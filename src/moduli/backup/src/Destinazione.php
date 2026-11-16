<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Backup;

/** Destinazione remota di una copia del backup (cartella di rete montata, SFTP, archivio compatibile S3). */
interface Destinazione {

    public function nome(): string;

    /** Copia la cartella del backup (file regolari) sotto il nome $id. */
    public function invia(string $cartella, string $id): void;

    /** @return list<string> identificativi dei backup presenti */
    public function elenco(): array;

    public function elimina(string $id): void;

    /** Riporta il backup $id in una cartella locale nuova. */
    public function scarica(string $id, string $cartella): void;
}
