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
 * Google Workspace con la Gmail API: POST gmail/v1/users/{utente}/messages/send con il messaggio MIME in base64url
 * nel campo «raw»; conto di servizio con delega a livello di dominio e ambito gmail.send. Riferimento:
 * developers.google.com/workspace/gmail/api/guides/sending, verificato il 14 novembre 2026.
 */
final class Gmail extends Api {

    public const AMBITO = 'https://www.googleapis.com/auth/gmail.send';

    public function __construct(Connettore $c, private readonly Gettone $gettone, private readonly string $utente = 'me') {
        parent::__construct($c);
    }

    protected function fornitore(): string {
        return 'gmail';
    }

    protected function richiesta(Messaggio $m): Richiesta {
        [$mime] = Mime::componi($m);
        return Richiesta::json('POST', 'https://gmail.googleapis.com/gmail/v1/users/' . rawurlencode($this->utente) . '/messages/send',
            ['raw' => rtrim(strtr(base64_encode($mime), '+/', '-_'), '=')], ['Authorization' => 'Bearer ' . $this->gettone->valore()]);
    }

    protected function esito(Risposta $r, Messaggio $m): Invio {
        return new Invio((string) (self::json($r)['id'] ?? ''), "accettato {$r->stato}");
    }
}
