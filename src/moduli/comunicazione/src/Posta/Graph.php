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
 * Microsoft 365 con Microsoft Graph: POST /v1.0/users/{casella}/sendMail con il messaggio MIME in base64
 * (Content-Type text/plain), risposta 202; permesso dell'applicazione Mail.Send, gettone con le credenziali del
 * client. Riferimento: learn.microsoft.com/graph/api/user-sendmail, verificato il 14 novembre 2026. Si consiglia di
 * limitare l'applicazione alla sola casella del portale con i criteri di accesso di Exchange.
 */
final class Graph extends Api {

    public function __construct(Connettore $c, private readonly Gettone $gettone, private readonly string $casella) {
        parent::__construct($c);
    }

    protected function fornitore(): string {
        return 'graph';
    }

    protected function richiesta(Messaggio $m): Richiesta {
        [$mime] = Mime::componi($m);
        return new Richiesta('POST', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($this->casella) . '/sendMail',
            ['Authorization' => 'Bearer ' . $this->gettone->valore(), 'Content-Type' => 'text/plain'], base64_encode($mime));
    }

    protected function esito(Risposta $r, Messaggio $m): Invio {
        return new Invio($r->intestazione('request-id') ?? '', "accettato {$r->stato}");
    }

    protected function richiesta_prova(): Richiesta {
        return new Richiesta('GET', 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($this->casella) . '?$select=id',
            ['Authorization' => 'Bearer ' . $this->gettone->valore()]);
    }
}
