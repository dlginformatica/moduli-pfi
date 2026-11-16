<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Contratti;

/**
 * Esito di una prova di salute: stato, messaggio in italiano senza segreti e dettagli strutturati facoltativi.
 */
final class EsitoSalute {

    public const BUONO = 'buono';
    public const AVVISO = 'avviso';
    public const GUASTO = 'guasto';

    /**
     * @param array<string,scalar|null> $dettagli
     */
    public function __construct(public readonly string $stato, public readonly string $messaggio, public readonly array $dettagli = []) {
        if (!in_array($stato, [self::BUONO, self::AVVISO, self::GUASTO], true)) {
            throw new Eccezione("Stato di salute sconosciuto: «{$stato}»");
        }
    }

    public static function buono(string $messaggio, array $dettagli = []): self {
        return new self(self::BUONO, $messaggio, $dettagli);
    }

    public static function avviso(string $messaggio, array $dettagli = []): self {
        return new self(self::AVVISO, $messaggio, $dettagli);
    }

    public static function guasto(string $messaggio, array $dettagli = []): self {
        return new self(self::GUASTO, $messaggio, $dettagli);
    }
}
