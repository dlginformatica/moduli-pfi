<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione\Prove;

use PHPUnit\Framework\TestCase;
use Pfi\Comunicazione\Canale;
use Pfi\Comunicazione\CodaInvii;
use Pfi\Comunicazione\Comunicazione;
use Pfi\Comunicazione\ErroreInvio;
use Pfi\Comunicazione\Indirizzo;
use Pfi\Comunicazione\Invio;
use Pfi\Comunicazione\Messaggio;
use Pfi\Comunicazione\Salute\Canali;
use Pfi\Contratti\EsitoSalute;
use Pfi\Modelli\Archivi\Memoria as ArchivioMemoria;
use Pfi\Modelli\Modello;
use Pfi\Modelli\Modelli;
use Pfi\Modelli\Schema;
use Pfi\Registro\Destinazioni\Memoria;
use Pfi\Registro\Registro;

final class CanaleProva implements Canale {

    /** @var list<Messaggio> */
    public array $inviati = [];

    /** @param list<ErroreInvio> $errori */
    public function __construct(private readonly string $t, public array $errori = []) {
    }

    public function tipo(): string {
        return $this->t;
    }

    public function nome(): string {
        return $this->t . ' prova';
    }

    public function invia(Messaggio $m): Invio {
        if ($this->errori) {
            throw array_shift($this->errori);
        }
        $this->inviati[] = $m;
        return new Invio('id-' . count($this->inviati), 'ok');
    }

    public function salute(): EsitoSalute {
        return EsitoSalute::buono('ok');
    }
}

final class ComunicazioneTest extends TestCase {

    private CodaInvii $coda;
    private Memoria $voci;

    private function comunicazione(Canale ...$canali): Comunicazione {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->coda = new CodaInvii($pdo);
        $this->coda->crea_tabella();
        $schema = (new Schema('tool_pfiaula/promemoria'))->segnaposto('nome', 'Nome', 'Maria')->segnaposto('data', 'Data', '20/11');
        $modelli = (new Modelli(new ArchivioMemoria()))->dichiara($schema,
            new Modello('tool_pfiaula/promemoria', 'posta', 'it', ['oggetto' => 'Lezione del {{data}}', 'html' => '<p>Gentile {{nome}}</p>']),
            new Modello('tool_pfiaula/promemoria', 'whatsapp', 'it', ['nome' => 'promemoria_lezione', 'parametri' => "{{nome}}\n{{data}}\n"]));
        $this->voci = new Memoria();
        $c = new Comunicazione($modelli, $this->coda, (new Registro())->aggiungi($this->voci), Indirizzo::posta('portale@example.org'), 'sale');
        foreach ($canali as $k) {
            $c->canale($k);
        }
        return $c;
    }

    public function test_invio_con_modello_e_coda(): void {
        $posta = new CanaleProva('posta');
        $c = $this->comunicazione($posta, new CanaleProva('whatsapp'));
        $dest = [Indirizzo::posta('anna@example.org'), Indirizzo::posta('luca@example.org')];
        $ids = $c->invia('posta', 'tool_pfiaula/promemoria', $dest, ['data' => '20/11'], ['correlazione' => 'k1',
            'dati_per' => fn(Indirizzo $i) => ['nome' => strstr($i->valore, '@', true)]]);
        $this->assertCount(2, $ids);
        $this->assertSame('in_coda', $this->coda->riga($ids[0])['stato']);
        $this->assertSame(2, $c->elabora());
        $this->assertSame(['<p>Gentile anna</p>', '<p>Gentile luca</p>'], array_map(fn($m) => $m->html, $posta->inviati));
        $this->assertSame('portale@example.org', $posta->inviati[0]->mittente->valore);
        $this->assertSame('k1', $posta->inviati[0]->intestazioni['X-Pfi-Correlazione']);
        $r = $this->coda->riga($ids[0]);
        $this->assertSame(['inviato', 1, 'id-1', null, null], [$r['stato'], (int) $r['tentativi'], $r['idfornitore'], $r['contenuto'], $r['inchiaro']]);
        $this->assertSame(Indirizzo::posta('anna@example.org')->pseudonimo('sale'), $r['destinatario']);
        $this->assertSame(0, $c->elabora());
        foreach ($this->voci->voci as $v) {
            $this->assertStringNotContainsString('anna@', $v->json());
        }
        $this->assertCount(2, $this->coda->cerca(['correlazione' => 'k1']));
        [$e] = $c->invia('posta', 'tool_pfiaula/promemoria', [$dest[0]], ['nome' => 'B'], ['etichetta' => 'tool_pfiaula/invito (composto)']);
        $this->assertSame('tool_pfiaula/invito (composto)', $this->coda->riga($e)['modello']);
    }

    public function test_tentativi_e_errori_definitivi(): void {
        $posta = new CanaleProva('posta', [new ErroreInvio('rete', true), new ErroreInvio('casella inesistente', false)]);
        $c = $this->comunicazione($posta);
        [$a] = $c->invia('posta', 'tool_pfiaula/promemoria', [Indirizzo::posta('anna@example.org')], ['nome' => 'A'], ['subito' => true]);
        $r = $this->coda->riga($a);
        $this->assertSame(['in_coda', 1, 'rete'], [$r['stato'], (int) $r['tentativi'], $r['esito']]);
        $this->assertGreaterThan(time() + 50, (int) $r['prossimo']);
        $this->assertNotNull($r['contenuto']);
        $this->assertSame(0, $c->elabora());
        $this->assertCount(1, $this->coda->prendi(10, time() + 61));
        $this->coda->esito($a, 'in_coda', 1, time(), '', '', false);
        $this->assertSame(1, $c->elabora());
        $r = $this->coda->riga($a);
        $this->assertSame(['fallito', 2, null], [$r['stato'], (int) $r['tentativi'], $r['contenuto']]);
        $this->assertSame('error', end($this->voci->voci)->livello);
        $this->assertSame(1, $this->coda->conserva(1, time() + 2 * 86400));
    }

    public function test_whatsapp_parametri_dal_modello_e_salute(): void {
        $wa = new CanaleProva('whatsapp');
        $c = $this->comunicazione($wa);
        $c->invia('whatsapp', 'tool_pfiaula/promemoria', [new Indirizzo('393331234567')], ['nome' => 'Anna', 'data' => '1/12'], ['subito' => true]);
        $this->assertSame(['modello' => 'promemoria_lezione', 'lingua' => 'it', 'parametri' => ['Anna', '1/12']], $wa->inviati[0]->extra);
        $this->assertSame('buono', (new Canali($c))->esegui()->stato);
        $this->assertSame('buono', (new Canali())->esegui()->stato);
        $this->expectException(\Pfi\Contratti\Eccezione::class);
        $c->invia('telegram', 'tool_pfiaula/promemoria', [new Indirizzo('1')], []);
    }
}
