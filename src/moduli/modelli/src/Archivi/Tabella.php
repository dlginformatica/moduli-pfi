<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Modelli\Archivi;

use Pfi\Contratti\Eccezione;
use Pfi\Modelli\Archivio;
use Pfi\Modelli\Modello;

/**
 * Archivio in una tabella via PDO (PostgreSQL, MySQL o MariaDB, SQLite). Nel portale la tabella è dichiarata da
 * tool_pfimodelli; fuori da Moodle si crea con crea_tabella(). Il numero di versione è unico per chiave, canale e lingua.
 */
final class Tabella implements Archivio {

    public function __construct(private readonly \PDO $pdo, private readonly string $tabella = 'pfi_modelli') {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $tabella)) {
            throw new Eccezione('Nome di tabella dei modelli non valido.');
        }
    }

    public function crea_tabella(): void {
        $id = match ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'pgsql' => 'BIGSERIAL PRIMARY KEY',
            'mysql' => 'BIGINT AUTO_INCREMENT PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
        $t = $this->tabella;
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$t} (id {$id}, chiave VARCHAR(100) NOT NULL, canale VARCHAR(31) NOT NULL, "
            . "lingua VARCHAR(5) NOT NULL, versione INTEGER NOT NULL, parti TEXT NOT NULL, autore VARCHAR(100) NOT NULL, "
            . "nota VARCHAR(255) NOT NULL, creato BIGINT NOT NULL)");
        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS {$t}_versione ON {$t} (chiave, canale, lingua, versione)");
    }

    public function salva(Modello $m, string $autore, string $nota = ''): Modello {
        $this->pdo->beginTransaction();
        try {
            $s = $this->pdo->prepare("SELECT MAX(versione) FROM {$this->tabella} WHERE chiave = ? AND canale = ? AND lingua = ?");
            $s->execute([$m->chiave, $m->canale, $m->lingua]);
            $n = (int) $s->fetchColumn() + 1;
            $this->pdo->prepare("INSERT INTO {$this->tabella} (chiave, canale, lingua, versione, parti, autore, nota, creato) "
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$m->chiave, $m->canale, $m->lingua, $n,
                json_encode($m->parti, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                mb_substr($autore, 0, 100), mb_substr($nota, 0, 255), time()]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return new Modello($m->chiave, $m->canale, $m->lingua, $m->parti, $n);
    }

    public function attuale(string $chiave, string $canale, string $lingua): ?Modello {
        $s = $this->pdo->prepare("SELECT versione, parti FROM {$this->tabella} WHERE chiave = ? AND canale = ? AND lingua = ? "
            . 'ORDER BY versione DESC LIMIT 1');
        $s->execute([$chiave, $canale, $lingua]);
        $r = $s->fetch(\PDO::FETCH_ASSOC);
        return $r ? new Modello($chiave, $canale, $lingua, json_decode($r['parti'], true), (int) $r['versione']) : null;
    }

    public function versione(string $chiave, string $canale, string $lingua, int $versione): ?Modello {
        $s = $this->pdo->prepare("SELECT parti FROM {$this->tabella} WHERE chiave = ? AND canale = ? AND lingua = ? AND versione = ?");
        $s->execute([$chiave, $canale, $lingua, $versione]);
        $p = $s->fetchColumn();
        return $p === false ? null : new Modello($chiave, $canale, $lingua, json_decode($p, true), $versione);
    }

    public function versioni(string $chiave, string $canale, string $lingua): array {
        $s = $this->pdo->prepare("SELECT versione, autore, nota, creato FROM {$this->tabella} WHERE chiave = ? AND canale = ? "
            . 'AND lingua = ? ORDER BY versione DESC');
        $s->execute([$chiave, $canale, $lingua]);
        return array_map(fn($r) => ['versione' => (int) $r['versione'], 'autore' => $r['autore'], 'nota' => $r['nota'],
            'creato' => (int) $r['creato']], $s->fetchAll(\PDO::FETCH_ASSOC));
    }
}
