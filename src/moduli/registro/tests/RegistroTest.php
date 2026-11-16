<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Registro\Prove;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pfi\Contratti\EsitoSalute;
use Pfi\Registro\Destinazione;
use Pfi\Registro\Destinazioni\File;
use Pfi\Registro\Destinazioni\Memoria;
use Pfi\Registro\Destinazioni\Syslog;
use Pfi\Registro\Destinazioni\Tabella;
use Pfi\Registro\Mascheratore;
use Pfi\Registro\Registro;
use Pfi\Registro\Rfc5424;
use Pfi\Registro\Salute\Destinazioni;
use Pfi\Registro\Voce;

final class RegistroTest extends TestCase {

    private string $cartella;

    protected function setUp(): void {
        $this->cartella = sys_get_temp_dir() . '/pfi-registro-' . bin2hex(random_bytes(4));
        mkdir($this->cartella, 0700);
    }

    protected function tearDown(): void {
        foreach (glob($this->cartella . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cartella);
    }

    public static function casi_con_segreti(): array {
        return [
            'parametro' => ['connessione con password=Sup3rS3greta&utente=mario', 'Sup3rS3greta'],
            'json' => ['{"token": "abcd1234efgh"}', 'abcd1234efgh'],
            'intestazione' => ["Authorization: Basic dXRlbnRlOnBhc3N3b3Jk\r\nHost: x", 'dXRlbnRlOnBhc3N3b3Jk'],
            'bearer' => ['risposta a Bearer eyJhbGciOiJIUzI1NiJ9.abc.def', 'eyJhbGciOiJIUzI1NiJ9'],
            'indirizzo' => ['smtp://posta:Segreto99@smtp.example.org:587', 'Segreto99'],
            'chiave api' => ['api_key: k-77-xyz-000', 'k-77-xyz-000'],
            'virgolette' => ["secret='due parole'", 'due parole'],
        ];
    }

    #[DataProvider('casi_con_segreti')]
    public function test_mascheramento_testo(string $testo, string $segreto): void {
        $fuori = (new Mascheratore())->testo($testo);
        $this->assertStringNotContainsString($segreto, $fuori);
        $this->assertStringContainsString(Mascheratore::MASCHERA, $fuori);
    }

    public function test_mascheramento_contesto_e_valori_noti(): void {
        $m = new Mascheratore();
        $m->valore_segreto('valore-del-deposito-42');
        $m->valore_segreto('abc');
        $c = $m->contesto(['utente' => 'mario', 'password' => 'x1', 'smtp_pass' => 'y2', 'Token' => 'z3', 'vuoto' => '',
            'nota' => 'contiene valore-del-deposito-42 dentro', 'breve' => 'abc', 'annidato' => ['client_secret' => 's'],
            'eccezione' => new \RuntimeException('token=zzzzzzzz'), 'oggetto' => new \stdClass(), 'numero' => 3]);
        $this->assertSame('mario', $c['utente']);
        foreach (['password', 'smtp_pass', 'Token'] as $k) {
            $this->assertSame(Mascheratore::MASCHERA, $c[$k], $k);
        }
        $this->assertSame('', $c['vuoto']);
        $this->assertSame('contiene ' . Mascheratore::MASCHERA . ' dentro', $c['nota']);
        $this->assertSame('abc', $c['breve']);
        $this->assertSame(Mascheratore::MASCHERA, $c['annidato']['client_secret']);
        $this->assertSame('RuntimeException: token=' . Mascheratore::MASCHERA, $c['eccezione']);
        $this->assertSame('[stdClass]', $c['oggetto']);
        $this->assertSame(3, $c['numero']);
    }

    public function test_livelli_interpolazione_correlazione(): void {
        $mem = new Memoria();
        $tutto = new Memoria();
        $ora = new \DateTimeImmutable('2026-11-14T10:00:00+01:00');
        $r = (new Registro(new Mascheratore(), 'pfi/prova', 'c0ffee', fn() => $ora))
            ->aggiungi($mem, Registro::AVVISO)->aggiungi($tutto);
        $r->info('Avvio di {cosa}', ['cosa' => 'prova']);
        $r->error('Invio a {utente} non riuscito: password={password}', ['utente' => 'mario', 'password' => 'Pippo123']);
        $r->per_modulo('pfi/altro')->con_correlazione('beef')->warning('Da {x} a {y}', ['x' => [1, 2], 'y' => null]);
        $this->assertCount(2, $mem->voci);
        $this->assertCount(3, $tutto->voci);
        $this->assertSame('Avvio di prova', $tutto->voci[0]->messaggio);
        $this->assertStringNotContainsString('Pippo123', $mem->voci[0]->messaggio);
        $this->assertSame(['pfi/prova', 'c0ffee'], [$mem->voci[0]->modulo, $mem->voci[0]->correlazione]);
        $this->assertSame(['pfi/altro', 'beef', 'Da [1,2] a null'], [$mem->voci[1]->modulo, $mem->voci[1]->correlazione,
            $mem->voci[1]->messaggio]);
        $this->assertNotSame($r, $r->per_modulo('pfi/x'));
        $r->notice('dopo');
        $this->assertSame(['pfi/prova', 'c0ffee'], [end($tutto->voci)->modulo, end($tutto->voci)->correlazione]);
        $r->registra(Registro::NOTA, 'esplicito', ['modulo' => 'pfi/m', 'correlazione' => 'k']);
        $ultima = end($tutto->voci);
        $this->assertSame(['pfi/m', 'k', []], [$ultima->modulo, $ultima->correlazione, $ultima->contesto]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', Registro::nuova_correlazione());
        $this->expectException(\Pfi\Contratti\Eccezione::class);
        $r->registra('fatale', 'x');
    }

    public function test_destinazione_guasta_non_ferma_le_altre(): void {
        $guasta = new class implements Destinazione {
            public function nome(): string {
                return 'guasta';
            }
            public function scrivi(Voce $voce): void {
                throw new \RuntimeException('connessione rifiutata, password=abcdefgh');
            }
            public function salute(): EsitoSalute {
                return EsitoSalute::guasto('no');
            }
        };
        $mem = new Memoria();
        $r = (new Registro())->aggiungi($guasta)->aggiungi($mem);
        $r->error('evento');
        $this->assertCount(1, $mem->voci);
        $this->assertCount(1, $r->guasti());
        $this->assertStringNotContainsString('abcdefgh', $r->guasti()[0]);
        $esito = (new Destinazioni($r))->esegui();
        $this->assertSame('guasto', $esito->stato);
        $this->assertSame('avviso', (new Destinazioni())->esegui()->stato);
        $this->assertSame('buono', (new Destinazioni((new Registro())->aggiungi(new Memoria())))->esegui()->stato);
    }

    private function voce(array $contesto = [], string $livello = 'error'): Voce {
        return new Voce(new \DateTimeImmutable('2026-11-14T10:00:00.123456+01:00'), $livello, 'Messaggio àè "x"',
            'pfi/comunicazione', 'abc123', $contesto);
    }

    public function test_formato_rfc5424(): void {
        $f = new Rfc5424('local3', 'server uno', 'pfi', 4321);
        $s = $f->formatta($this->voce(['destinatario' => 'a]b"c\\d', 'tentativi' => 2, 'nome con spazi' => true]));
        $this->assertSame(19 * 8 + 3, $f->priorita($this->voce()));
        $this->assertStringStartsWith('<155>1 2026-11-14T09:00:00.123456Z server_uno pfi 4321 comunicazione '
            . '[pfi@32473 modulo="pfi/comunicazione" correlazione="abc123" livello="error"]'
            . '[pfictx@32473 destinatario="a\\]b\\"c\\\\d" tentativi="2" nome_con_spazi="true"] ', $s);
        $this->assertStringEndsWith("\xEF\xBB\xBFMessaggio àè \"x\"", $s);
        $this->assertStringNotContainsString('[pfictx', $f->formatta($this->voce()));
        $this->expectException(\Pfi\Contratti\Eccezione::class);
        new Rfc5424('kern');
    }

    public function test_syslog_udp(): void {
        $server = stream_socket_server('udp://127.0.0.1:0', $n, $e, STREAM_SERVER_BIND);
        $porta = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        $d = new Syslog('udp', '127.0.0.1', $porta, new Rfc5424('local0', 'h', 'pfi', 1));
        $d->scrivi($this->voce());
        $ricevuto = stream_socket_recvfrom($server, 9000);
        $this->assertStringStartsWith('<131>1 ', $ricevuto);
        $this->assertSame('avviso', $d->salute()->stato);
        fclose($server);
    }

    public function test_syslog_tcp_con_conteggio_dei_byte(): void {
        $server = stream_socket_server('tcp://127.0.0.1:0', $n, $e);
        $porta = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        $d = new Syslog('tcp', '127.0.0.1', $porta, new Rfc5424('local0', 'h', 'pfi', 1));
        $d->scrivi($this->voce());
        $d->scrivi($this->voce([], 'info'));
        $c = stream_socket_accept($server, 2);
        stream_set_timeout($c, 2);
        $dati = '';
        while (substr_count($dati, '<1') < 2 && !feof($c)) {
            $dati .= fread($c, 8192);
            if (strlen($dati) > 0 && preg_match_all('/(\d+) <\d+>1 /', $dati) >= 2) {
                break;
            }
        }
        preg_match('/^(\d+) /', $dati, $m);
        $primo = substr($dati, strlen($m[0]), (int) $m[1]);
        $this->assertStringStartsWith('<131>1 ', $primo);
        $this->assertStringEndsWith('Messaggio àè "x"', $primo);
        $resto = substr($dati, strlen($m[0]) + (int) $m[1]);
        $this->assertMatchesRegularExpression('/^\d+ <134>1 /', $resto);
        $this->assertSame('buono', $d->salute()->stato);
        fclose($c);
        fclose($server);
        $chiusa = new Syslog('tcp', '127.0.0.1', $porta, new Rfc5424(), 0.5);
        $this->assertSame('guasto', $chiusa->salute()->stato);
    }

    public function test_syslog_tls_verifica_il_certificato(): void {
        $chiave = $this->cartella . '/server.key';
        $cert = $this->cartella . '/server.crt';
        exec('openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=localhost -addext subjectAltName=DNS:localhost '
            . '-keyout ' . escapeshellarg($chiave) . ' -out ' . escapeshellarg($cert) . ' 2>/dev/null', $o, $esito);
        if ($esito !== 0 || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('openssl o pcntl non disponibili');
        }
        $ctx = stream_context_create(['ssl' => ['local_cert' => $cert, 'local_pk' => $chiave]]);
        $server = stream_socket_server('tls://127.0.0.1:0', $n, $e, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
        $porta = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        $pid = pcntl_fork();
        if ($pid === 0) {
            $c = @stream_socket_accept($server, 3);
            if ($c) {
                file_put_contents($this->cartella . '/ricevuto', fread($c, 8192));
                fclose($c);
            }
            @stream_socket_accept($server, 2);
            exit(0);
        }
        $d = new Syslog('tls', '127.0.0.1', $porta, new Rfc5424('local0', 'h', 'pfi', 1), 2.0,
            ['ca' => $cert, 'nome' => 'localhost']);
        $d->scrivi($this->voce());
        $d->chiudi();
        $senzaca = new Syslog('tls', '127.0.0.1', $porta, new Rfc5424(), 1.0, ['nome' => 'localhost']);
        $this->assertSame('guasto', $senzaca->salute()->stato);
        pcntl_waitpid($pid, $st);
        fclose($server);
        $this->assertMatchesRegularExpression('/^\d+ <131>1 /', (string) @file_get_contents($this->cartella . '/ricevuto'));
    }

    public function test_file_rotazione_e_conservazione(): void {
        $p = $this->cartella . '/registro.log';
        $d = new File($p, 1024, 3, 30);
        for ($i = 0; $i < 40; $i++) {
            $d->scrivi($this->voce(['i' => $i]));
        }
        $this->assertFileExists($p . '.1');
        $this->assertFileExists($p . '.3');
        $this->assertFileDoesNotExist($p . '.4');
        $this->assertSame(0640, fileperms($p) & 0777);
        $righe = file($p);
        $ultima = json_decode(end($righe), true);
        $this->assertSame(39, $ultima['contesto']['i']);
        $this->assertSame('buono', $d->salute()->stato);
        touch($p . '.3', time() - 31 * 86400);
        $this->assertSame(1, $d->conserva());
        $this->assertFileDoesNotExist($p . '.3');
        $this->assertSame('guasto', (new File('/inesistente/x.log'))->salute()->stato);
    }

    public function test_tabella_sqlite(): void {
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $t = new Tabella($pdo);
        $this->assertSame('guasto', $t->salute()->stato);
        $t->crea_tabella();
        $r = (new Registro(new Mascheratore(), 'pfi/stampa'))->aggiungi($t);
        $r->info('primo');
        $r->error('secondo con token={t}', ['t' => 'qwertyuiop']);
        $r->con_correlazione('zz')->warning('terzo');
        $this->assertCount(3, $t->cerca());
        $this->assertCount(2, $t->cerca(['livello' => 'warning']));
        $this->assertSame('terzo', $t->cerca(['correlazione' => 'zz'])[0]['messaggio']);
        $this->assertStringNotContainsString('qwertyuiop', $t->cerca(['testo' => 'secondo'])[0]['messaggio']);
        $this->assertSame(0, $t->conserva(1));
        $this->assertSame(3, $t->conserva(1, time() + 2 * 86400));
        $this->assertSame('buono', $t->salute()->stato);
        $this->expectException(\Pfi\Contratti\Eccezione::class);
        new Tabella($pdo, 'x; drop');
    }
}
