<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Gestore\Prove;

use PHPUnit\Framework\TestCase;
use Pfi\Contratti\Eccezione;
use Pfi\Gestore\Gestore;
use Pfi\Gestore\Pacchetto;
use Pfi\Gestore\Salute\Dipendenze;

final class GestoreTest extends TestCase {

    private string $t;
    private string $pub;
    private string $priv;

    protected function setUp(): void {
        $this->t = sys_get_temp_dir() . '/pfi-gestore-' . bin2hex(random_bytes(4));
        mkdir("{$this->t}/moduli", 0755, true);
        mkdir("{$this->t}/pacchetti");
        mkdir("{$this->t}/sorgenti");
        [$this->pub, $this->priv] = Pacchetto::genera_chiavi();
    }

    protected function tearDown(): void {
        exec('rm -rf ' . escapeshellarg($this->t));
    }

    /** Modulo di prova con una prova di salute che dà lo stato indicato. */
    private function modulo(string $nome, string $versione, string $stato = 'buono', array $richiede = ['pfi/contratti' => '^1.0']): string {
        $classe = ucfirst($nome);
        $d = "{$this->t}/sorgenti/{$nome}-{$versione}";
        mkdir("{$d}/src", 0755, true);
        file_put_contents("{$d}/modulo.json", json_encode(['nome' => "pfi/{$nome}", 'versione' => $versione, 'descrizione' => 'Prova',
            'spazio' => "Pfi\\{$classe}\\", 'richiede' => $richiede, 'salute' => ["Pfi\\{$classe}\\Salute"]]));
        file_put_contents("{$d}/src/Salute.php", "<?php\nnamespace Pfi\\{$classe};\nuse Pfi\\Contratti\\{EsitoSalute, ProvaDiSalute};\n"
            . "final class Salute implements ProvaDiSalute {\n public function codice(): string { return '{$nome}.prova'; }\n"
            . " public function descrizione(): string { return 'Prova'; }\n public function esegui(): EsitoSalute { return EsitoSalute::{$stato}('versione {$versione}'); }\n}\n");
        return Pacchetto::crea($d, "{$this->t}/pacchetti", $this->priv);
    }

    private function gestore(): Gestore {
        return new Gestore("{$this->t}/moduli", $this->pub);
    }

    public function test_installazione_aggiornamento_ritorno_e_salute(): void {
        $g = $this->gestore();
        $radice = dirname(__DIR__, 2);
        $g->installa(Pacchetto::crea("{$radice}/contratti", "{$this->t}/pacchetti", $this->priv), 'admin');
        $g->installa(Pacchetto::crea("{$radice}/diagnostica", "{$this->t}/pacchetti", $this->priv), 'admin');
        $this->assertSame('1.0.0', $g->attuale('pfi/contratti'));
        $g->installa($this->modulo('prova', '1.0.0'), 'admin');
        $g->installa($this->modulo('dipende', '1.0.0', 'buono', ['pfi/contratti' => '^1.0', 'pfi/prova' => '^1.0']), 'admin');
        $g->installa($this->modulo('prova', '1.1.0'), 'admin');
        $this->assertSame(['1.1.0', '1.0.0'], $g->versioni('pfi/prova'));
        $this->assertSame('1.1.0', $g->attuale('pfi/prova'));
        try {
            $g->installa($this->modulo('prova', '1.2.0', 'guasto'), 'admin');
            $this->fail('versione guasta in uso');
        } catch (Eccezione $e) {
            $this->assertStringContainsString('rimessa in uso la versione 1.1.0', $e->getMessage());
            $this->assertStringContainsString('versione 1.2.0', $e->getMessage());
        }
        $this->assertSame('1.1.0', $g->attuale('pfi/prova'));
        try {
            $g->installa($this->modulo('prova', '2.0.0'), 'admin');
            $this->fail('dipendenza violata');
        } catch (Eccezione $e) {
            $this->assertStringContainsString('pfi/dipende 1.0.0 richiede pfi/prova «^1.0»', $e->getMessage());
        }
        $this->assertSame('1.0.0', $g->indietro('pfi/prova', 'admin'));
        $this->assertSame('1.1.0', $g->indietro('pfi/prova', 'admin', '1.1.0'));
        $e = $g->elenco();
        $this->assertSame(['pfi/contratti', 'pfi/diagnostica', 'pfi/dipende', 'pfi/prova'], array_keys($e));
        $this->assertSame([], $e['pfi/dipende']['mancanti']);
        $this->assertSame(['pfi/prova' => ['1.1.0', '1.0.0']], $g->scostamenti(['pfi/contratti' => '1.0.0', 'pfi/diagnostica' => '1.0.0',
            'pfi/dipende' => '1.0.0', 'pfi/prova' => '1.0.0']));
        $g->rimuovi('pfi/prova', '1.2.0');
        $this->assertSame(['1.1.0', '1.0.0'], $g->versioni('pfi/prova'));
        $azioni = array_column($g->leggi_storia(), 'azione');
        $this->assertContains('annullato', $azioni);
        $this->assertSame('buono', (new Dipendenze($g))->esegui()->stato);
        $this->expectException(Eccezione::class);
        $g->rimuovi('pfi/prova', '1.1.0');
    }

    public function test_pacchetti_non_validi(): void {
        $g = $this->gestore();
        $a = $this->modulo('prova', '1.0.0', 'buono', []);
        $copia = "{$this->t}/alterato.tar.gz";
        copy($a, $copia);
        copy("{$a}.firma", "{$copia}.firma");
        file_put_contents($copia, 'x', FILE_APPEND);
        [$altra] = Pacchetto::genera_chiavi();
        foreach ([[$copia, $this->pub], [$a, $altra]] as [$file, $chiave]) {
            try {
                (new Gestore("{$this->t}/moduli", $chiave))->installa($file, 'admin');
                $this->fail('pacchetto accettato');
            } catch (Eccezione $e) {
                $this->assertStringContainsString('Firma del pacchetto non valida', $e->getMessage());
            }
        }
        $d = "{$this->t}/sorgenti/prova-1.0.0";
        symlink('/etc/passwd', "{$d}/collegamento");
        $l = Pacchetto::crea($d, "{$this->t}/pacchetti", $this->priv);
        try {
            $g->installa($l, 'admin');
            $this->fail('collegamento accettato');
        } catch (Eccezione $e) {
            $this->assertStringContainsString('collegamenti simbolici', $e->getMessage());
        }
        $this->assertSame([], glob("{$this->t}/moduli/.pacchetto-*"));
        $this->assertNull($g->attuale('pfi/prova'));
    }
}
