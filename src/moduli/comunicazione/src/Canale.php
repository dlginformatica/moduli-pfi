<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione;

use Pfi\Contratti\EsitoSalute;

/**
 * Canale di comunicazione (proposta 49 § 3.4): posta (SMTP o API di un fornitore), notifica del portale, Telegram,
 * WhatsApp. L'invio restituisce l'esito o lancia ErroreInvio, temporaneo (si ritenta dalla coda) o definitivo.
 */
interface Canale {

    /** Nome del tipo di canale: «posta», «notifica», «telegram», «whatsapp». */
    public function tipo(): string;

    /** Nome della configurazione per il registro, per esempio «posta smtp» o «posta postmark». */
    public function nome(): string;

    public function invia(Messaggio $messaggio): Invio;

    /** Prova della configurazione senza inviare messaggi (connessione, autenticazione dove possibile). */
    public function salute(): EsitoSalute;
}
