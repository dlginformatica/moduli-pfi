<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Modelli;

/**
 * Conservazione dei modelli con le versioni (proposta 49 § 3.5): ogni salvataggio crea una versione nuova, il
 * ripristino salva come nuova una versione precedente, nessuna versione si cancella.
 */
interface Archivio {

    /** Salva le parti come nuova versione e restituisce il modello con il numero di versione. */
    public function salva(Modello $modello, string $autore, string $nota = ''): Modello;

    /** Versione in uso (l'ultima), o null. */
    public function attuale(string $chiave, string $canale, string $lingua): ?Modello;

    public function versione(string $chiave, string $canale, string $lingua, int $versione): ?Modello;

    /** @return list<array{versione:int,autore:string,nota:string,creato:int}> dalla più recente */
    public function versioni(string $chiave, string $canale, string $lingua): array;
}
