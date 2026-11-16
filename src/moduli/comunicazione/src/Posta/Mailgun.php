<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione\Posta;

use Pfi\Comunicazione\ErroreInvio;
use Pfi\Comunicazione\Invio;
use Pfi\Comunicazione\Messaggio;
use Pfi\Comunicazione\Mime;
use Pfi\Connettori\Connettore;
use Pfi\Connettori\Richiesta;
use Pfi\Connettori\Risposta;

/**
 * Mailgun: POST /v3/{dominio}/messages.mime in multipart/form-data con i destinatari in «to» e il messaggio MIME in
 * «message»; autenticazione Basic con utente «api» e la chiave; sede degli Stati Uniti (api.mailgun.net) o dell'Unione
 * europea (api.eu.mailgun.net). Riferimento: documentation.mailgun.com/docs/mailgun/api-reference/send, verificato il
 * 14 novembre 2026 [DA VERIFICARE: nome del campo «message» con un account di prova].
 */
final class Mailgun extends Api {

    public function __construct(Connettore $c, private readonly string $dominio, #[\SensitiveParameter] private readonly string $chiave,
        private readonly string $sede = 'eu') {
        parent::__construct($c);
        if (!in_array($sede, ['eu', 'us'], true) || !preg_match('/^[A-Za-z0-9.-]{3,253}$/', $dominio)) {
            throw new ErroreInvio('Sede o dominio Mailgun non validi.', false);
        }
    }

    protected function fornitore(): string {
        return 'mailgun';
    }

    private function base(): string {
        return $this->sede === 'eu' ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
    }

    private function autorizzazione(): string {
        return 'Basic ' . base64_encode('api:' . $this->chiave);
    }

    protected function richiesta(Messaggio $m): Richiesta {
        [$mime] = Mime::componi($m);
        $confine = 'pfi' . bin2hex(random_bytes(12));
        $corpo = '';
        foreach ([...$m->destinatari, ...$m->cc, ...$m->ccn] as $d) {
            $corpo .= "--{$confine}\r\nContent-Disposition: form-data; name=\"to\"\r\n\r\n{$d->valore}\r\n";
        }
        $corpo .= "--{$confine}\r\nContent-Disposition: form-data; name=\"message\"; filename=\"messaggio.eml\"\r\n"
            . "Content-Type: message/rfc822\r\n\r\n{$mime}\r\n--{$confine}--\r\n";
        return new Richiesta('POST', $this->base() . '/v3/' . rawurlencode($this->dominio) . '/messages.mime',
            ['Authorization' => $this->autorizzazione(), 'Content-Type' => "multipart/form-data; boundary={$confine}"], $corpo);
    }

    protected function richiesta_prova(): Richiesta {
        return new Richiesta('GET', $this->base() . '/v3/domains/' . rawurlencode($this->dominio), ['Authorization' => $this->autorizzazione()]);
    }

    protected function esito(Risposta $r, Messaggio $m): Invio {
        return new Invio(trim((string) (self::json($r)['id'] ?? ''), '<>'), "accettato {$r->stato}");
    }
}
