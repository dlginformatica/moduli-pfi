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
 * Brevo: POST /v3/smtp/email con sender, to, cc, bcc, subject, htmlContent, textContent, replyTo, headers e
 * attachment (contenuto in base64 con il nome); chiave nell'intestazione «api-key»; risposta 201 con messageId.
 * Riferimento: developers.brevo.com/reference/send-transac-email, verificato il 14 novembre 2026.
 */
final class Brevo extends Api {

    public function __construct(Connettore $c, #[\SensitiveParameter] private readonly string $chiave) {
        parent::__construct($c);
    }

    protected function fornitore(): string {
        return 'brevo';
    }

    protected function richiesta(Messaggio $m): Richiesta {
        $d = ['sender' => self::elenco([$m->mittente])[0], 'to' => self::elenco($m->destinatari), 'subject' => $m->oggetto];
        if ($m->cc) {
            $d['cc'] = self::elenco($m->cc);
        }
        if ($m->ccn) {
            $d['bcc'] = self::elenco($m->ccn);
        }
        $d['htmlContent'] = $m->html !== '' ? $m->html : '<pre>' . htmlspecialchars($m->testo) . '</pre>';
        if (($t = $m->testo_semplice()) !== '') {
            $d['textContent'] = $t;
        }
        if ($m->rispondia) {
            $d['replyTo'] = self::elenco([$m->rispondia])[0];
        }
        if ($m->intestazioni) {
            $d['headers'] = $m->intestazioni;
        }
        foreach ($m->allegati as $a) {
            $d['attachment'][] = ['content' => base64_encode($a->contenuto), 'name' => $a->nome];
        }
        return Richiesta::json('POST', 'https://api.brevo.com/v3/smtp/email', $d, ['api-key' => $this->chiave]);
    }

    protected function richiesta_prova(): Richiesta {
        return new Richiesta('GET', 'https://api.brevo.com/v3/account', ['api-key' => $this->chiave, 'Accept' => 'application/json']);
    }

    protected function esito(Risposta $r, Messaggio $m): Invio {
        return new Invio(trim((string) (self::json($r)['messageId'] ?? ''), '<>'), "accettato {$r->stato}");
    }
}
