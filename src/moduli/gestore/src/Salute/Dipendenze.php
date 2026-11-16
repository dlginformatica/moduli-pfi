<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Gestore\Salute;

use Pfi\Contratti\Caricatore;
use Pfi\Contratti\EsitoSalute;
use Pfi\Contratti\ProvaDiSalute;
use Pfi\Gestore\Gestore;

/** Prova di salute: ogni modulo installato trova le versioni dei moduli che richiede. */
final class Dipendenze implements ProvaDiSalute {

    public function __construct(private readonly ?Gestore $gestore = null) {
    }

    public function codice(): string {
        return 'gestore.dipendenze';
    }

    public function descrizione(): string {
        return 'Dipendenze fra i moduli universali';
    }

    public function esegui(): EsitoSalute {
        if ($this->gestore !== null) {
            $problemi = [];
            foreach ($this->gestore->elenco() as $nome => $s) {
                foreach ($s['mancanti'] as $p) {
                    $problemi[] = "{$nome}: {$p}";
                }
            }
        } else {
            $moduli = Caricatore::moduli();
            $disp = array_map(fn($m) => $m->versione, $moduli);
            $problemi = [];
            foreach ($moduli as $nome => $m) {
                foreach ($m->requisiti_mancanti($disp) as $p) {
                    $problemi[] = "{$nome}: {$p}";
                }
            }
        }
        return $problemi ? EsitoSalute::guasto('Dipendenze non soddisfatte: ' . implode('; ', $problemi) . '.')
            : EsitoSalute::buono('Dipendenze fra i moduli soddisfatte.');
    }
}
