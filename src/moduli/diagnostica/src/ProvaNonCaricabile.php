<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Diagnostica;

use Pfi\Contratti\EsitoSalute;
use Pfi\Contratti\ProvaDiSalute;

/**
 * Prova dichiarata in un manifesto ma non costruibile: si riporta come guasto con il motivo.
 */
final class ProvaNonCaricabile implements ProvaDiSalute {

    public function __construct(private readonly string $classe, private readonly string $motivo) {
    }

    public function codice(): string {
        return 'diagnostica.caricamento';
    }

    public function descrizione(): string {
        return 'Caricamento della prova ' . $this->classe;
    }

    public function esegui(): EsitoSalute {
        return EsitoSalute::guasto('Prova non caricabile: ' . $this->motivo);
    }
}
