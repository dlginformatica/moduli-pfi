<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Registro;

use Pfi\Contratti\EsitoSalute;

/**
 * Destinazione degli eventi (tabella, file, syslog, memoria). Una destinazione non deve lanciare eccezioni per un evento
 * non scritto se può evitarlo; se le lancia, il registro le intercetta e prosegue con le altre destinazioni.
 */
interface Destinazione {

    /** Nome breve per la configurazione e i messaggi, per esempio «file» o «syslog tcp». */
    public function nome(): string;

    public function scrivi(Voce $voce): void;

    /** Stato della destinazione per la diagnostica, senza scrivere eventi. */
    public function salute(): EsitoSalute;
}
