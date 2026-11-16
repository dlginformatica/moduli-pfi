<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Connettori;

use Pfi\Contratti\Eccezione;

/**
 * Tentativi e attese (proposta 49 § 3.10). Si ritenta solo quando è sicuro: errore di rete prima della risposta, oppure
 * 429, 502, 503, 504 (e 500 se ritenta500). Per POST e PATCH, che non sono idempotenti, si ritenta un errore HTTP solo
 * se la richiesta lo dichiara con l'intestazione Idempotency-Key, oppure se il servizio risponde 429 o 503 (non ha
 * eseguito). L'attesa cresce in modo esponenziale con una variazione casuale e rispetta Retry-After fino al massimo.
 */
final class Politica {

    public function __construct(
        public readonly int $tentativi = 3,
        public readonly float $attesainiziale = 0.5,
        public readonly float $attesamassima = 30.0,
        public readonly bool $ritenta500 = false,
    ) {
        if ($tentativi < 1 || $tentativi > 10 || $attesainiziale < 0 || $attesamassima < $attesainiziale) {
            throw new Eccezione('Politica dei tentativi non valida.');
        }
    }

    public function ritentabile(Richiesta $richiesta, ?Risposta $risposta): bool {
        if ($risposta === null) {
            return true;
        }
        $s = $risposta->stato;
        if (in_array($s, [429, 503], true)) {
            return true;
        }
        if (!in_array($s, [502, 504], true) && !($s === 500 && $this->ritenta500)) {
            return false;
        }
        if (in_array($richiesta->metodo, ['POST', 'PATCH'], true)) {
            foreach (array_keys($richiesta->intestazioni) as $k) {
                if (strcasecmp($k, 'Idempotency-Key') === 0) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    /** Attesa in secondi prima del tentativo numero $n (dal secondo in poi). */
    public function attesa(int $n, ?Risposta $risposta, ?\Closure $caso = null): float {
        $dopo = $risposta?->intestazione('retry-after');
        if ($dopo !== null) {
            $sec = ctype_digit(trim($dopo)) ? (float) trim($dopo) : (float) max(0, (strtotime($dopo) ?: time()) - time());
            return min($sec, $this->attesamassima);
        }
        $base = $this->attesainiziale * (2 ** max(0, $n - 2));
        $variazione = $caso ? ($caso)() : mt_rand() / mt_getrandmax();
        return min($base * (0.5 + $variazione / 2), $this->attesamassima);
    }
}
