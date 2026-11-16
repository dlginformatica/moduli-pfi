<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Registro\Destinazioni;

use Pfi\Contratti\EsitoSalute;
use Pfi\Registro\Destinazione;
use Pfi\Registro\Voce;

/** Destinazione in memoria, per le prove e per mostrare gli eventi di una singola operazione. */
final class Memoria implements Destinazione {

    /** @var list<Voce> */
    public array $voci = [];

    public function __construct(private readonly int $massimo = 1000) {
    }

    public function nome(): string {
        return 'memoria';
    }

    public function scrivi(Voce $voce): void {
        $this->voci[] = $voce;
        if (count($this->voci) > $this->massimo) {
            array_shift($this->voci);
        }
    }

    public function salute(): EsitoSalute {
        return EsitoSalute::buono('Destinazione in memoria.');
    }
}
