<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esecutore\Salute;

use Pfi\Contratti\EsitoSalute;
use Pfi\Contratti\ProvaDiSalute;
use Pfi\Esecutore\Coda as CodaRichieste;

/**
 * Prova di salute della coda: cartelle presenti e nessuna richiesta in attesa da più di $ritardo secondi (che indica il
 * servizio pfi-esecutore fermo). Senza coda (costruzione dal manifesto) usa la cartella predefinita del kit.
 */
final class Coda implements ProvaDiSalute {

    public const PREDEFINITA = '/var/spool/pfi-esecutore';

    public function __construct(private readonly ?CodaRichieste $coda = null, private readonly int $ritardo = 300) {
    }

    public function codice(): string {
        return 'esecutore.coda';
    }

    public function descrizione(): string {
        return 'Coda delle operazioni privilegiate';
    }

    public function esegui(): EsitoSalute {
        $coda = $this->coda ?? new CodaRichieste(self::PREDEFINITA);
        foreach (['richieste', 'esiti'] as $c) {
            if (!is_dir($coda->cartella($c))) {
                return $this->coda === null ? EsitoSalute::avviso('Coda delle operazioni privilegiate non installata.')
                    : EsitoSalute::guasto('Cartella della coda mancante.', ['cartella' => $c]);
            }
        }
        $vecchie = 0;
        foreach (glob($coda->cartella('richieste') . '/*.json') ?: [] as $f) {
            if (filemtime($f) < time() - $this->ritardo) {
                $vecchie++;
            }
        }
        return $vecchie > 0
            ? EsitoSalute::guasto('Richieste in attesa da oltre ' . intdiv($this->ritardo, 60) . ' minuti: il servizio pfi-esecutore sembra fermo.',
                ['in attesa' => $vecchie])
            : EsitoSalute::buono('Coda delle operazioni privilegiate in ordine.');
    }
}
