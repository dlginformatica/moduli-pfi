<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione\Salute;

use Pfi\Comunicazione\Comunicazione;
use Pfi\Contratti\EsitoSalute;
use Pfi\Contratti\ProvaDiSalute;

/**
 * Prova di salute dei canali configurati (connessione e credenziali, senza inviare). Senza configurazione
 * (costruzione dal manifesto) controlla solo le estensioni di PHP richieste.
 */
final class Canali implements ProvaDiSalute {

    public function __construct(private readonly ?Comunicazione $comunicazione = null) {
    }

    public function codice(): string {
        return 'comunicazione.canali';
    }

    public function descrizione(): string {
        return 'Canali di comunicazione';
    }

    public function esegui(): EsitoSalute {
        foreach (['openssl', 'mbstring', 'curl'] as $e) {
            if (!extension_loaded($e)) {
                return EsitoSalute::guasto("Estensione PHP mancante: {$e}.");
            }
        }
        if ($this->comunicazione === null) {
            return EsitoSalute::buono('Estensioni PHP presenti; i canali li prova l\'applicazione che li configura.');
        }
        $stato = EsitoSalute::BUONO;
        $dettagli = [];
        foreach ($this->comunicazione->canali() as $tipo => $c) {
            $e = $c->salute();
            $dettagli[$tipo] = $e->stato . ': ' . $e->messaggio;
            if ($e->stato === EsitoSalute::GUASTO || ($e->stato === EsitoSalute::AVVISO && $stato === EsitoSalute::BUONO)) {
                $stato = $e->stato;
            }
        }
        if (!$dettagli) {
            return EsitoSalute::avviso('Nessun canale di comunicazione configurato.');
        }
        return new EsitoSalute($stato, $stato === EsitoSalute::BUONO ? 'Canali di comunicazione in ordine.'
            : 'Canali di comunicazione con problemi.', $dettagli);
    }
}
