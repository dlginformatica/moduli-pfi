<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione\Prove;

use PHPUnit\Framework\TestCase;
use Pfi\Comunicazione\Allegato;
use Pfi\Comunicazione\ErroreInvio;
use Pfi\Comunicazione\Indirizzo;
use Pfi\Comunicazione\Messaggio;
use Pfi\Comunicazione\Mime;
use Pfi\Comunicazione\Posta\Smtp;
use Pfi\Contratti\Eccezione;

require_once __DIR__ . '/ServerSmtp.php';

final class PostaTest extends TestCase {

    private static string $cartella;
    private static string $cert;
    private static string $chiave;

    public static function setUpBeforeClass(): void {
        self::$cartella = sys_get_temp_dir() . '/pfi-posta-' . bin2hex(random_bytes(4));
        mkdir(self::$cartella);
        self::$cert = self::$cartella . '/c.crt';
        self::$chiave = self::$cartella . '/c.key';
        exec('openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=localhost -addext subjectAltName=DNS:localhost '
            . '-keyout ' . self::$chiave . ' -out ' . self::$cert . ' 2>/dev/null');
    }

    public static function tearDownAfterClass(): void {
        exec('rm -rf ' . escapeshellarg(self::$cartella));
    }

    private function messaggio(array $a = []): Messaggio {
        return new Messaggio([Indirizzo::posta('maria.rossi@example.org', 'Maria Rossì')],
            'Promemoria: lezione di giovedì sul Codice dei contratti pubblici e sulle procedure negoziate',
            "<p>Gentile Maria,</p>\n<p>la lezione è <b>giovedì</b>.</p>\n.riga che inizia con un punto",
            '', Indirizzo::posta('portale@example.org', 'Portale di formazione'), [], [], $a,
            Indirizzo::posta('ufficio@example.org'), ['X-Pfi-Correlazione' => 'abc']);
    }

    public function test_composizione_mime(): void {
        [$dati, $id] = Mime::componi($this->messaggio([new Allegato('Programma è.pdf', 'application/pdf', '%PDF-1.4 prova')]),
            new \DateTimeImmutable('2026-11-14 10:00:00+01:00'), 'CONFINE');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}@example\.org$/', $id);
        $this->assertStringContainsString("Date: Sat, 14 Nov 2026 10:00:00 +0100\r\n", $dati);
        $this->assertStringContainsString("From: \"Portale di formazione\" <portale@example.org>\r\n", $dati);
        $this->assertStringContainsString('To: =?UTF-8?B?' . base64_encode('Maria Rossì') . '?= <maria.rossi@example.org>', $dati);
        $this->assertStringContainsString("Reply-To: ufficio@example.org\r\n", $dati);
        $this->assertMatchesRegularExpression('/Subject: =\?UTF-8\?B\?[^?]+\?=\r\n =\?UTF-8\?B\?/', $dati);
        $this->assertStringContainsString('Content-Type: multipart/mixed; boundary="mCONFINE"', $dati);
        $this->assertStringContainsString('Content-Type: multipart/alternative; boundary="aCONFINE"', $dati);
        $this->assertStringContainsString("filename*=UTF-8''Programma%20%C3%A8.pdf", $dati);
        $this->assertStringContainsString(base64_encode('%PDF-1.4 prova'), $dati);
        $this->assertStringContainsString("Gentile Maria,\r\n\r\nla lezione =C3=A8 gioved=C3=AC.", $dati);
        $this->assertStringNotContainsString("\n\n", str_replace("\r\n", '', $dati));
        foreach (explode("\r\n", $dati) as $riga) {
            $this->assertLessThanOrEqual(78, strlen($riga));
        }
        $soggetto = iconv_mime_decode_headers($dati, 0, 'UTF-8')['Subject'];
        $this->assertSame($this->messaggio()->oggetto, $soggetto);
        foreach ([fn() => Indirizzo::posta('non valido'), fn() => new Indirizzo("a\r\nBcc: x@y"),
            fn() => new Messaggio([]), fn() => new Messaggio([new Indirizzo('x')], intestazioni: ['Bcc' => 'x']),
            fn() => new Allegato('../x', 'text/plain', '')] as $errato) {
            try {
                $errato();
                $this->fail('accettato');
            } catch (Eccezione) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_smtp_starttls_autenticazione_e_invio(): void {
        $s = new ServerSmtp(self::$cartella, self::$cert, self::$chiave);
        try {
            $smtp = new Smtp('localhost', $s->porta, 'starttls', 'utente', 'Segreta!1', 5, 'portale', self::$cert);
            $esito = $smtp->invia($this->messaggio());
            $this->assertSame('ABC123XYZ', $esito->idfornitore);
            $this->assertSame('buono', $smtp->salute()->stato);
            try {
                (new Smtp('localhost', $s->porta, 'starttls', 'utente', 'sbagliata', 5, null, self::$cert))->invia($this->messaggio());
                $this->fail('autenticazione accettata');
            } catch (ErroreInvio $e) {
                $this->assertFalse($e->temporaneo);
                $this->assertStringNotContainsString('sbagliata', $e->getMessage());
            }
            try {
                $smtp->invia($this->messaggio()->per(Indirizzo::posta('rifiuta@example.org')));
                $this->fail('destinatario accettato');
            } catch (ErroreInvio $e) {
                $this->assertSame([false, '550'], [$e->temporaneo, $e->codice]);
            }
            try {
                $smtp->invia($this->messaggio()->per(Indirizzo::posta('pieno@example.org')));
                $this->fail('destinatario accettato');
            } catch (ErroreInvio $e) {
                $this->assertTrue($e->temporaneo);
            }
            $senzaca = new Smtp('localhost', $s->porta, 'starttls', 'utente', 'Segreta!1', 5);
            $this->assertSame('guasto', $senzaca->salute()->stato);
        } finally {
            $s->ferma();
        }
        $t = file_get_contents($s->trascrizione);
        $this->assertStringNotContainsString('Segreta', $t);
        $this->assertStringContainsString("MAIL FROM:<portale@example.org>", $t);
        $this->assertStringContainsString("\r\n..riga che inizia con un punto", $t);
        $this->assertSame(6, substr_count($t, 'C: STARTTLS'));
        $this->assertSame('guasto', (new Smtp('127.0.0.1', 1, 'starttls', '', '', 1))->salute()->stato);
        $this->expectException(Eccezione::class);
        new Smtp('x', 25, 'nessuna', 'utente', 'p');
    }
}
