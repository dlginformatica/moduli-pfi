<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esecutore;

use Pfi\Contratti\Eccezione;

/**
 * Coda delle richieste fra le pagine del portale (utente del server web) e il servizio pfi-esecutore (root).
 *
 * Cartelle sotto la base: «richieste» (scritta dal portale), «in-corso» e «esiti» (scritte dal servizio, leggibili dal
 * gruppo del server web). Una richiesta è un file JSON «<id>.json» scritto in modo atomico; il servizio la prende
 * spostandola in «in-corso» e scrive l'esito in «esiti». L'identificativo è di 32 caratteri esadecimali.
 */
final class Coda {

    public const MASSIMO = 65536;

    public function __construct(public readonly string $base) {
    }

    public function cartella(string $nome): string {
        return rtrim($this->base, '/') . '/' . $nome;
    }

    /** Crea le cartelle (per le prove e per il kit, che ne imposta poi proprietari e permessi). */
    public function prepara(): void {
        foreach (['richieste', 'in-corso', 'esiti'] as $c) {
            if (!is_dir($this->cartella($c)) && !mkdir($this->cartella($c), 0770, true) && !is_dir($this->cartella($c))) {
                throw new Eccezione('Cartella della coda non creata.');
            }
        }
    }

    public static function valido(string $id): bool {
        return (bool) preg_match('/^[0-9a-f]{32}$/D', $id);
    }

    /** Accoda una richiesta e ne restituisce l'identificativo. */
    public function richiedi(string $operazione, array $parametri, string $richiedente, string $correlazione = ''): string {
        $id = bin2hex(random_bytes(16));
        $json = json_encode(['id' => $id, 'operazione' => $operazione, 'parametri' => $parametri,
            'richiedente' => substr($richiedente, 0, 100), 'correlazione' => substr($correlazione, 0, 40), 'creata' => time()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MASSIMO) {
            throw new Eccezione('Richiesta troppo grande.');
        }
        $tmp = $this->cartella('richieste') . '/.' . $id . '.tmp';
        $vecchia = umask(0007);
        try {
            if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $this->cartella('richieste') . "/{$id}.json")) {
                @unlink($tmp);
                throw new Eccezione('Richiesta non accodata: cartella della coda non scrivibile.');
            }
        } finally {
            umask($vecchia);
        }
        return $id;
    }

    /**
     * Stato di una richiesta: ['stato' => 'in coda'|'in corso'|'sconosciuta'] oppure l'esito scritto dal servizio
     * (stato «eseguita», «non riuscita», «rifiutata», «interrotta»).
     */
    public function esito(string $id): array {
        if (!self::valido($id)) {
            return ['stato' => 'sconosciuta'];
        }
        $f = $this->cartella('esiti') . "/{$id}.json";
        if (is_file($f)) {
            $d = json_decode((string) file_get_contents($f), true);
            if (is_array($d)) {
                return $d;
            }
        }
        if (is_file($this->cartella('in-corso') . "/{$id}.json")) {
            return ['stato' => 'in corso'];
        }
        if (is_file($this->cartella('richieste') . "/{$id}.json")) {
            return ['stato' => 'in coda'];
        }
        return ['stato' => 'sconosciuta'];
    }

    /** Attende l'esito fino a $secondi; restituisce l'ultimo stato letto. */
    public function attendi(string $id, float $secondi, float $intervallo = 0.5): array {
        $fine = microtime(true) + $secondi;
        do {
            $e = $this->esito($id);
            if (!in_array($e['stato'], ['in coda', 'in corso'], true)) {
                return $e;
            }
            usleep((int) ($intervallo * 1e6));
        } while (microtime(true) < $fine);
        return $this->esito($id);
    }
}
