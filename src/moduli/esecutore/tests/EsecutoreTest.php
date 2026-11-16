<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esecutore\Prove;

use PHPUnit\Framework\TestCase;
use Pfi\Contratti\Eccezione;
use Pfi\Esecutore\Catalogo;
use Pfi\Esecutore\Coda;
use Pfi\Esecutore\Esecutore;
use Pfi\Esecutore\Operazione;
use Pfi\Esecutore\Salute\Coda as SaluteCoda;
use Pfi\Registro\Destinazioni\Memoria;
use Pfi\Registro\Registro;

final class EsecutoreTest extends TestCase {

    private string $base;

    protected function setUp(): void {
        $this->base = sys_get_temp_dir() . '/pfi-esecutore-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/backup/20261114-020000', 0700, true);
        mkdir($this->base . '/altrove', 0700, true);
    }

    protected function tearDown(): void {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    private function catalogo(): Catalogo {
        return Catalogo::da_json(json_encode(['operazioni' => [
            'eco' => ['descrizione' => 'Prova', 'comando' => ['/bin/echo', 'fisso', '{testo}', '{?forte:--forte}', '{numero}'],
                'parametri' => ['testo' => ['tipo' => 'testo', 'modello' => '^[a-z ;$`|-]+$'], 'forte' => ['tipo' => 'booleano'],
                    'numero' => ['tipo' => 'intero', 'min' => 1, 'max' => 9, 'obbligatorio' => false]]],
            'ripristino' => ['comando' => ['/bin/ls', '{cartella}'], 'parametri' => ['cartella' => ['tipo' => 'percorso',
                'radice' => $this->base . '/backup']]],
            'fallisce' => ['comando' => ['/bin/sh', '-c', 'echo errore >&2; exit 3']],
            'lenta' => ['comando' => ['/bin/sleep', '5'], 'tempomassimo' => 1],
            'ambiente' => ['comando' => ['/usr/bin/env']],
        ]]));
    }

    public function test_operazione_parametri_e_argomenti(): void {
        $op = $this->catalogo()->operazione('eco');
        $v = $op->controlla(['testo' => 'a; rm -rf $x`|', 'forte' => true]);
        $this->assertSame(['/bin/echo', 'fisso', 'a; rm -rf $x`|', '--forte'], $op->argomenti($v));
        $this->assertSame(['/bin/echo', 'fisso', 'b', '3'], $op->argomenti($op->controlla(['testo' => 'b', 'numero' => 3])));
        foreach ([['testo' => 'B'], ['testo' => 'b', 'numero' => 10], ['testo' => 'b', 'altro' => 1], [],
            ['testo' => 'b', 'forte' => 'sì'], ['testo' => ['x']], ['testo' => "b\nc"]] as $i => $errati) {
            try {
                $op->controlla($errati);
                $this->fail("caso {$i} accettato");
            } catch (Eccezione $e) {
                $this->assertStringNotContainsString('rm -rf', $e->getMessage());
            }
        }
        $r = $this->catalogo()->operazione('ripristino');
        $this->assertSame(['/bin/ls', realpath($this->base . '/backup/20261114-020000')],
            $r->argomenti($r->controlla(['cartella' => $this->base . '/backup/20261114-020000'])));
        symlink($this->base . '/altrove', $this->base . '/backup/collegamento');
        foreach ([$this->base . '/altrove', $this->base . '/backup/../altrove', $this->base . '/backup/collegamento',
            $this->base . '/backup', '/etc'] as $fuori) {
            try {
                $r->controlla(['cartella' => $fuori]);
                $this->fail("percorso accettato: {$fuori}");
            } catch (Eccezione) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(Eccezione::class);
        new Operazione('x', 'x', ['/bin/echo', '{non_dichiarato}']);
    }

    public function test_coda_ed_esecuzione(): void {
        $coda = new Coda($this->base . '/coda');
        $coda->prepara();
        $mem = new Memoria();
        $es = new Esecutore($coda, $this->catalogo(), (new Registro())->aggiungi($mem));
        $a = $coda->richiedi('eco', ['testo' => 'ciao', 'forte' => true], 'admin', 'c0rr');
        $b = $coda->richiedi('fallisce', [], 'admin');
        $c = $coda->richiedi('inesistente', [], 'admin');
        $d = $coda->richiedi('ambiente', [], 'admin', 'zz');
        $this->assertSame('in coda', $coda->esito($a)['stato']);
        $this->assertSame(4, $es->elabora());
        $ea = $coda->esito($a);
        $this->assertSame(['eseguita', 0, "fisso ciao --forte\n"], [$ea['stato'], $ea['codice'], $ea['uscita']]);
        $eb = $coda->esito($b);
        $this->assertSame(['non riuscita', 3, "errore\n"], [$eb['stato'], $eb['codice'], $eb['uscita']]);
        $this->assertSame('rifiutata', $coda->esito($c)['stato']);
        $env = $coda->esito($d)['uscita'];
        $this->assertStringContainsString('PFI_CORRELAZIONE=zz', $env);
        $this->assertStringNotContainsString('HOME=', $env);
        $this->assertSame('sconosciuta', $coda->esito('../../etc/passwd')['stato']);
        $this->assertSame([], glob($coda->cartella('in-corso') . '/*'));
        $correlazioni = array_map(fn($v) => $v->correlazione, $mem->voci);
        $this->assertContains('c0rr', $correlazioni);
        $this->assertSame(0, $es->elabora());
    }

    public function test_tempo_massimo_scadenza_e_richieste_manomesse(): void {
        $coda = new Coda($this->base . '/coda');
        $coda->prepara();
        $es = new Esecutore($coda, $this->catalogo(), null, 60);
        $lenta = $coda->richiedi('lenta', [], 'admin');
        $vecchia = $coda->richiedi('eco', ['testo' => 'x'], 'admin');
        $f = $coda->cartella('richieste') . "/{$vecchia}.json";
        $j = json_decode(file_get_contents($f), true);
        $j['creata'] = time() - 3600;
        file_put_contents($f, json_encode($j));
        $falsa = str_repeat('a', 32);
        file_put_contents($coda->cartella('richieste') . "/{$falsa}.json", json_encode(['id' => 'diverso', 'operazione' => 'eco']));
        $collegata = str_repeat('b', 32);
        symlink('/etc/hostname', $coda->cartella('richieste') . "/{$collegata}.json");
        file_put_contents($coda->cartella('richieste') . '/nome-strano.json', '{}');
        $inizio = microtime(true);
        $es->elabora();
        $this->assertLessThan(4, microtime(true) - $inizio);
        $el = $coda->esito($lenta);
        $this->assertSame(['interrotta', 124], [$el['stato'], $el['codice']]);
        $this->assertSame('Richiesta scaduta.', $coda->esito($vecchia)['motivo']);
        $this->assertSame('rifiutata', $coda->esito($falsa)['stato']);
        $this->assertSame('rifiutata', $coda->esito($collegata)['stato']);
        $this->assertFileExists('/etc/hostname');
        file_put_contents($coda->cartella('richieste') . '/.abandonata.tmp', '{}');
        touch($coda->cartella('richieste') . '/.abandonata.tmp', time() - 7200);
        file_put_contents($coda->cartella('richieste') . '/.recente.tmp', '{}');
        $es->elabora();
        $this->assertSame(['.recente.tmp'], array_values(array_diff(scandir($coda->cartella('richieste')), ['.', '..'])),
            'la cartella delle richieste si svuota dei file non validi (unità .path di systemd)');
        unlink($coda->cartella('richieste') . '/.recente.tmp');
        file_put_contents($coda->cartella('in-corso') . '/' . str_repeat('c', 32) . '.json', '{}');
        $es->elabora();
        $this->assertSame('interrotta', $coda->esito(str_repeat('c', 32))['stato']);
        touch($coda->cartella('esiti') . "/{$lenta}.json", time() - 40 * 86400);
        foreach (glob($coda->cartella('in-corso') . '/.scartata-*') as $sc) {
            touch($sc, time() - 40 * 86400);
        }
        $this->assertSame(1 + 2, $es->pulisci(30), 'esito vecchio e due file scartati');
    }

    public function test_salute_e_catalogo_da_file(): void {
        $coda = new Coda($this->base . '/coda');
        $this->assertSame('guasto', (new SaluteCoda($coda))->esegui()->stato);
        $coda->prepara();
        $this->assertSame('buono', (new SaluteCoda($coda))->esegui()->stato);
        $id = $coda->richiedi('eco', ['testo' => 'x'], 'admin');
        touch($coda->cartella('richieste') . "/{$id}.json", time() - 600);
        $this->assertSame('guasto', (new SaluteCoda($coda))->esegui()->stato);
        $f = $this->base . '/catalogo.json';
        file_put_contents($f, '{"operazioni":{}}');
        chmod($f, 0666);
        $this->assertCount(0, Catalogo::da_file($f, false)->operazioni());
        $this->expectException(Eccezione::class);
        Catalogo::da_file($f);
    }
}
