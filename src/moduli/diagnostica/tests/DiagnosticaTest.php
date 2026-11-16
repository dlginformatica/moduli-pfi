<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Diagnostica\Prove;

use PHPUnit\Framework\TestCase;
use Pfi\Contratti\EsitoSalute;
use Pfi\Contratti\Manifesto;
use Pfi\Contratti\ProvaDiSalute;
use Pfi\Contratti\Registro;
use Pfi\Diagnostica\Diagnostica;

final class DiagnosticaTest extends TestCase {

    private function prova(string $codice, callable $fn): ProvaDiSalute {
        return new class($codice, $fn) implements ProvaDiSalute {
            public function __construct(private string $c, private $f) {
            }
            public function codice(): string {
                return $this->c;
            }
            public function descrizione(): string {
                return 'Prova ' . $this->c;
            }
            public function esegui(): EsitoSalute {
                return ($this->f)();
            }
        };
    }

    public function test_stato_complessivo_ed_eccezioni(): void {
        $righe = [];
        $registro = new class($righe) implements Registro {
            public function __construct(private array &$r) {
            }
            public function registra(string $livello, string $messaggio, array $contesto = []): void {
                $this->r[] = "$livello " . $contesto['codice'];
            }
        };
        $d = (new Diagnostica($registro))
            ->aggiungi('pfi/a', $this->prova('a.buona', fn() => EsitoSalute::buono('ok')))
            ->aggiungi('pfi/a', $this->prova('a.avviso', fn() => EsitoSalute::avviso('attenzione')));
        $r = $d->esegui();
        $this->assertSame('avviso', Diagnostica::stato($r));
        $d->aggiungi('pfi/b', $this->prova('b.eccezione', function() {
            throw new \RuntimeException('password=segreta');
        }));
        $r = $d->esegui();
        $this->assertSame('guasto', Diagnostica::stato($r));
        $this->assertStringNotContainsString('segreta', $r[2]->esito->messaggio);
        $this->assertContains('error b.eccezione', $righe);
        $json = json_decode(Diagnostica::json($r, new \DateTimeImmutable('2026-11-14T12:00:00+01:00')), true);
        $this->assertSame('guasto', $json['stato']);
        $this->assertCount(3, $json['prove']);
        $this->assertArrayNotHasKey('dettagli', $json['prove'][0]);
        $this->assertStringContainsString('| pfi/a | Prova a.buona | buono | ok |', Diagnostica::markdown($r));
    }

    public function test_prove_dai_manifesti(): void {
        $m = Manifesto::da_json(json_encode(['nome' => 'pfi/segreti', 'versione' => '1.0.0', 'descrizione' => 'x',
            'spazio' => 'Pfi\\Segreti\\', 'salute' => ['Pfi\\Segreti\\Salute\\Chiave', 'Pfi\\Inesistente\\Prova']]));
        $r = (new Diagnostica())->da_manifesti([$m])->esegui();
        $this->assertSame(['avviso', 'guasto'], array_map(fn($x) => $x->esito->stato, $r));
        $this->assertSame('diagnostica.caricamento', $r[1]->codice);
        $sostituita = (new Diagnostica())->da_manifesti([$m], ['Pfi\\Segreti\\Salute\\Chiave' =>
            $this->prova('segreti.deposito', fn() => EsitoSalute::buono('ok'))])->esegui();
        $this->assertSame('buono', $sostituita[0]->esito->stato);
    }

    public function test_prova_lenta(): void {
        $r = (new Diagnostica(null, 1.0))->aggiungi('pfi/a', $this->prova('lenta', function() {
            usleep(5000);
            return EsitoSalute::buono('ok');
        }))->esegui();
        $this->assertSame('avviso', $r[0]->esito->stato);
    }
}
