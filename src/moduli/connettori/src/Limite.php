<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Connettori;

/**
 * Limite di frequenza a secchiello (token bucket) per connettore: al massimo $richieste nell'arco di $secondi, con
 * l'attesa necessaria prima di una richiesta oltre il limite. Lo stato è nel processo; con $file lo stato è condiviso
 * fra i processi del server attraverso un file con blocco esclusivo.
 */
final class Limite {

    private float $gettoni;
    private float $ultimo;

    /** @param \Closure():float|null $orologio */
    public function __construct(
        private readonly int $richieste,
        private readonly float $secondi,
        private readonly ?string $file = null,
        private ?\Closure $orologio = null,
    ) {
        if ($richieste < 1 || $secondi <= 0) {
            throw new \Pfi\Contratti\Eccezione('Limite di frequenza non valido.');
        }
        $this->orologio ??= fn() => microtime(true);
        $this->gettoni = $richieste;
        $this->ultimo = ($this->orologio)();
    }

    /** Prende un gettone; restituisce i secondi da attendere prima di inviare (0 se si invia subito). */
    public function prendi(): float {
        if ($this->file === null) {
            return $this->calcola();
        }
        $fp = fopen($this->file, 'c+');
        if ($fp === false) {
            return $this->calcola();
        }
        try {
            flock($fp, LOCK_EX);
            $stato = json_decode((string) stream_get_contents($fp), true);
            if (is_array($stato) && isset($stato['g'], $stato['u'])) {
                [$this->gettoni, $this->ultimo] = [(float) $stato['g'], (float) $stato['u']];
            }
            $attesa = $this->calcola();
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode(['g' => $this->gettoni, 'u' => $this->ultimo]));
            return $attesa;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function calcola(): float {
        $adesso = ($this->orologio)();
        $ritmo = $this->richieste / $this->secondi;
        $this->gettoni = min($this->richieste, $this->gettoni + max(0, $adesso - $this->ultimo) * $ritmo);
        $this->ultimo = $adesso;
        $this->gettoni -= 1;
        return $this->gettoni >= 0 ? 0.0 : -$this->gettoni / $ritmo;
    }
}
