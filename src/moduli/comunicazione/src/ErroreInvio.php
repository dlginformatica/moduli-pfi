<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione;

/**
 * Invio non riuscito. Temporaneo: il fornitore non è raggiungibile, limita la frequenza o ha un guasto (si ritenta).
 * Definitivo: destinatario rifiutato, autenticazione o configurazione errate, messaggio non valido (non si ritenta).
 */
final class ErroreInvio extends \Pfi\Contratti\Eccezione {

    public function __construct(string $messaggio, public readonly bool $temporaneo, public readonly string $codice = '') {
        parent::__construct($messaggio);
    }

    /** Classificazione degli stati HTTP delle API: 408, 425, 429 e 5xx temporanei, il resto definitivo. */
    public static function da_stato_http(string $fornitore, int $stato, string $dettaglio = ''): self {
        $temporaneo = $stato === 0 || in_array($stato, [408, 425, 429], true) || $stato >= 500;
        return new self("{$fornitore}: invio non riuscito (" . ($stato ?: 'rete') . ')' . ($dettaglio !== '' ? ": {$dettaglio}" : ''),
            $temporaneo, (string) $stato);
    }
}
