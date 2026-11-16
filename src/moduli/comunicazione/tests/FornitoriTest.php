<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione\Prove;

use PHPUnit\Framework\TestCase;
use Pfi\Comunicazione\Allegato;
use Pfi\Comunicazione\Canali\Telegram;
use Pfi\Comunicazione\Canali\WhatsApp;
use Pfi\Comunicazione\ErroreInvio;
use Pfi\Comunicazione\Indirizzo;
use Pfi\Comunicazione\Messaggio;
use Pfi\Comunicazione\Posta\Brevo;
use Pfi\Comunicazione\Posta\Gettone;
use Pfi\Comunicazione\Posta\Gmail;
use Pfi\Comunicazione\Posta\Graph;
use Pfi\Comunicazione\Posta\Mailgun;
use Pfi\Comunicazione\Posta\Postmark;
use Pfi\Comunicazione\Posta\SendGrid;
use Pfi\Comunicazione\Posta\Ses;
use Pfi\Connettori\Connettore;
use Pfi\Connettori\Politica;
use Pfi\Connettori\Richiesta;
use Pfi\Connettori\Risposta;
use Pfi\Connettori\Trasporti\Finto;
use Pfi\Registro\Destinazioni\Memoria;
use Pfi\Registro\Registro;

final class FornitoriTest extends TestCase {

    private Memoria $voci;

    private function connettore(Finto $f): Connettore {
        $this->voci = new Memoria();
        return new Connettore('prova', $f, new Politica(2, 0, 0), null, (new Registro())->aggiungi($this->voci), fn() => null);
    }

    private function messaggio(): Messaggio {
        return new Messaggio([Indirizzo::posta('maria@example.org', 'Maria')], 'Oggetto è', '<p>Ciao <b>Maria</b></p>', '',
            Indirizzo::posta('portale@example.org', 'Portale'), [Indirizzo::posta('cc@example.org')], [Indirizzo::posta('ccn@example.org')],
            [new Allegato('a.txt', 'text/plain', 'contenuto')], Indirizzo::posta('ufficio@example.org'), ['X-Pfi' => '1']);
    }

    private function senza_segreti(string $segreto): void {
        foreach ($this->voci->voci as $v) {
            $this->assertStringNotContainsString($segreto, $v->json());
        }
    }

    public function test_postmark_brevo_sendgrid(): void {
        $f = (new Finto())->accoda(Finto::json(200, ['ErrorCode' => 0, 'MessageID' => 'pm-1']), Finto::json(201, ['messageId' => '<br-1@x>']),
            new Risposta(202, ['x-message-id' => 'sg-1']));
        $c = $this->connettore($f);
        $this->assertSame('pm-1', (new Postmark($c, 'TOKEN-POSTMARK'))->invia($this->messaggio())->idfornitore);
        $this->assertSame('br-1@x', (new Brevo($c, 'CHIAVE-BREVO'))->invia($this->messaggio())->idfornitore);
        $this->assertSame('sg-1', (new SendGrid($c, 'CHIAVE-SENDGRID'))->invia($this->messaggio())->idfornitore);
        [$pm, $br, $sg] = $f->ricevute;
        $this->assertSame('https://api.postmarkapp.com/email', $pm->url);
        $this->assertSame('TOKEN-POSTMARK', $pm->intestazioni['X-Postmark-Server-Token']);
        $d = json_decode($pm->corpo, true);
        $this->assertSame(['"Portale" <portale@example.org>', '"Maria" <maria@example.org>', 'cc@example.org', 'ccn@example.org', 'outbound'],
            [$d['From'], $d['To'], $d['Cc'], $d['Bcc'], $d['MessageStream']]);
        $this->assertSame(['Name' => 'a.txt', 'Content' => base64_encode('contenuto'), 'ContentType' => 'text/plain'], $d['Attachments'][0]);
        $this->assertSame('Ciao Maria', $d['TextBody']);
        $d = json_decode($br->corpo, true);
        $this->assertSame(['https://api.brevo.com/v3/smtp/email', 'CHIAVE-BREVO'], [$br->url, $br->intestazioni['api-key']]);
        $this->assertSame([['email' => 'maria@example.org', 'name' => 'Maria']], $d['to']);
        $this->assertSame(['email' => 'ufficio@example.org'], $d['replyTo']);
        $d = json_decode($sg->corpo, true);
        $this->assertSame('https://api.sendgrid.com/v3/mail/send', $sg->url);
        $this->assertSame([['email' => 'ccn@example.org']], $d['personalizations'][0]['bcc']);
        $this->assertSame(['text/plain', 'text/html'], array_column($d['content'], 'type'));
        foreach (['TOKEN-POSTMARK', 'CHIAVE-BREVO', 'CHIAVE-SENDGRID'] as $s) {
            $this->senza_segreti($s);
        }
        $f->accoda(Finto::json(200, ['ErrorCode' => 406, 'Message' => 'inattivo']));
        try {
            (new Postmark($c, 'T'))->invia($this->messaggio());
            $this->fail('accettato');
        } catch (ErroreInvio $e) {
            $this->assertFalse($e->temporaneo);
        }
    }

