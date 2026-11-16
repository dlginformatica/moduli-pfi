<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esportazione\Formati;

use Pfi\Esportazione\Base;

/** JSON: elenco di oggetti con le colonne come chiavi; i numeri e i booleani restano tali. */
final class Json extends Base {

    public function nome(): string {
        return 'json';
    }

    public function estensione(): string {
        return 'json';
    }

    public function tipo(): string {
        return 'application/json';
    }

    protected function apri(): void {
        $this->out('[');
    }

    protected function scrivi_riga(array $valori): void {
        $o = [];
        foreach ($this->colonne as $i => $c) {
            $v = $valori[$i];
            $o[$c] = is_int($v) || is_float($v) || is_bool($v) || $v === null ? $v : self::testo($v);
        }
        $this->out(($this->righe > 1 ? ",\n" : "\n") . json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION));
    }

    protected function termina(): void {
        $this->out("\n]\n");
    }
}
