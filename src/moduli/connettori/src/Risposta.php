<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Connettori;

/** Risposta HTTP. Le intestazioni hanno i nomi in minuscolo. */
final class Risposta {

    /**
     * @param array<string,string> $intestazioni
     */
    public function __construct(
        public readonly int $stato,
        public readonly array $intestazioni = [],
        public readonly string $corpo = '',
        public readonly float $durata = 0.0,
        public readonly int $tentativi = 1,
    ) {
    }

    public function riuscita(): bool {
        return $this->stato >= 200 && $this->stato < 300;
    }

    public function intestazione(string $nome): ?string {
        return $this->intestazioni[strtolower($nome)] ?? null;
    }

    /** Corpo JSON decodificato; ErroreConnettore se non è JSON. */
    public function json(): array {
        try {
            $d = json_decode($this->corpo, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ErroreConnettore('La risposta del servizio non è JSON valido.', $this->stato);
        }
        return is_array($d) ? $d : ['valore' => $d];
    }

    public function con_tentativi(int $n): self {
        return new self($this->stato, $this->intestazioni, $this->corpo, $this->durata, $n);
    }
}
