<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Stampa\Prove;

use PHPUnit\Framework\TestCase;
use Pfi\Contratti\Eccezione;
use Pfi\Esportazione\Esportatore;
use Pfi\Modelli\Archivi\Memoria;
use Pfi\Modelli\Modello;
use Pfi\Modelli\Modelli;
use Pfi\Modelli\Schema;
use Pfi\Stampa\Chromium;
use Pfi\Stampa\Documento;
use Pfi\Stampa\Pagina;
use Pfi\Stampa\Qr;
use Pfi\Stampa\Salute\Motore;
use Pfi\Stampa\Stampa;

final class StampaTest extends TestCase {

    /** Programma per le prove: PFI_CHROMIUM, la shell senza interfaccia di Playwright, o quello di sistema. */
    private static function programma(): ?string {
        $p = getenv('PFI_CHROMIUM') ?: (glob('/opt/pw-browsers/chromium_headless_shell-*/*/chrome-headless-shell')[0] ?? null)
            ?? Chromium::cerca();
        return $p && is_executable($p) ? $p : null;
    }

    private static function chromium(): Chromium {
        $p = self::programma();
        if ($p === null) {
            self::markTestSkipped('Chromium non disponibile');
        }
        return new Chromium($p, 60, posix_geteuid() === 0);
    }

    private static function testo_pdf(string $pdf): string {
        $f = tempnam(sys_get_temp_dir(), 'pfipdf');
        file_put_contents($f, $pdf);
        $t = (string) shell_exec('pdftotext -layout ' . escapeshellarg($f) . ' - 2>/dev/null');
        unlink($f);
        return $t;
    }

    private function modelli(string $corpo): Modelli {
        return (new Modelli(new Memoria()))->dichiara((new Schema('tool_pfiaula/foglio_firme'))->segnaposto('titolo', 'Titolo', 'Lezione')
            ->segnaposto('persone', 'Persone', null, true)->segnaposto('persone.nome', 'Nome', 'Maria')->segnaposto('qr', 'QR', ''),
            new Modello('tool_pfiaula/foglio_firme', 'stampa', 'it', ['titolo' => 'Foglio firme: {{titolo}}', 'html' => $corpo,
                'intestazione' => 'Portale di formazione - {{titolo}}']));
    }

    public function test_pagina_e_documento(): void {
        $p = new Pagina('A4', true, [10, 12, 14, 16], 'Ente "di prova"', 'Pagina {pagina} di {pagine}');
        $this->assertSame('@page{size:A4 landscape;margin:10mm 12mm 14mm 16mm;@top-center{content:"Ente \\"di prova\\"";font-size:8pt;'
            . 'color:#555;font-family:"DejaVu Sans",Arial,sans-serif}@bottom-center{content:"Pagina " counter(page) " di " counter(pages);font-size:8pt;color:#555;font-family:"DejaVu Sans",Arial,sans-serif}}', $p->css());
        $h = Documento::html('<p>x</p>', new Pagina(), 'Titolo <b>', 'it', '</style><script>');
        $this->assertStringContainsString('content="' . Documento::CSP . '"', $h);
        $this->assertStringContainsString('<title>Titolo &lt;b&gt;</title>', $h);
        $this->assertStringNotContainsString('</style><script>', $h);
        $this->expectException(Eccezione::class);
        new Pagina('B5');
    }

