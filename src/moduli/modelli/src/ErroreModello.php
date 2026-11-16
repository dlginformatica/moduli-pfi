<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Modelli;

/**
 * Errore di sintassi di un modello, con riga e colonna per l'editor.
 */
final class ErroreModello extends \Pfi\Contratti\Eccezione {

    public function __construct(string $messaggio, public readonly int $riga = 0, public readonly int $colonna = 0) {
        parent::__construct($riga > 0 ? "{$messaggio} (riga {$riga}, colonna {$colonna})" : $messaggio);
    }
}
