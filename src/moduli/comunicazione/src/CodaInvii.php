<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione;

use Pfi\Contratti\Eccezione;

/**
 * Coda e registro degli invii (proposta 49 § 3.4) in una tabella via PDO; nel portale la tabella è dichiarata da
 * tool_pficomunicazione.
 *
 * Ogni riga è un invio a un destinatario: canale, modello e versione, lingua, destinatario pseudonimizzato (in chiaro
 * solo se la configurazione lo chiede), stato («in_coda», «in_invio», «inviato», «fallito»), tentativi, prossimo
 * tentativo, identificativo del fornitore, esito senza segreti, correlazione. Il contenuto del messaggio resta solo
 * finché serve all'invio: dopo l'esito finale si cancella (minimizzazione).
 */
final class CodaInvii implements Coda {

    public const STATI = ['in_coda', 'in_invio', 'inviato', 'fallito'];

    public function __construct(private readonly \PDO $pdo, private readonly string $tabella = 'pfi_invii') {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $tabella)) {
            throw new Eccezione('Nome di tabella degli invii non valido.');
        }
    }

    public function crea_tabella(): void {
        $id = match ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'pgsql' => 'BIGSERIAL PRIMARY KEY',
            'mysql' => 'BIGINT AUTO_INCREMENT PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
        $t = $this->tabella;
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$t} (id {$id}, correlazione VARCHAR(40) NOT NULL, canale VARCHAR(31) NOT NULL, "
            . "configurazione VARCHAR(60) NOT NULL DEFAULT '', modello VARCHAR(100) NOT NULL, versione INTEGER NOT NULL, "
            . "lingua VARCHAR(5) NOT NULL, destinatario VARCHAR(40) NOT NULL, inchiaro VARCHAR(255), contenuto TEXT, "
            . "stato VARCHAR(10) NOT NULL, tentativi INTEGER NOT NULL DEFAULT 0, prossimo BIGINT NOT NULL, idfornitore VARCHAR(255) NOT NULL DEFAULT '', "
            . "esito VARCHAR(255) NOT NULL DEFAULT '', creato BIGINT NOT NULL, aggiornato BIGINT NOT NULL)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$t}_stato ON {$t} (stato, prossimo)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$t}_correlazione ON {$t} (correlazione)");
    }

    public function accoda(array $riga): int {
        $adesso = time();
        $riga += ['configurazione' => '', 'inchiaro' => null, 'stato' => 'in_coda', 'tentativi' => 0, 'prossimo' => $adesso,
            'idfornitore' => '', 'esito' => '', 'creato' => $adesso, 'aggiornato' => $adesso];
        $campi = ['correlazione', 'canale', 'configurazione', 'modello', 'versione', 'lingua', 'destinatario', 'inchiaro', 'contenuto',
            'stato', 'tentativi', 'prossimo', 'idfornitore', 'esito', 'creato', 'aggiornato'];
        $s = $this->pdo->prepare("INSERT INTO {$this->tabella} (" . implode(', ', $campi) . ') VALUES ('
            . implode(', ', array_fill(0, count($campi), '?')) . ')');
        $s->execute(array_map(fn($c) => $riga[$c], $campi));
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Prende fino a $massimo invii scaduti e li segna «in_invio»; gli invii rimasti «in_invio» da oltre 15 minuti
     * (processo interrotto) tornano in coda.
     *
     * @return list<array<string,mixed>>
     */
    public function prendi(int $massimo, ?int $adesso = null): array {
        $adesso ??= time();
        $this->pdo->prepare("UPDATE {$this->tabella} SET stato = 'in_coda' WHERE stato = 'in_invio' AND aggiornato < ?")
            ->execute([$adesso - 900]);
        $s = $this->pdo->prepare("SELECT * FROM {$this->tabella} WHERE stato = 'in_coda' AND prossimo <= ? ORDER BY prossimo, id LIMIT "
            . max(1, min($massimo, 1000)));
        $s->execute([$adesso]);
        $presi = [];
        $u = $this->pdo->prepare("UPDATE {$this->tabella} SET stato = 'in_invio', aggiornato = ? WHERE id = ? AND stato = 'in_coda'");
        foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $u->execute([$adesso, $r['id']]);
            if ($u->rowCount() === 1) {
                $presi[] = $r;
            }
        }
        return $presi;
    }

    /** Prende un singolo invio in coda (invio immediato), o null se un altro processo l'ha già preso. */
    public function prendi_uno(int $id): ?array {
        $u = $this->pdo->prepare("UPDATE {$this->tabella} SET stato = 'in_invio', aggiornato = ? WHERE id = ? AND stato = 'in_coda'");
        $u->execute([time(), $id]);
        return $u->rowCount() === 1 ? $this->riga($id) : null;
    }

    public function esito(int $id, string $stato, int $tentativi, int $prossimo, string $idfornitore, string $esito, bool $cancellacontenuto): void {
        if (!in_array($stato, self::STATI, true)) {
            throw new Eccezione('Stato di invio sconosciuto.');
        }
        $sql = "UPDATE {$this->tabella} SET stato = ?, tentativi = ?, prossimo = ?, idfornitore = ?, esito = ?, aggiornato = ?"
            . ($cancellacontenuto ? ', contenuto = NULL' : '') . ' WHERE id = ?';
        $this->pdo->prepare($sql)->execute([$stato, $tentativi, $prossimo, mb_substr($idfornitore, 0, 255), mb_substr($esito, 0, 255), time(), $id]);
    }

    public function riga(int $id): ?array {
        $s = $this->pdo->prepare("SELECT * FROM {$this->tabella} WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Ricerca per la pagina del registro degli invii.
     *
     * @param array{canale?:string,stato?:string,modello?:string,correlazione?:string,destinatario?:string,dal?:int,al?:int} $filtri
     * @return list<array<string,mixed>> righe senza il contenuto
     */
    public function cerca(array $filtri = [], int $limite = 100, int $da = 0): array {
        $c = [];
        $p = [];
        foreach (['canale', 'stato', 'modello', 'correlazione', 'destinatario'] as $k) {
            if (!empty($filtri[$k])) {
                $c[] = "{$k} = ?";
                $p[] = $filtri[$k];
            }
        }
        if (isset($filtri['dal'])) {
            $c[] = 'creato >= ?';
            $p[] = (int) $filtri['dal'];
        }
        if (isset($filtri['al'])) {
            $c[] = 'creato <= ?';
            $p[] = (int) $filtri['al'];
        }
        $campi = 'id, correlazione, canale, configurazione, modello, versione, lingua, destinatario, inchiaro, stato, tentativi, '
            . 'prossimo, idfornitore, esito, creato, aggiornato';
        $s = $this->pdo->prepare("SELECT {$campi} FROM {$this->tabella}" . ($c ? ' WHERE ' . implode(' AND ', $c) : '')
            . ' ORDER BY creato DESC, id DESC LIMIT ' . max(1, min($limite, 5000)) . ' OFFSET ' . max(0, $da));
        $s->execute($p);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Elimina le righe con esito finale più vecchie di $giorni; restituisce quante. */
    public function conserva(int $giorni, ?int $adesso = null): int {
        if ($giorni < 1) {
            throw new Eccezione('La conservazione degli invii è di almeno un giorno.');
        }
        $s = $this->pdo->prepare("DELETE FROM {$this->tabella} WHERE stato IN ('inviato', 'fallito') AND aggiornato < ?");
        $s->execute([($adesso ?? time()) - $giorni * 86400]);
        return $s->rowCount();
    }
}
