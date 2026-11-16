<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Diagnostica;

use Pfi\Contratti\EsitoSalute;

/**
 * Risultato di una prova di salute eseguita: modulo, codice, descrizione, esito e durata.
 */
final class Risultato {

    public function __construct(
        public readonly string $modulo,
        public readonly string $codice,
        public readonly string $descrizione,
        public readonly EsitoSalute $esito,
        public readonly float $millisecondi,
    ) {
    }

    /** @return array<string,mixed> */
    public function in_array(): array {
        return ['modulo' => $this->modulo, 'codice' => $this->codice, 'descrizione' => $this->descrizione,
            'stato' => $this->esito->stato, 'messaggio' => $this->esito->messaggio, 'dettagli' => $this->esito->dettagli,
            'ms' => round($this->millisecondi, 1)];
    }
}
