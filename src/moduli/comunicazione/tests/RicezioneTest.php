<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione\Prove;

use PHPUnit\Framework\TestCase;
use Pfi\Comunicazione\ErroreInvio;
use Pfi\Comunicazione\Ricezione\Imap;
use Pfi\Comunicazione\Ricezione\Pop3;
use Pfi\Comunicazione\Ricezione\Ricevuto;

final class RicezioneTest extends TestCase {

    private static string $cartella;
    private static string $cert;
    private static string $chiave;

    public static function setUpBeforeClass(): void {
        self::$cartella = sys_get_temp_dir() . '/pfi-ricezione-' . bin2hex(random_bytes(4));
        mkdir(self::$cartella);
        self::$cert = self::$cartella . '/c.crt';
        self::$chiave = self::$cartella . '/c.key';
        exec('openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=localhost -addext subjectAltName=DNS:localhost '
            . '-keyout ' . self::$chiave . ' -out ' . self::$cert . ' 2>/dev/null');
    }

    public static function tearDownAfterClass(): void {
        exec('rm -rf ' . escapeshellarg(self::$cartella));
    }

    private const RISPOSTA = "From: =?UTF-8?B?QW5uYSBSb3Nzw6w=?= <anna@example.org>\r\nTo: portale@example.org\r\n"
        . "Subject: Re: Promemoria\r\nDate: Sat, 14 Nov 2026 10:00:00 +0100\r\nMessage-ID: <r1@example.org>\r\n"
        . "In-Reply-To: <abc@example.org>\r\nX-Pfi-Correlazione: k1\r\nMIME-Version: 1.0\r\n"
        . "Content-Type: multipart/alternative; boundary=\"B\"\r\n\r\n--B\r\nContent-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n\r\nGrazie, ci sar=C3=B2.\r\n.punto\r\n--B\r\nContent-Type: text/html\r\n\r\n<p>x</p>\r\n--B--\r\n";

    private const RIMBALZO = "From: MAILER-DAEMON@example.org\r\nSubject: Undelivered\r\nMIME-Version: 1.0\r\n"
        . "Content-Type: multipart/report; report-type=delivery-status; boundary=\"R\"\r\n\r\n--R\r\nContent-Type: text/plain\r\n\r\n"
        . "Messaggio non consegnato.\r\n--R\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; mx.example.org\r\n\r\n"
        . "Final-Recipient: rfc822; nessuno@example.org\r\nAction: failed\r\nStatus: 5.1.1\r\n--R--\r\n";

    public function test_analisi_dei_messaggi(): void {
        $r = Ricevuto::da_mime('1', self::RISPOSTA);
        $this->assertSame(['Anna Rossì <anna@example.org>', 'Re: Promemoria', 'r1@example.org', 'abc@example.org', 'k1'],
            [$r->mittente, $r->oggetto, $r->messageid, $r->inrisposta, $r->correlazione]);
        $this->assertSame("Grazie, ci sarò.\n.punto", $r->testo);
        $this->assertNull($r->rimbalzo);
        $b = Ricevuto::da_mime('2', self::RIMBALZO);
        $this->assertSame(['destinatario' => 'nessuno@example.org', 'stato' => '5.1.1', 'azione' => 'failed'], $b->rimbalzo);
        $this->assertSame('Messaggio non consegnato.', $b->testo);
    }

    /** @param \Closure(resource):void $sessione */
    private function server(\Closure $sessione): array {
        $ctx = stream_context_create(['ssl' => ['local_cert' => self::$cert, 'local_pk' => self::$chiave]]);
        $s = stream_socket_server('tls://127.0.0.1:0', $n, $e, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
        $porta = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        $pid = pcntl_fork();
        if ($pid === 0) {
            while ($c = @stream_socket_accept($s, 5)) {
                $sessione($c);
                fclose($c);
            }
            exit(0);
        }
        fclose($s);
        return [$porta, $pid];
    }

    private function ferma(int $pid): void {
        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $st);
    }

