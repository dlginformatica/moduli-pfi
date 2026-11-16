<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione\Posta;

use Pfi\Comunicazione\Canale;
use Pfi\Comunicazione\ErroreInvio;
use Pfi\Comunicazione\Invio;
use Pfi\Comunicazione\Messaggio;
use Pfi\Connettori\Connettore;
use Pfi\Connettori\ErroreConnettore;
use Pfi\Connettori\Richiesta;
use Pfi\Connettori\Risposta;
use Pfi\Contratti\EsitoSalute;

/**
 * Base comune della posta con le API dei fornitori (D-161 d): invio attraverso un connettore (tentativi, limiti,
 * registro senza segreti), errori tradotti in ErroreInvio temporanei o definitivi, prova della configurazione con una
 * chiamata in sola lettura dove il fornitore la offre.
 */
abstract class Api implements Canale {

    public function __construct(protected readonly Connettore $connettore) {
    }

    abstract protected function fornitore(): string;

    abstract protected function richiesta(Messaggio $m): Richiesta;

    abstract protected function esito(Risposta $r, Messaggio $m): Invio;

    /** Richiesta in sola lettura per la prova della configurazione, o null se il fornitore non ne ha una adatta. */
    protected function richiesta_prova(): ?Richiesta {
        return null;
    }

    public function tipo(): string {
        return 'posta';
    }

    public function nome(): string {
        return 'posta ' . $this->fornitore();
    }

    public function invia(Messaggio $m): Invio {
        if ($m->mittente === null) {
            throw new ErroreInvio('Messaggio di posta senza mittente.', false);
        }
        try {
            return $this->esito($this->connettore->invia($this->richiesta($m)), $m);
        } catch (ErroreConnettore $e) {
            throw ErroreInvio::da_stato_http($this->fornitore(), $e->stato, $this->dettaglio($e->risposta));
        }
    }

    /** Breve spiegazione dell'errore dal corpo JSON della risposta (campi «message», «error»…), senza dati del messaggio. */
    protected function dettaglio(?Risposta $r): string {
        if ($r === null) {
            return '';
        }
        $d = json_decode($r->corpo, true);
        $t = '';
        if (is_array($d)) {
            $t = $d['error']['message'] ?? $d['message'] ?? $d['Message'] ?? $d['errors'][0]['message'] ?? $d['error_description']
                ?? (is_string($d['error'] ?? null) ? $d['error'] : '') ?? '';
        }
        return mb_substr(trim(preg_replace('/\s+/', ' ', (string) $t)), 0, 160);
    }

    public function salute(): EsitoSalute {
        try {
            $prova = $this->richiesta_prova();
            if ($prova === null) {
                return EsitoSalute::avviso('Configurazione non verificabile senza un invio di prova.', ['fornitore' => $this->fornitore()]);
            }
            $r = $this->connettore->invia($prova, [401, 403]);
            return in_array($r->stato, [401, 403], true)
                ? EsitoSalute::guasto("{$this->fornitore()}: credenziali rifiutate ({$r->stato}).")
                : EsitoSalute::buono("{$this->fornitore()}: credenziali accettate.");
        } catch (ErroreConnettore|ErroreInvio $e) {
            return EsitoSalute::guasto("{$this->fornitore()}: " . ($e instanceof ErroreConnettore && $e->di_rete()
                ? 'servizio non raggiungibile.' : 'prova non riuscita (' . ($e instanceof ErroreConnettore ? $e->stato : $e->codice) . ').'));
        }
    }

    /** @return list<array{email:string,name?:string}> */
    protected static function elenco(array $indirizzi): array {
        return array_map(fn($i) => $i->nome !== '' ? ['email' => $i->valore, 'name' => $i->nome] : ['email' => $i->valore], $indirizzi);
    }

    protected static function json(Risposta $r): array {
        $d = json_decode($r->corpo, true);
        return is_array($d) ? $d : [];
    }
}
