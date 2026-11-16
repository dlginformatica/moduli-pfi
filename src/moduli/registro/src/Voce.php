<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Registro;

/**
 * Evento già pronto per le destinazioni: messaggio con i segnaposto sostituiti e contesto senza segreti.
 */
final class Voce {

    /**
     * @param array<string,scalar|null|array> $contesto dati strutturati già mascherati
     */
    public function __construct(
        public readonly \DateTimeImmutable $quando,
        public readonly string $livello,
        public readonly string $messaggio,
        public readonly string $modulo,
        public readonly string $correlazione,
        public readonly array $contesto = [],
    ) {
    }

    /** Codice di gravità di RFC 5424 (0 emergenza … 7 debug). */
    public function gravita(): int {
        return \Pfi\Contratti\Registro::LIVELLI[$this->livello];
    }

    /** Forma JSON di una riga, usata dai file e dall'esportazione. */
    public function json(): string {
        return json_encode(['quando' => $this->quando->format(DATE_RFC3339_EXTENDED), 'livello' => $this->livello,
            'modulo' => $this->modulo, 'correlazione' => $this->correlazione, 'messaggio' => $this->messaggio,
            'contesto' => $this->contesto], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
