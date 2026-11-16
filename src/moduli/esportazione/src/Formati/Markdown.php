<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esportazione\Formati;

use Pfi\Esportazione\Base;

/** Tabella Markdown (stile GitHub): «|» protetto con «\», a capo sostituiti da <br>, numeri allineati a destra. */
final class Markdown extends Base {

    public function nome(): string {
        return 'md';
    }

    public function estensione(): string {
        return 'md';
    }

    public function tipo(): string {
        return 'text/markdown';
    }

    private static function cella(mixed $v): string {
        return str_replace(['\\', '|', "\r\n", "\n", "\r"], ['\\\\', '\\|', '<br>', '<br>', '<br>'], self::testo($v));
    }

    protected function apri(): void {
        if ($this->titolo !== '') {
            $this->out('# ' . str_replace(["\r", "\n"], ' ', $this->titolo) . "\n\n");
        }
        $this->out('| ' . implode(' | ', array_map([self::class, 'cella'], $this->colonne)) . " |\n");
        $this->out('|' . str_repeat('---|', count($this->colonne)) . "\n");
    }

    protected function scrivi_riga(array $valori): void {
        $this->out('| ' . implode(' | ', array_map([self::class, 'cella'], $valori)) . " |\n");
    }

    protected function termina(): void {
    }
}