    public function test_pdf_con_modello_intestazione_e_pagine(): void {
        $corpo = '<h1>{{titolo}}</h1><table><thead><tr><th>Nome</th><th>Firma</th></tr></thead><tbody>'
            . '{{#persone}}<tr><td>{{nome}}</td><td style="height:12mm"></td></tr>{{/persone}}</tbody></table>';
        $s = new Stampa($this->modelli($corpo), self::chromium());
        $persone = array_map(fn($i) => ['nome' => "Persona numero {$i} è"], range(1, 40));
        $pdf = $s->pdf('tool_pfiaula/foglio_firme', ['titolo' => 'Contratti <pubblici>', 'persone' => $persone]);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $t = self::testo_pdf($pdf);
        $this->assertStringContainsString('Contratti <pubblici>', $t);
        $this->assertStringContainsString('Persona numero 40 è', $t);
        $this->assertStringContainsString('Portale di formazione - Contratti <pubblici>', $t);
        $this->assertMatchesRegularExpression('/Pagina 2 di [2-9]/', $t);
        $this->assertGreaterThan(1, substr_count($t, 'Nome'), 'intestazione della tabella ripetuta');
    }

    public function test_risorse_esterne_bloccate(): void {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $porta = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        stream_set_blocking($s, false);
        $corpo = '<p>testo</p><img src="http://127.0.0.1:' . $porta . '/spia.png" alt="spia"><link rel="stylesheet" href="http://127.0.0.1:'
            . $porta . '/x.css"><script>document.body.append("ESEGUITO")</script>';
        $pdf = (new Stampa($this->modelli($corpo), self::chromium()))->pdf('tool_pfiaula/foglio_firme', ['titolo' => 'x']);
        $this->assertStringContainsString('testo', self::testo_pdf($pdf));
        $this->assertStringNotContainsString('ESEGUITO', self::testo_pdf($pdf));
        $this->assertFalse(@stream_socket_accept($s, 0), 'nessuna richiesta verso l\'esterno');
        fclose($s);
    }

    public function test_qr_ed_esportazione_pdf(): void {
        $tcpdf = getenv('PFI_TCPDF') ?: '/home/claude/moodle-locale/moodle/public/lib/tcpdf/tcpdf_barcodes_2d.php';
        if (!class_exists('TCPDF2DBarcode') && is_file($tcpdf)) {
            require_once $tcpdf;
        }
        try {
            $svg = Qr::svg('https://formazione.example.org/verifica?codice=ABC123');
        } catch (Eccezione) {
            $this->markTestSkipped('nessun generatore di codici QR');
        }
        $this->assertStringStartsWith('<svg', $svg);
        $c = self::chromium();
        $pdf = (new Stampa($this->modelli('<p>Codice</p>{{{qr}}}'), $c))->pdf('tool_pfiaula/foglio_firme',
            ['titolo' => 'QR', 'qr' => Qr::img('https://formazione.example.org/verifica?codice=ABC123', 'codice di verifica')]);
        $f = tempnam(sys_get_temp_dir(), 'pfiqr');
        file_put_contents($f . '.pdf', $pdf);
        exec('pdftoppm -r 150 -png -f 1 -l 1 ' . escapeshellarg($f . '.pdf') . ' ' . escapeshellarg($f) . ' 2>/dev/null');
        $png = glob($f . '*.png')[0] ?? '';
        $letto = trim((string) shell_exec('python3 -I -c ' . escapeshellarg('import cv2,sys; print(cv2.QRCodeDetector().detectAndDecode(cv2.imread(sys.argv[1]))[0])')
            . ' ' . escapeshellarg($png) . ' 2>/dev/null'));
        array_map('unlink', glob($f . '*'));
        if ($letto === '' && !shell_exec('python3 -c "import cv2" 2>&1 && echo ok')) {
            $this->markTestSkipped('opencv non disponibile');
        }
        $this->assertSame('https://formazione.example.org/verifica?codice=ABC123', $letto);
        $out = fopen('php://memory', 'w+');
        (new Esportatore($c))->esporta([['Anna', 3]], ['Nome', 'Ore'], 'pdf', $out, 'Presenze');
        rewind($out);
        $t = self::testo_pdf((string) stream_get_contents($out));
        $this->assertStringContainsString('Presenze', $t);
        $this->assertStringContainsString('Anna', $t);
        $this->assertSame('buono', (new Motore($c, true))->esegui()->stato);
    }
}
