<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Segreti\Salute;

use Pfi\Contratti\EsitoSalute;
use Pfi\Contratti\ProvaDiSalute;
use Pfi\Segreti\Deposito;

/**
 * Prova di salute del deposito: chiave presente e protetta, cartella delle voci presente e non accessibile ad altri.
 */
final class Chiave implements ProvaDiSalute {

    public function __construct(private readonly ?Deposito $deposito = null) {
    }

    public function codice(): string {
        return 'segreti.deposito';
    }

    public function descrizione(): string {
        return 'Deposito dei segreti con chiave presente e permessi ristretti';
    }

    public function esegui(): EsitoSalute {
        if ($this->deposito === null) {
            return EsitoSalute::avviso('Deposito dei segreti non configurato');
        }
        if (!$this->deposito->attivo()) {
            return EsitoSalute::guasto('Chiave del deposito assente o troppo corta, oppure cartella delle voci mancante');
        }
        $problemi = [];
        if ((fileperms($this->deposito->file_chiave()) & 0o007) !== 0) {
            $problemi[] = 'la chiave è leggibile da tutti';
        }
        if ((fileperms($this->deposito->cartella()) & 0o007) !== 0) {
            $problemi[] = 'la cartella delle voci è accessibile a tutti';
        }
        if ($problemi) {
            return EsitoSalute::guasto('Permessi troppo larghi: ' . implode('; ', $problemi));
        }
        return EsitoSalute::buono('Deposito attivo', ['voci' => $this->deposito->voci()]);
    }
}