    public function test_errori_temporanei_e_definitivi(): void {
        $f = (new Finto())->accoda(Finto::json(401, ['message' => 'chiave non valida']), new Risposta(503), new Risposta(503));
        $c = $this->connettore($f);
        try {
            (new Brevo($c, 'K'))->invia($this->messaggio());
            $this->fail();
        } catch (ErroreInvio $e) {
            $this->assertSame([false, '401'], [$e->temporaneo, $e->codice]);
            $this->assertStringContainsString('chiave non valida', $e->getMessage());
        }
        try {
            (new Brevo($c, 'K'))->invia($this->messaggio());
            $this->fail();
        } catch (ErroreInvio $e) {
            $this->assertTrue($e->temporaneo);
        }
        $f->accoda(Finto::json(200, []), Finto::json(401, []));
        $this->assertSame('buono', (new Postmark($c, 'T'))->salute()->stato);
        $this->assertSame('guasto', (new Postmark($c, 'T'))->salute()->stato);
    }

    public function test_mime_per_graph_gmail_ses_mailgun(): void {
        $chiave = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($chiave, $pem);
        $json = json_encode(['client_email' => 'portale@progetto.iam.gserviceaccount.com', 'private_key' => $pem]);
        $f = (new Finto())->accoda(
            Finto::json(200, ['access_token' => 'GETTONE-MS', 'expires_in' => 3600]), new Risposta(202, ['request-id' => 'ms-1']),
            new Risposta(202),
            Finto::json(200, ['access_token' => 'GETTONE-G', 'expires_in' => 3600]), Finto::json(200, ['id' => 'g-1']),
            Finto::json(200, ['MessageId' => 'ses-1']),
            Finto::json(200, ['id' => '<mg-1@example.org>', 'message' => 'Queued']));
        $c = $this->connettore($f);
        $graph = new Graph($c, Gettone::microsoft($c, 'contoso.onmicrosoft.com', 'id-client', 'SEGRETO-MS'), 'portale@example.org');
        $this->assertSame('ms-1', $graph->invia($this->messaggio())->idfornitore);
        $graph->invia($this->messaggio());
        $gmail = new Gmail($c, Gettone::google($c, $json, 'portale@example.org', Gmail::AMBITO), 'portale@example.org');
        $this->assertSame('g-1', $gmail->invia($this->messaggio())->idfornitore);
        $ses = new Ses($c, 'eu-south-1', 'AKIDPROVA', 'SEGRETO-AWS', fn() => new \DateTimeImmutable('2026-11-14T09:00:00Z'));
        $this->assertSame('ses-1', $ses->invia($this->messaggio())->idfornitore);
        $this->assertSame('mg-1@example.org', (new Mailgun($c, 'mg.example.org', 'CHIAVE-MG', 'eu'))->invia($this->messaggio())->idfornitore);
        [$tok, $g1, $g2, $gtok, $gm, $s, $mg] = $f->ricevute;
        $this->assertSame('https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/token', $tok->url);
        parse_str($tok->corpo, $campi);
        $this->assertSame(['client_credentials', 'https://graph.microsoft.com/.default'], [$campi['grant_type'], $campi['scope']]);
        $this->assertSame('https://graph.microsoft.com/v1.0/users/portale%40example.org/sendMail', $g1->url);
        $this->assertSame(['Bearer GETTONE-MS', 'text/plain'], [$g1->intestazioni['Authorization'], $g1->intestazioni['Content-Type']]);
        $this->assertStringContainsString('Subject: =?UTF-8?B?', base64_decode($g1->corpo, true));
        $this->assertSame('Bearer GETTONE-MS', $g2->intestazioni['Authorization'], 'gettone riusato');
        parse_str($gtok->corpo, $campi);
        [$h, $p, $firma] = explode('.', $campi['assertion']);
        $pl = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        $this->assertSame(['portale@progetto.iam.gserviceaccount.com', 'portale@example.org', Gmail::AMBITO, 'https://oauth2.googleapis.com/token'],
            [$pl['iss'], $pl['sub'], $pl['scope'], $pl['aud']]);
        $pub = openssl_pkey_get_details($chiave)['key'];
        $this->assertSame(1, openssl_verify("{$h}.{$p}", base64_decode(strtr($firma, '-_', '+/')), $pub, OPENSSL_ALGO_SHA256));
        $raw = json_decode($gm->corpo, true)['raw'];
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $raw);
        $this->assertStringContainsString('From: "Portale" <portale@example.org>', base64_decode(strtr($raw, '-_', '+/')));
        $this->assertSame('https://email.eu-south-1.amazonaws.com/v2/email/outbound-emails', $s->url);
        $this->assertMatchesRegularExpression('#^AWS4-HMAC-SHA256 Credential=AKIDPROVA/20261114/eu-south-1/ses/aws4_request, '
            . 'SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$#', $s->intestazioni['Authorization']);
        $this->assertSame(hash('sha256', $s->corpo), $s->intestazioni['X-Amz-Content-Sha256']);
        $this->assertSame('f4780e2d9f65fa895f9c67b32ce1baf0b0d8a43505a000a1a9e090d414db404d',
            bin2hex(Ses::chiave_firma('wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', '20120215', 'us-east-1', 'iam')));
        $this->assertSame('https://api.eu.mailgun.net/v3/mg.example.org/messages.mime', $mg->url);
        $this->assertSame('Basic ' . base64_encode('api:CHIAVE-MG'), $mg->intestazioni['Authorization']);
        $this->assertSame(3, substr_count($mg->corpo, 'name="to"'));
        $this->assertStringContainsString('name="message"; filename="messaggio.eml"', $mg->corpo);
        foreach (['GETTONE-MS', 'SEGRETO-MS', 'GETTONE-G', 'SEGRETO-AWS', 'CHIAVE-MG', 'BEGIN PRIVATE'] as $seg) {
            $this->senza_segreti($seg);
        }
    }

    public function test_telegram_e_whatsapp(): void {
        $token = '123456789:AAEhBOweik6ad9r_QXMENQjcrGbqCr4K-yk';
        $f = (new Finto())->accoda(Finto::json(200, ['ok' => true, 'result' => ['message_id' => 77]]),
            Finto::json(200, ['messages' => [['id' => 'wamid.X']]]), Finto::json(400, ['error' => ['code' => 132001, 'message' => 'modello inesistente']]));
        $c = $this->connettore($f);
        $m = new Messaggio([new Indirizzo('-1001234567')], 'Avviso', '<p>Lezione <b>spostata</b></p>');
        $this->assertSame('77', (new Telegram($c, $token))->invia($m)->idfornitore);
        $tg = json_decode($f->ricevute[0]->corpo, true);
        $this->assertSame(['-1001234567', "Avviso\n\nLezione spostata"], [$tg['chat_id'], $tg['text']]);
        $this->senza_segreti('AAEhBOweik6ad9r');
        $wa = new WhatsApp($c, 'GETTONE-WA', '106540352242922');
        $w = new Messaggio([new Indirizzo('+39 333 123-4567')], extra: ['modello' => 'promemoria_lezione', 'parametri' => ['Maria', '20/11']]);
        $this->assertSame('wamid.X', $wa->invia($w)->idfornitore);
        $d = json_decode($f->ricevute[1]->corpo, true);
        $this->assertSame(['393331234567', 'template', 'promemoria_lezione', 'it'], [$d['to'], $d['type'], $d['template']['name'],
            $d['template']['language']['code']]);
        $this->assertSame([['type' => 'text', 'text' => 'Maria'], ['type' => 'text', 'text' => '20/11']], $d['template']['components'][0]['parameters']);
        try {
            $wa->invia($w);
            $this->fail();
        } catch (ErroreInvio $e) {
            $this->assertStringContainsString('132001', $e->getMessage());
            $this->assertFalse($e->temporaneo);
        }
        $corpo = '{"entry":[]}';
        $this->assertTrue(WhatsApp::webhook_autentico($corpo, 'sha256=' . hash_hmac('sha256', $corpo, 'app'), 'app'));
        $this->assertFalse(WhatsApp::webhook_autentico($corpo, 'sha256=00', 'app'));
        $this->assertTrue(Telegram::webhook_autentico('s3', 's3'));
        $this->assertSame(['chat' => '42', 'da' => '7', 'testo' => 'ciao', 'id' => '1'],
            Telegram::ricevuto('{"message":{"message_id":1,"from":{"id":7},"chat":{"id":42},"text":"ciao"}}'));
        $this->expectException(ErroreInvio::class);
        WhatsApp::numero('abc');
    }
}
