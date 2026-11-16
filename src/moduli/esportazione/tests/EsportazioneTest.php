<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esportazione\Prove;

use PHPUnit\Framework\TestCase;
use Pfi\Contratti\Eccezione;
use Pfi\Esportazione\Esportatore;
use Pfi\Esportazione\Formati\Delimitato;
use Pfi\Esportazione\Formati\LarghezzaFissa;
use Pfi\Esportazione\Impaginatore;

final class EsportazioneTest extends TestCase {

    private const COLONNE = ['Nome', 'Importo €', 'Note', 'Data'];

    private static function righe(): array {
        return [
            ['Anna Rossì', 12.5, "riga 1\nriga 2, con virgola", new \DateTimeImmutable('2026-11-14 10:00:00+01:00')],
            ['=SOMMA(A1:A9)', -3, 'virgolette "doppie" | barra & <tag>', null],
            ['Luca', 1000000, true, '2026-12-01'],
        ];
    }

    private function esporta(string $formato, string $titolo = 'Elenco di prova', ?Esportatore $e = null): string {
        $out = fopen('php://memory', 'w+');
        $n = ($e ?? new Esportatore())->esporta(self::righe(), self::COLONNE, $formato, $out, $titolo);
        $this->assertSame(3, $n);
        rewind($out);
        return (string) stream_get_contents($out);
    }

    private function python(string $tipo, string $dati): array {
        exec('python3 -c "import openpyxl, docx, odf" 2>/dev/null', $o, $c);
        if ($c !== 0) {
            $this->markTestSkipped('openpyxl, python-docx o odfpy non disponibili');
        }
        $f = tempnam(sys_get_temp_dir(), 'pfit') . '.' . $tipo;
        file_put_contents($f, $dati);
        $j = shell_exec('python3 -I ' . escapeshellarg(__DIR__ . '/controlla.py') . ' ' . $tipo . ' ' . escapeshellarg($f) . ' 2>&1');
        unlink($f);
        $r = json_decode((string) $j, true);
        $this->assertIsArray($r, (string) $j);
        return $r;
    }

    public function test_csv_tsv_e_codifiche(): void {
        $csv = $this->esporta('csv');
        $this->assertStringStartsWith("\xEF\xBB\xBFNome,Importo €,Note,Data\r\n", $csv);
        $righe = array_map('str_getcsv', preg_split('/\r\n(?=(?:[^"]*"[^"]*")*[^"]*$)/', trim(substr($csv, 3))));
        $this->assertSame(['Anna Rossì', '12.5', "riga 1\nriga 2, con virgola", '2026-11-14T10:00:00+01:00'], $righe[1]);
        $this->assertSame(["'=SOMMA(A1:A9)", '-3', 'virgolette "doppie" | barra & <tag>', ''], $righe[2]);
        $this->assertSame(['Luca', '1000000', '1', '2026-12-01'], $righe[3]);
        $e = (new Esportatore())->formato_con_nome('csv_win', fn() => new Delimitato(';', '"', 'Windows-1252', false));
        $w = $this->esporta('csv_win', '', $e);
        $this->assertStringContainsString("Importo \x80", $w);
        $this->assertStringContainsString("Anna Ross\xEC", $w);
        $tsv = $this->esporta('tsv');
        $this->assertSame("Nome\tImporto €\tNote\tData", explode("\n", $tsv)[0]);
        $this->assertStringContainsString("riga 1 riga 2, con virgola", $tsv);
        $this->expectException(Eccezione::class);
        new Delimitato(',', ',');
    }

    public function test_larghezza_fissa(): void {
        $e = (new Esportatore())->formato_con_nome('fisso', fn() => new LarghezzaFissa([8, 10, 12, 10]));
        $r = explode("\r\n", $this->esporta('fisso', '', $e));
        $this->assertSame('Nome    Importo € Note        Data      ', $r[0]);
        $this->assertSame('Anna Ros      12.5riga 1 riga 2026-11-14', $r[1]);
        $this->assertSame(40, mb_strlen($r[2]));
    }