    public function test_imap(): void {
        $log = self::$cartella . '/imap.txt';
        $messaggi = ['7' => self::RISPOSTA, '9' => self::RIMBALZO];
        [$porta, $pid] = $this->server(function($c) use ($messaggi, $log) {
            fwrite($c, "* OK pronto\r\n");
            while (($r = fgets($c)) !== false) {
                [$tag, $cmd] = explode(' ', trim($r), 2) + [1 => ''];
                file_put_contents($log, (str_starts_with($cmd, 'LOGIN') ? 'LOGIN …' : $cmd) . "\n", FILE_APPEND);
                if (str_starts_with($cmd, 'LOGIN')) {
                    fwrite($c, $cmd === 'LOGIN "servizio@example.org" "pa\\"ss"' ? "{$tag} OK\r\n" : "{$tag} NO [AUTHENTICATIONFAILED] no\r\n");
                } elseif (str_starts_with($cmd, 'SELECT')) {
                    fwrite($c, "* 2 EXISTS\r\n{$tag} OK [READ-WRITE]\r\n");
                } elseif ($cmd === 'UID SEARCH UNSEEN') {
                    fwrite($c, "* SEARCH 7 9\r\n{$tag} OK\r\n");
                } elseif (preg_match('/^UID FETCH (\d+)/', $cmd, $m)) {
                    $d = $messaggi[$m[1]];
                    fwrite($c, "* 1 FETCH (UID {$m[1]} BODY[] {" . strlen($d) . "}\r\n{$d})\r\n{$tag} OK\r\n");
                } elseif ($cmd === 'LOGOUT') {
                    fwrite($c, "* BYE\r\n{$tag} OK\r\n");
                    return;
                } else {
                    fwrite($c, "{$tag} OK\r\n");
                }
            }
        });
        try {
            $imap = new Imap('localhost', $porta, 'tls', 'servizio@example.org', 'pa"ss', null, 'INBOX', 5, self::$cert);
            $r = $imap->ricevi();
            $this->assertCount(2, $r);
            $this->assertSame(['7', 'Re: Promemoria'], [$r[0]->id, $r[0]->oggetto]);
            $this->assertSame('5.1.1', $r[1]->rimbalzo['stato']);
            $this->assertSame('buono', $imap->salute()->stato);
            $errata = new Imap('localhost', $porta, 'tls', 'servizio@example.org', 'altra', null, 'INBOX', 5, self::$cert);
            try {
                $errata->ricevi();
                $this->fail();
            } catch (ErroreInvio $e) {
                $this->assertStringNotContainsString('altra', $e->getMessage());
                $this->assertStringContainsString('accesso', $e->getMessage());
            }
        } finally {
            $this->ferma($pid);
        }
        $this->assertStringContainsString("UID STORE 7 +FLAGS.SILENT (\\Seen)\nUID FETCH 9 (BODY.PEEK[])", file_get_contents($log));
    }

    public function test_pop3(): void {
        $log = self::$cartella . '/pop3.txt';
        $messaggi = [1 => self::RISPOSTA, 2 => self::RIMBALZO];
        [$porta, $pid] = $this->server(function($c) use ($messaggi, $log) {
            fwrite($c, "+OK pronto\r\n");
            while (($r = fgets($c)) !== false) {
                $cmd = trim($r);
                file_put_contents($log, (str_starts_with($cmd, 'PASS') ? 'PASS …' : $cmd) . "\n", FILE_APPEND);
                if ($cmd === 'UIDL') {
                    fwrite($c, "+OK\r\n1 aaa\r\n2 bbb\r\n.\r\n");
                } elseif (preg_match('/^RETR (\d)$/', $cmd, $m)) {
                    fwrite($c, "+OK\r\n" . preg_replace('/^\./m', '..', $messaggi[(int) $m[1]]) . ".\r\n");
                } elseif ($cmd === 'PASS segreta' || !str_starts_with($cmd, 'PASS')) {
                    fwrite($c, "+OK\r\n");
                    if ($cmd === 'QUIT') {
                        return;
                    }
                } else {
                    fwrite($c, "-ERR accesso negato\r\n");
                }
            }
        });
        try {
            $pop = new Pop3('localhost', $porta, 'tls', 'servizio', 'segreta', false, fn(string $u) => $u === 'aaa', 5, self::$cert);
            $r = $pop->ricevi();
            $this->assertCount(1, $r);
            $this->assertSame(['bbb', 'nessuno@example.org'], [$r[0]->id, $r[0]->rimbalzo['destinatario']]);
            $this->assertSame(['bbb'], $pop->letti);
            $cancella = new Pop3('localhost', $porta, 'tls', 'servizio', 'segreta', true, null, 5, self::$cert);
            $this->assertSame("Grazie, ci sarò.\n.punto", $cancella->ricevi(1)[0]->testo);
            $this->assertSame('guasto', (new Pop3('localhost', $porta, 'tls', 'servizio', 'no', true, null, 5, self::$cert))->salute()->stato);
        } finally {
            $this->ferma($pid);
        }
        $t = file_get_contents($log);
        $this->assertStringContainsString("RETR 1\nDELE 1\nQUIT", $t);
        $this->assertStringNotContainsString('segreta', $t);
    }
}
