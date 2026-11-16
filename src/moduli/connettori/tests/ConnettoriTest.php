<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Connettori\Prove;

use PHPUnit\Framework\TestCase;
use Pfi\Connettori\Connettore;
use Pfi\Connettori\ErroreConnettore;
use Pfi\Connettori\Limite;
use Pfi\Connettori\Politica;
use Pfi\Connettori\Richiesta;
use Pfi\Connettori\Risposta;
use Pfi\Connettori\Salute\Raggiungibili;
use Pfi\Connettori\Trasporti\Curl;
use Pfi\Connettori\Trasporti\Finto;
use Pfi\Registro\Destinazioni\Memoria;
use Pfi\Registro\Registro;

final class ConnettoriTest extends TestCase {

    private static $servizio = null;
    private static int $porta = 0;

    public static function tearDownAfterClass(): void {
        if (self::$servizio) {
            proc_terminate(self::$servizio);
            proc_close(self::$servizio);
        }
    }

    private static function avvia(): string {
        if (!self::$servizio) {
            $s = stream_socket_server('tcp://127.0.0.1:0');
            self::$porta = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
            fclose($s);
            self::$servizio = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$porta, __DIR__ . '/servizio.php'],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $tubi);
            for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', self::$porta); $i++) {
                usleep(100000);
            }
        }
        return 'http://127.0.0.1:' . self::$porta;
    }

    private function connettore(Finto $f, ?Politica $p = null, ?Registro $r = null, array &$attese = []): Connettore {
        return new Connettore('prova', $f, $p ?? new Politica(3, 0.5, 30), null, $r,
            function(float $s) use (&$attese) {
                $attese[] = $s;
            });
    }

    public function test_tentativi_e_attese(): void {
        $f = (new Finto())->accoda(new Risposta(503, ['retry-after' => '7']), new Risposta(502), Finto::json(200, ['ok' => 1]));
        $attese = [];
        $r = $this->connettore($f, null, null, $attese)->invia(new Richiesta('GET', 'https://api.example.org/x'));
        $this->assertSame([1, 3], [$r->json()['ok'], $r->tentativi]);
        $this->assertSame(7.0, $attese[0]);
        $this->assertGreaterThanOrEqual(0.5, $attese[1]);
        $this->assertLessThanOrEqual(1.0, $attese[1]);
    }

    public function test_post_non_idempotente_non_si_ritenta(): void {
        $f = (new Finto())->accoda(new Risposta(502), Finto::json(200, []));
        try {
            $this->connettore($f)->invia(Richiesta::json('POST', 'https://api.example.org/invio', ['a' => 1]));
            $this->fail('attesa eccezione');
        } catch (ErroreConnettore $e) {
            $this->assertSame(502, $e->stato);
            $this->assertCount(1, $f->ricevute);
        }
        $f = (new Finto())->accoda(new Risposta(502), Finto::json(200, []));
        $r = $this->connettore($f)->invia(Richiesta::json('POST', 'https://api.example.org/invio', ['a' => 1],
            ['Idempotency-Key' => 'k1']));
        $this->assertSame(2, $r->tentativi);
        $f = (new Finto())->accoda(new Risposta(429), Finto::json(201, []));
        $this->assertSame(201, $this->connettore($f)->invia(Richiesta::modulo('POST', 'https://x.example.org/', ['a' => 'b']))->stato);
        $this->assertSame(404, $this->connettore((new Finto())->accoda(new Risposta(404)))
            ->invia(new Richiesta('GET', 'https://x.example.org/'), [404])->stato);
    }

    public function test_errore_di_rete_e_registro_senza_segreti(): void {
        $mem = new Memoria();
        $reg = (new Registro())->aggiungi($mem);
        $f = (new Finto())->accoda(new ErroreConnettore('timeout'), new ErroreConnettore('timeout'), new ErroreConnettore('timeout'));
        try {
            $this->connettore($f, null, $reg)->invia(new Richiesta('GET', 'https://u:p@api.example.org/v1/x?access_token=SEGRETO',
                ['Authorization' => 'Bearer SEGRETISSIMO']));
            $this->fail('attesa eccezione');
        } catch (ErroreConnettore $e) {
            $this->assertTrue($e->di_rete());
            $this->assertCount(3, $f->ricevute);
        }
        $voce = $mem->voci[0];
        $this->assertSame(['error', 'pfi/connettori'], [$voce->livello, $voce->modulo]);
        $this->assertStringContainsString('https://api.example.org/v1/x?…', $voce->messaggio);
        $this->assertStringNotContainsString('SEGRET', $voce->json());
        $this->assertStringContainsString('3 tentativi', $voce->messaggio);
        $this->assertSame('https://api.telegram.org/…/sendMessage',
            (new Richiesta('POST', 'https://api.telegram.org/bot123456789:AAEhBOweik6ad9r_QXMENQjcrGbqCr4K-yk/sendMessage'))->url_per_registro());
    }

    public function test_politica_e_limite(): void {
        $p = new Politica(3, 1.0, 5.0);
        $this->assertSame(2.0, $p->attesa(3, null, fn() => 1.0));
        $this->assertSame(5.0, $p->attesa(2, new Risposta(429, ['retry-after' => '120'])));
        $this->assertFalse($p->ritentabile(new Richiesta('GET', 'https://x.example.org'), new Risposta(500)));
        $this->assertTrue((new Politica(3, 1, 5, true))->ritentabile(new Richiesta('GET', 'https://x.example.org'), new Risposta(500)));
        $this->assertFalse($p->ritentabile(new Richiesta('GET', 'https://x.example.org'), new Risposta(400)));
        $t = 100.0;
        $l = new Limite(2, 1.0, null, function() use (&$t) {
            return $t;
        });
        $this->assertSame([0.0, 0.0, 0.5, 1.0], [$l->prendi(), $l->prendi(), $l->prendi(), $l->prendi()]);
        $t += 10;
        $this->assertSame(0.0, $l->prendi());
        $file = sys_get_temp_dir() . '/pfi-limite-' . bin2hex(random_bytes(3));
        $a = new Limite(1, 10.0, $file, fn() => 50.0);
        $b = new Limite(1, 10.0, $file, fn() => 50.0);
        $this->assertSame([0.0, 10.0], [$a->prendi(), $b->prendi()]);
        unlink($file);
        $this->expectException(\Pfi\Contratti\Eccezione::class);
        new Richiesta('GET', 'file:///etc/passwd');
    }

    public function test_trasporto_curl(): void {
        $base = self::avvia();
        $c = new Curl(2.0, 1.0, [], null, 100000);
        $r = $c->invia(Richiesta::json('POST', $base . '/eco', ['a' => 'è'], ['Authorization' => "Bearer x\r\nX-Iniettata: 1"]));
        $d = $r->json();
        $this->assertSame(['POST', '{"a":"è"}', 'Bearer xX-Iniettata: 1', 'application/json'],
            [$d['metodo'], $d['corpo'], $d['autorizzazione'], $d['tipo']]);
        $this->assertSame('application/json', $r->intestazione('Content-Type'));
        $this->assertSame(302, $c->invia(new Richiesta('GET', $base . '/rimanda'))->stato);
        $this->assertSame(418, $c->invia(new Richiesta('GET', $base . '/stato/418'))->stato);
        try {
            $c->invia(new Richiesta('GET', $base . '/grande'));
            $this->fail('attesa eccezione');
        } catch (ErroreConnettore $e) {
            $this->assertStringContainsString('dimensione massima', $e->getMessage());
        }
        try {
            $c->invia(new Richiesta('GET', $base . '/lento', [], null, 0.5));
            $this->fail('attesa eccezione');
        } catch (ErroreConnettore $e) {
            $this->assertTrue($e->di_rete());
        }
        $conn = new Connettore('locale', $c, new Politica(2, 0, 0));
        $chiuso = new Connettore('chiuso', new Curl(1.0, 0.5), new Politica(1, 0, 0));
        $esito = (new Raggiungibili(['locale' => [$conn, $base . '/stato/401'], 'chiuso' => [$chiuso, 'http://127.0.0.1:1/']]))->esegui();
        $this->assertSame('avviso', $esito->stato);
        $this->assertSame(401, $esito->dettagli['locale']);
        $this->assertSame('buono', (new Raggiungibili())->esegui()->stato);
    }
}
