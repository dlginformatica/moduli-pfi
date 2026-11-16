<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Contratti;

/**
 * Accesso ai segreti (credenziali, chiavi, token): interfaccia comune; l'implementazione è il modulo pfi/segreti.
 * Il valore non va mai scritto nel registro né mostrato: per riconoscerlo si usa l'impronta.
 */
interface Segreti {

    /** Valore del segreto, o null se non c'è. */
    public function valore(string $nome): ?string;

    /** Il segreto esiste ed è non vuoto. */
    public function presente(string $nome): bool;

    /** Impronta non reversibile del valore (per riconoscerlo senza mostrarlo), o null se non c'è. */
    public function impronta(string $nome): ?string;
}
