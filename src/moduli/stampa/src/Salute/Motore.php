<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Stampa\Salute;

use Pfi\Contratti\EsitoSalute;
use Pfi\Contratti\ProvaDiSalute;
use Pfi\Stampa\Chromium;

/** Prova di salute: programma Chromium presente; con $prova vera impagina anche una pagina minima. */
final class Motore implements ProvaDiSalute {

    public function __construct(private readonly ?Chromium $chromium = null, private readonly bool $prova = false) {
    }

    public function codice(): string {
        return 'stampa.motore';
    }

    public function descrizione(): string {
        return 'Motore di stampa PDF';
    }

    public function esegui(): EsitoSalute {
        $c = $this->chromium;
        if ($c === null) {
            $p = Chromium::cerca();
            return $p !== null ? EsitoSalute::buono('Chromium presente.', ['programma' => $p])
                : EsitoSalute::avviso('Chromium non installato: le stampe in PDF non sono disponibili.');
        }
        if (!$this->prova) {
            return EsitoSalute::buono('Motore di stampa configurato.');
        }
        try {
            $c->pdf('<!DOCTYPE html><html><body>prova</body></html>');
            return EsitoSalute::buono('Impaginazione di prova riuscita.');
        } catch (\Throwable $e) {
            return EsitoSalute::guasto('Impaginazione di prova non riuscita: ' . $e->getMessage());
        }
    }
}
