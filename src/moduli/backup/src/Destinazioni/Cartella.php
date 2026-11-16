<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Backup\Destinazioni;

use Pfi\Backup\Backup;
use Pfi\Backup\Comando;
use Pfi\Backup\Destinazione;
use Pfi\Contratti\Eccezione;

/**
 * Cartella locale o di rete già montata (NFS, SMB): ogni backup in una sottocartella con il suo identificativo,
 * copiata prima con un nome provvisorio e rinominata alla fine, così una copia interrotta non sembra completa.
 */
final class Cartella implements Destinazione {

    public function __construct(private readonly string $percorso) {
        if (!str_starts_with($percorso, '/')) {
            throw new Eccezione('Percorso della destinazione non assoluto.');
        }
    }

    public function nome(): string {
        return 'cartella ' . $this->percorso;
    }

    public function invia(string $cartella, string $id): void {
        if (!is_dir($this->percorso) || !is_writable($this->percorso)) {
            throw new Eccezione('Cartella di destinazione assente o non scrivibile (montaggio mancante?).');
        }
        $tmp = "{$this->percorso}/.{$id}.parziale";
        Comando::esegui(['rm', '-rf', '--', $tmp]);
        mkdir($tmp, 0700);
        foreach (Backup::file_di($cartella) as $f) {
            Comando::riuscito(['cp', '--', "{$cartella}/{$f}", "{$tmp}/{$f}"]);
        }
        if (!rename($tmp, "{$this->percorso}/{$id}")) {
            throw new Eccezione('Copia del backup non completata.');
        }
    }

    public function elenco(): array {
        return array_values(array_filter(scandir($this->percorso) ?: [], fn($x) => Backup::valido($x)));
    }

    public function elimina(string $id): void {
        if (Backup::valido($id)) {
            Comando::riuscito(['rm', '-rf', '--', "{$this->percorso}/{$id}"]);
        }
    }

    public function scarica(string $id, string $cartella): void {
        if (!Backup::valido($id) || !is_dir("{$this->percorso}/{$id}")) {
            throw new Eccezione("Backup {$id} assente nella destinazione.");
        }
        mkdir($cartella, 0700, true);
        foreach (Backup::file_di("{$this->percorso}/{$id}") as $f) {
            Comando::riuscito(['cp', '--', "{$this->percorso}/{$id}/{$f}", "{$cartella}/{$f}"]);
        }
    }
}
