<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Contratti;

/**
 * Registro che non scrive nulla: per le prove e per le applicazioni senza registro.
 */
final class RegistroNullo implements Registro {

    public function registra(string $livello, string $messaggio, array $contesto = []): void {
        if (!isset(self::LIVELLI[$livello])) {
            throw new Eccezione("Livello del registro sconosciuto: «{$livello}»");
        }
    }
}
