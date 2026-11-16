<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Backup\Unita;

use Pfi\Backup\Comando;
use Pfi\Backup\Unita;
use Pfi\Contratti\Eccezione;

/**
 * Database PostgreSQL: pg_dump nel formato personalizzato; ripristino come nel rapporto 48, § 2.3 (database ricreato
 * vuoto con il proprietario e la codifica UTF-8, pg_restore senza proprietari con il ruolo del portale); prova con
 * pg_restore -l e, se richiesta, ripristino completo in un database temporaneo (rapporto 48, § 2.2).
 *
 * $come è il prefisso dei comandi (per esempio ['runuser', '-u', 'postgres', '--'] quando il servizio gira come root);
 * $ambiente le variabili di connessione (PGHOST, PGPORT, PGUSER).
 */
final class Postgres implements Unita {

    /**
     * @param list<string> $come
     * @param array<string,string> $ambiente
     */
    public function __construct(
        private readonly string $database,
        private readonly string $proprietario,
        private readonly array $come = [],
        private readonly array $ambiente = [],
        private readonly bool $provaprofonda = false,
    ) {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $database) || !preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $proprietario)) {
            throw new Eccezione('Nome di database o di ruolo non valido.');
        }
    }

    public function nome(): string {
        return 'database';
    }

    public function descrizione(): string {
        return "Database PostgreSQL «{$this->database}»";
    }

    private function cmd(string ...$a): array {
        return [...$this->come, ...$a];
    }

    public function salva(string $cartella): array {
        Comando::riuscito($this->cmd('pg_dump', '-Fc', '--no-password', '-d', $this->database), null, "{$cartella}/database.dump", $this->ambiente);
        return ['database.dump'];
    }

    public function ripristina(string $cartella): void {
        $f = "{$cartella}/database.dump";
        Comando::riuscito($this->cmd('dropdb', '--force', '--if-exists', $this->database), null, null, $this->ambiente);
        Comando::riuscito($this->cmd('createdb', '-O', $this->proprietario, '-E', 'UTF8', '-T', 'template0', $this->database), null, null, $this->ambiente);
        Comando::riuscito($this->cmd('pg_restore', '--no-owner', '--role=' . $this->proprietario, '--exit-on-error', '-d', $this->database), $f, null, $this->ambiente);
    }

    public function prova(string $cartella): void {
        $f = "{$cartella}/database.dump";
        Comando::riuscito($this->cmd('pg_restore', '-l'), $f, '/dev/null', $this->ambiente);
        if (!$this->provaprofonda) {
            return;
        }
        $tmp = 'pfi_prova_ripristino_' . bin2hex(random_bytes(4));
        try {
            Comando::riuscito($this->cmd('createdb', '-O', $this->proprietario, '-E', 'UTF8', '-T', 'template0', $tmp), null, null, $this->ambiente);
            Comando::riuscito($this->cmd('pg_restore', '--no-owner', '--role=' . $this->proprietario, '--exit-on-error', '-d', $tmp), $f, null, $this->ambiente);
        } finally {
            Comando::esegui($this->cmd('dropdb', '--force', '--if-exists', $tmp), null, null, $this->ambiente);
        }
    }
}
