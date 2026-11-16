<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione\Ricezione;

use Pfi\Contratti\EsitoSalute;

/**
 * Casella di posta di servizio in ricezione (proposta 49 § 3.4): risposte alle comunicazioni, rimbalzi, richieste.
 */
interface Casella {

    /**
     * Messaggi non ancora trattati, al massimo $massimo; con $segna vero li segna come trattati (letti in IMAP,
     * eliminati in POP3 solo se la configurazione lo prevede).
     *
     * @return list<Ricevuto>
     */
    public function ricevi(int $massimo = 50, bool $segna = true): array;

    public function salute(): EsitoSalute;
}