    public function test_json_xml_markdown_html_rtf(): void {
        $j = json_decode($this->esporta('json'), true);
        $this->assertSame(['Nome' => 'Luca', 'Importo €' => 1000000, 'Note' => true, 'Data' => '2026-12-01'], $j[2]);
        $this->assertSame(12.5, $j[0]['Importo €']);
        $x = new \DOMDocument();
        $this->assertTrue($x->loadXML($this->esporta('xml')));
        $this->assertSame('Elenco di prova', $x->documentElement->getAttribute('titolo'));
        $this->assertSame('virgolette "doppie" | barra & <tag>', $x->getElementsByTagName('Note')->item(1)->textContent);
        $this->assertSame(3, $x->getElementsByTagName('Importo_EUR')->length);
        $this->assertSame('c_1_citta', \Pfi\Esportazione\Formati\Xml::nome_elemento('1 città'));
        $md = $this->esporta('md');
        $this->assertStringContainsString('| Anna Rossì | 12.5 | riga 1<br>riga 2, con virgola |', $md);
        $this->assertStringContainsString('virgolette "doppie" \\| barra & <tag>', $md);
        $html = $this->esporta('html');
        $d = new \DOMDocument();
        $this->assertTrue(@$d->loadHTML($html));
        $this->assertSame(4, $d->getElementsByTagName('tr')->length);
        $this->assertStringContainsString('&lt;tag&gt;', $html);
        $this->assertStringContainsString('<td class="n">1000000</td>', $html);
        $rtf = $this->esporta('rtf');
        $this->assertStringStartsWith('{\\rtf1', $rtf);
        $this->assertStringContainsString('Anna Ross\\u236?', $rtf);
        $this->assertSame(substr_count($rtf, '{'), substr_count($rtf, '}') - substr_count($rtf, '\\}') + substr_count($rtf, '\\{'));
    }

    public function test_xlsx_ods_docx_odt_letti_da_altri_programmi(): void {
        $x = $this->python('xlsx', $this->esporta('xlsx'));
        $this->assertSame(self::COLONNE, $x[0]);
        $this->assertSame(['Anna Rossì', 12.5, "riga 1\nriga 2, con virgola", '2026-11-14T10:00:00+01:00'], $x[1]);
        $this->assertSame([-3, 1000000], [$x[2][1], $x[3][1]]);
        $this->assertSame(['__grassetto__', true, 'A2', 'Elenco di prova'], end($x));
        $o = $this->python('ods', $this->esporta('ods'));
        $this->assertSame(self::COLONNE, $o[0]);
        $this->assertSame("riga 1\nriga 2, con virgola", $o[1][2]);
        $w = $this->python('docx', $this->esporta('docx'));
        $this->assertSame(self::COLONNE, $w[0]);
        $this->assertSame('virgolette "doppie" | barra & <tag>', $w[2][2]);
        $this->assertSame(['__titolo__', 'Elenco di prova'], end($w));
        $t = $this->python('odt', $this->esporta('odt'));
        $this->assertSame(['Luca', '1000000', '1', '2026-12-01'], $t[3]);
        $zip = new \ZipArchive();
        $f = tempnam(sys_get_temp_dir(), 'pfiz');
        file_put_contents($f, $this->esporta('odt'));
        $zip->open($f);
        $this->assertSame(['mimetype', \ZipArchive::CM_STORE], [$zip->statIndex(0)['name'], $zip->statIndex(0)['comp_method']]);
        $zip->close();
        unlink($f);
    }

    public function test_pdf_con_impaginatore_e_file_grande(): void {
        $imp = new class implements Impaginatore {
            public string $html = '';
            public function pdf(string $html): string {
                $this->html = $html;
                return "%PDF-1.7 finto";
            }
        };
        $e = new Esportatore($imp);
        $this->assertContains('pdf', $e->nomi());
        $this->assertSame('%PDF-1.7 finto', $this->esporta('pdf', 'Elenco', $e));
        $this->assertStringContainsString('<caption>Elenco</caption>', $imp->html);
        $this->assertNotContains('pdf', (new Esportatore())->nomi());
        $genera = function() {
            for ($i = 0; $i < 50000; $i++) {
                yield ['Persona ' . $i, $i * 1.5, str_repeat('x', 40), '2026-11-14'];
            }
        };
        $f = sys_get_temp_dir() . '/pfi-grande-' . bin2hex(random_bytes(3)) . '.xlsx';
        $prima = memory_get_peak_usage();
        $this->assertSame(50000, (new Esportatore())->in_file($genera(), self::COLONNE, 'xlsx', $f));
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $prima);
        $this->assertGreaterThan(100000, filesize($f));
        unlink($f);
        try {
            (new Esportatore())->esporta([['solo uno']], self::COLONNE, 'csv', fopen('php://memory', 'w'));
            $this->fail();
        } catch (Eccezione $x) {
            $this->assertStringContainsString('1 valori per 4 colonne', $x->getMessage());
        }
    }
}
