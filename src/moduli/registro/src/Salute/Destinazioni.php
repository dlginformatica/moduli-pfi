<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Registro\Salute;

use Pfi\Contratti\EsitoSalute;
use Pfi\Contratti\ProvaDiSalute;
use Pfi\Registro\Registro;

/**
 * Prova di salute del registro: ogni destinazione raggiungibile o scrivibile, nessun errore di scrittura recente.
 * Senza registro (costruzione dal manifesto) dà un avviso: l'applicazione la sostituisce con un'istanza configurata.
 */
final class Destinazioni implements ProvaDiSalute {

    public function __construct(private readonly ?Registro $registro = null) {
    }

    public function codice(): string {
        return 'registro.destinazioni';
    }

    public function descrizione(): string {
        return 'Destinazioni del registro';
    }

    public function esegui(): EsitoSalute {
        if ($this->registro === null) {
            return EsitoSalute::avviso('Registro non configurato in questa prova: la configura l\'applicazione.');
        }
        $destinazioni = $this->registro->destinazioni();
        if (!$destinazioni) {
            return EsitoSalute::avviso('Il registro non ha destinazioni: gli eventi non si conservano.');
        }
        $stato = EsitoSalute::BUONO;
        $dettagli = [];
        foreach ($destinazioni as $i => $d) {
            $e = $d->salute();
            $dettagli[$i . ' ' . $d->nome()] = $e->stato;
            if ($e->stato === EsitoSalute::GUASTO || ($e->stato === EsitoSalute::AVVISO && $stato === EsitoSalute::BUONO)) {
                $stato = $e->stato;
            }
        }
        $guasti = count($this->registro->guasti());
        if ($guasti > 0) {
            $dettagli['errori di scrittura'] = $guasti;
            if ($stato === EsitoSalute::BUONO) {
                $stato = EsitoSalute::AVVISO;
            }
        }
        $messaggio = match ($stato) {
            EsitoSalute::BUONO => 'Destinazioni del registro in ordine.',
            EsitoSalute::AVVISO => 'Destinazioni del registro con avvisi.',
            default => 'Almeno una destinazione del registro è guasta.',
        };
        return new EsitoSalute($stato, $messaggio, $dettagli);
    }
}
