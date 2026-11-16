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
 * SendGrid (Twilio): POST /v3/mail/send con personalizations, from, subject, content e attachments; chiave in
 * Authorization Bearer; risposta 202 con l'intestazione X-Message-Id. Riferimento: twilio.com/docs/sendgrid/
 * api-reference/mail-send, verificato il 14 novembre 2026.
 */
final class SendGrid extends Api {

    public function __construct(Connettore $c, #[\SensitiveParameter] private readonly string $chiave,
        private readonly string $base = 'https://api.sendgrid.com') {
        parent::__construct($c);
    }

    protected function fornitore(): string {
        return 'sendgrid';
    }

    protected function richiesta(Messaggio $m): Richiesta {
        $p = ['to' => self::elenco($m->destinatari)];
        if ($m->cc) {
            $p['cc'] = self::elenco($m->cc);
        }
        if ($m->ccn) {
            $p['bcc'] = self::elenco($m->ccn);
        }
        $contenuto = [];
        if (($t = $m->testo_semplice()) !== '') {
            $contenuto[] = ['type' => 'text/plain', 'value' => $t];
        }
        if ($m->html !== '') {
            $contenuto[] = ['type' => 'text/html', 'value' => $m->html];
        }
        $d = ['personalizations' => [$p], 'from' => self::elenco([$m->mittente])[0], 'subject' => $m->oggetto, 'content' => $contenuto];
        if ($m->rispondia) {
            $d['reply_to'] = self::elenco([$m->rispondia])[0];
        }
        if ($m->intestazioni) {
            $d['headers'] = $m->intestazioni;
        }
        foreach ($m->allegati as $a) {
            $d['attachments'][] = ['content' => base64_encode($a->contenuto), 'filename' => $a->nome, 'type' => $a->tipo,
                'disposition' => 'attachment'];
        }
        return Richiesta::json('POST', $this->base . '/v3/mail/send', $d, ['Authorization' => 'Bearer ' . $this->chiave]);
    }

    protected function richiesta_prova(): Richiesta {
        return new Richiesta('GET', $this->base . '/v3/scopes', ['Authorization' => 'Bearer ' . $this->chiave]);
    }

    protected function esito(Risposta $r, Messaggio $m): Invio {
        return new Invio($r->intestazione('x-message-id') ?? '', "accettato {$r->stato}");
    }
}
