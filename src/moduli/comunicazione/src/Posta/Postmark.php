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
 * Postmark: POST /email con From, To, Cc, Bcc, Subject, HtmlBody, TextBody, ReplyTo, Headers, Attachments e
 * MessageStream; gettone del server nell'intestazione X-Postmark-Server-Token; risposta con MessageID ed ErrorCode 0.
 * Riferimento: postmarkapp.com/developer/api/email-api, verificato il 14 novembre 2026.
 */
final class Postmark extends Api {

    public function __construct(Connettore $c, #[\SensitiveParameter] private readonly string $gettone,
        private readonly string $flusso = 'outbound') {
        parent::__construct($c);
    }

    protected function fornitore(): string {
        return 'postmark';
    }

    private static function lista(array $indirizzi): string {
        return implode(', ', array_map([Mime::class, 'indirizzo'], $indirizzi));
    }

    protected function richiesta(Messaggio $m): Richiesta {
        $d = ['From' => Mime::indirizzo($m->mittente), 'To' => self::lista($m->destinatari), 'Subject' => $m->oggetto,
            'MessageStream' => $this->flusso];
        if ($m->cc) {
            $d['Cc'] = self::lista($m->cc);
        }
        if ($m->ccn) {
            $d['Bcc'] = self::lista($m->ccn);
        }
        if ($m->html !== '') {
            $d['HtmlBody'] = $m->html;
        }
        if (($t = $m->testo_semplice()) !== '') {
            $d['TextBody'] = $t;
        }
        if ($m->rispondia) {
            $d['ReplyTo'] = Mime::indirizzo($m->rispondia);
        }
        foreach ($m->intestazioni as $k => $v) {
            $d['Headers'][] = ['Name' => $k, 'Value' => $v];
        }
        foreach ($m->allegati as $a) {
            $d['Attachments'][] = ['Name' => $a->nome, 'Content' => base64_encode($a->contenuto), 'ContentType' => $a->tipo];
        }
        return Richiesta::json('POST', 'https://api.postmarkapp.com/email', $d, ['X-Postmark-Server-Token' => $this->gettone]);
    }

    protected function richiesta_prova(): Richiesta {
        return new Richiesta('GET', 'https://api.postmarkapp.com/server', ['X-Postmark-Server-Token' => $this->gettone,
            'Accept' => 'application/json']);
    }

    protected function esito(Risposta $r, Messaggio $m): Invio {
        $d = self::json($r);
        if ((int) ($d['ErrorCode'] ?? 0) !== 0) {
            throw new ErroreInvio('postmark: invio non riuscito (codice ' . (int) $d['ErrorCode'] . ').', false, (string) $d['ErrorCode']);
        }
        return new Invio((string) ($d['MessageID'] ?? ''), "accettato {$r->stato}");
    }
}
