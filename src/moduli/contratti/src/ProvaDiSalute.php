<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Contratti;

/**
 * Prova di salute di un modulo: la eseguono la diagnostica (pfi/diagnostica, pfi-verifica) e il pannello dei moduli prima
 * e dopo un aggiornamento. Deve essere rapida, in sola lettura e senza effetti sui dati.
 */
interface ProvaDiSalute {

    /** Codice stabile della prova, per esempio «registro.scrittura». */
    public function codice(): string;

    /** Descrizione in italiano di che cosa controlla. */
    public function descrizione(): string;

    public function esegui(): EsitoSalute;
}
