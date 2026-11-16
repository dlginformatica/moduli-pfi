<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Registro\Destinazioni;

use Pfi\Contratti\Eccezione;
use Pfi\Contratti\EsitoSalute;
use Pfi\Registro\Destinazione;
use Pfi\Registro\Voce;

/**
 * Tabella di un database raggiunto con PDO (PostgreSQL, MySQL o MariaDB, SQLite). Fuori da Moodle si crea la tabella con
 * crea_tabella(); nel portale la tabella è dichiarata da tool_pfiregistro e la scrittura passa dall'adattatore.
 *
 * Colonne: id, quando (secondi Unix), livello, gravita, modulo, correlazione, messaggio, contesto (JSON).
 */
final class Tabella implements Destinazione {

    public function __construct(private readonly \PDO $pdo, private readonly string $tabella = 'pfi_registro') {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $tabella)) {
            throw new Eccezione('Nome di tabella del registro non valido.');
        }
    }

    public function nome(): string {
        return 'tabella';
    }

    public function crea_tabella(): void {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $id = match ($driver) {
            'pgsql' => 'BIGSERIAL PRIMARY KEY',
            'mysql' => 'BIGINT AUTO_INCREMENT PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
        $t = $this->tabella;
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$t} (id {$id}, quando BIGINT NOT NULL, livello VARCHAR(10) NOT NULL, "
            . "gravita SMALLINT NOT NULL, modulo VARCHAR(100) NOT NULL, correlazione VARCHAR(40) NOT NULL, "
            . "messaggio TEXT NOT NULL, contesto TEXT)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$t}_quando ON {$t} (quando)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$t}_correlazione ON {$t} (correlazione)");
    }

    public function scrivi(Voce $voce): void {
        $s = $this->pdo->prepare("INSERT INTO {$this->tabella} (quando, livello, gravita, modulo, correlazione, messaggio, contesto) "
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)');
        $s->execute([$voce->quando->getTimestamp(), $voce->livello, $voce->gravita(), substr($voce->modulo, 0, 100),
            substr($voce->correlazione, 0, 40), $voce->messaggio,
            $voce->contesto ? json_encode($voce->contesto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]);
    }

    /** Elimina gli eventi più vecchi di $giorni; restituisce quanti. */
    public function conserva(int $giorni, ?int $adesso = null): int {
        if ($giorni < 1) {
            throw new Eccezione('La conservazione del registro è di almeno un giorno.');
        }
        $s = $this->pdo->prepare("DELETE FROM {$this->tabella} WHERE quando < ?");
        $s->execute([($adesso ?? time()) - $giorni * 86400]);
        return $s->rowCount();
    }

    /**
     * Ricerca per la pagina di consultazione.
     *
     * @param array{modulo?:string,livello?:string,dal?:int,al?:int,correlazione?:string,testo?:string} $filtri
     * @return list<array<string,mixed>>
     */
    public function cerca(array $filtri = [], int $limite = 100, int $da = 0): array {
        [$dove, $parametri] = self::condizioni($filtri);
        $limite = max(1, min($limite, 5000));
        $da = max(0, $da);
        $s = $this->pdo->prepare("SELECT * FROM {$this->tabella}{$dove} ORDER BY quando DESC, id DESC LIMIT {$limite} OFFSET {$da}");
        $s->execute($parametri);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array{0:string,1:list<mixed>} */
    public static function condizioni(array $filtri): array {
        $c = [];
        $p = [];
        if (!empty($filtri['modulo'])) {
            $c[] = 'modulo = ?';
            $p[] = $filtri['modulo'];
        }
        if (!empty($filtri['livello'])) {
            $gravita = \Pfi\Contratti\Registro::LIVELLI[$filtri['livello']] ?? null;
            if ($gravita === null) {
                throw new Eccezione('Livello sconosciuto nel filtro.');
            }
            $c[] = 'gravita <= ?';
            $p[] = $gravita;
        }
        if (isset($filtri['dal'])) {
            $c[] = 'quando >= ?';
            $p[] = (int) $filtri['dal'];
        }
        if (isset($filtri['al'])) {
            $c[] = 'quando <= ?';
            $p[] = (int) $filtri['al'];
        }
        if (!empty($filtri['correlazione'])) {
            $c[] = 'correlazione = ?';
            $p[] = $filtri['correlazione'];
        }
        if (!empty($filtri['testo'])) {
            $c[] = 'messaggio LIKE ?';
            $p[] = '%' . strtr($filtri['testo'], ['%' => '', '_' => '']) . '%';
        }
        return [$c ? ' WHERE ' . implode(' AND ', $c) : '', $p];
    }

    public function salute(): EsitoSalute {
        try {
            $this->pdo->query("SELECT COUNT(*) FROM {$this->tabella} WHERE 1 = 0");
            return EsitoSalute::buono('Tabella del registro presente.', ['tabella' => $this->tabella]);
        } catch (\Throwable $e) {
            return EsitoSalute::guasto('Tabella del registro non leggibile.', ['tabella' => $this->tabella]);
        }
    }
}
