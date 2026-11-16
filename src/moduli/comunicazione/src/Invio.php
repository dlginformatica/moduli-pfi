<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione;

/** Esito di un invio accettato dal fornitore: identificativo restituito e risposta sintetica senza segreti. */
final class Invio {

    public function __construct(public readonly string $idfornitore = '', public readonly string $risposta = '') {
    }
}
