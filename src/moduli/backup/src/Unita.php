<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Backup;

/**
 * Unità di backup (proposta 49 § 3.8): database, cartella dei dati, configurazione del server, singolo corso, dati di
 * un componente. Ogni unità scrive i propri file nella cartella del backup e sa ripristinarli.
 */
interface Unita {

    /** Nome stabile, usato nel manifesto e nella scelta del ripristino granulare (per esempio «database»). */
    public function nome(): string;

    public function descrizione(): string;

    /** @return list<string> nomi dei file scritti in $cartella */
    public function salva(string $cartella): array;

    /** Ripristina dai file dell'unità in $cartella (già verificati con le impronte). */
    public function ripristina(string $cartella): void;

    /** Controllo senza effetti che i file dell'unità siano ripristinabili (per la prova periodica). */
    public function prova(string $cartella): void;
}
