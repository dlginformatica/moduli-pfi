<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Connettori\Trasporti;

use Pfi\Connettori\ErroreConnettore;
use Pfi\Connettori\Richiesta;
use Pfi\Connettori\Risposta;
use Pfi\Connettori\Trasporto;

/**
 * Fornitore finto per le prove (proposta 49 § 3.10; D-161 d: senza account di prova i fornitori di posta via API si
 * provano con questo trasporto). Le risposte si accodano o si calcolano con una funzione; le richieste ricevute restano
 * in $ricevute per i controlli.
 */
final class Finto implements Trasporto {

    /** @var list<Risposta|ErroreConnettore|\Closure> */
    private array $coda = [];

    /** @var list<Richiesta> */
    public array $ricevute = [];

    /** @param \Closure(Richiesta):Risposta|null $sempre risposta calcolata quando la coda è vuota */
    public function __construct(private readonly ?\Closure $sempre = null) {
    }

    public function accoda(Risposta|ErroreConnettore|\Closure ...$risposte): self {
        array_push($this->coda, ...$risposte);
        return $this;
    }

    public static function json(int $stato, array $dati, array $intestazioni = []): Risposta {
        return new Risposta($stato, ['content-type' => 'application/json'] + $intestazioni,
            json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function invia(Richiesta $richiesta): Risposta {
        $this->ricevute[] = $richiesta;
        $r = array_shift($this->coda) ?? $this->sempre;
        if ($r === null) {
            throw new ErroreConnettore('fornitore finto senza risposte', 0);
        }
        if ($r instanceof \Closure) {
            $r = $r($richiesta);
        }
        if ($r instanceof ErroreConnettore) {
            throw $r;
        }
        return $r;
    }
}
