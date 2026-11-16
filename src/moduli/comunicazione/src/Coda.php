<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione;

/**
 * Coda e registro degli invii: CodaInvii la realizza con PDO; un'applicazione la può realizzare con il proprio accesso
 * al database (nel portale tool_pficomunicazione con le API dei dati di Moodle). Le righe hanno i campi id, correlazione,
 * canale, configurazione, modello, versione, lingua, destinatario, inchiaro, contenuto, stato, tentativi, prossimo,
 * idfornitore, esito, creato, aggiornato.
 */
interface Coda {

    /** @param array<string,mixed> $riga campi obbligatori: correlazione, canale, modello, versione, lingua, destinatario, contenuto */
    public function accoda(array $riga): int;

    /** @return list<array<string,mixed>> invii scaduti presi in carico («in_invio») */
    public function prendi(int $massimo, ?int $adesso = null): array;

    public function prendi_uno(int $id): ?array;

    public function esito(int $id, string $stato, int $tentativi, int $prossimo, string $idfornitore, string $esito, bool $cancellacontenuto): void;

    public function riga(int $id): ?array;

    /** @return list<array<string,mixed>> */
    public function cerca(array $filtri = [], int $limite = 100, int $da = 0): array;

    public function conserva(int $giorni, ?int $adesso = null): int;
}
