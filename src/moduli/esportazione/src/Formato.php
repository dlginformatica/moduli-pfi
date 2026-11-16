<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esportazione;

/**
 * Formato di esportazione di una tabella (proposta 49 § 3.6): intestazioni e righe scritte una alla volta in un flusso,
 * così che le esportazioni grandi non stiano in memoria. I formati in archivio ZIP (XLSX, ODS, DOCX, ODT) scrivono le
 * righe in un file temporaneo e compongono l'archivio alla chiusura.
 */
interface Formato {

    /** Nome breve: csv, tsv, delimitato, fisso, xlsx, ods, docx, odt, rtf, pdf, html, json, xml, md. */
    public function nome(): string;

    public function estensione(): string;

    public function tipo(): string;

    /**
     * @param resource $uscita flusso scrivibile
     * @param list<string> $colonne intestazioni
     */
    public function inizia($uscita, array $colonne, string $titolo = ''): void;

    /** @param list<mixed> $valori nello stesso ordine delle colonne */
    public function riga(array $valori): void;

    public function chiudi(): void;
}
