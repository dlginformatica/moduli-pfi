<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esportazione\Formati;

use Pfi\Esportazione\Base;

/**
 * RTF 1.9.1 con una tabella: i caratteri non ASCII come \uN? (Unicode con sostituto «?»), le intestazioni in grassetto,
 * larghezze uguali su una pagina A4 orizzontale con margini di 2 cm.
 */
final class Rtf extends Base {

    public function nome(): string {
        return 'rtf';
    }

    public function estensione(): string {
        return 'rtf';
    }

    public function tipo(): string {
        return 'application/rtf';
    }

    public static function rtf(string $s): string {
        $fuori = '';
        foreach (mb_str_split($s, 1, 'UTF-8') as $c) {
            $o = mb_ord($c, 'UTF-8');
            $fuori .= match (true) {
                $c === '\\' || $c === '{' || $c === '}' => '\\' . $c,
                $c === "\n" => '\\line ',
                $c === "\r" => '',
                $c === "\t" => '\\tab ',
                $o < 32 => '',
                $o < 128 => $c,
                $o < 65536 => '\\u' . ($o > 32767 ? $o - 65536 : $o) . '?',
                default => (function() use ($o) {
                    $v = $o - 0x10000;
                    $a = 0xD800 + ($v >> 10);
                    $b = 0xDC00 + ($v & 0x3FF);
                    return '\\u' . ($a - 65536) . '?\\u' . ($b - 65536) . '?';
                })(),
            };
        }
        return $fuori;
    }

    private function riga_rtf(array $valori, bool $intestazione): void {
        $n = max(1, count($valori));
        $larghezza = intdiv(16838 - 2 * 1134, $n);
        $s = '\\trowd\\trgaph70\\trleft0' . ($intestazione ? '\\trhdr' : '');
        for ($i = 1; $i <= $n; $i++) {
            $s .= '\\clbrdrt\\brdrs\\brdrw10\\clbrdrl\\brdrs\\brdrw10\\clbrdrb\\brdrs\\brdrw10\\clbrdrr\\brdrs\\brdrw10\\cellx' . ($larghezza * $i);
        }
        foreach ($valori as $v) {
            $s .= '\\pard\\intbl' . ($intestazione ? '\\b ' : ' ') . self::rtf(self::testo($v)) . ($intestazione ? '\\b0' : '') . '\\cell';
        }
        $this->out($s . "\\row\n");
    }

    protected function apri(): void {
        $this->out("{\\rtf1\\ansi\\ansicpg1252\\deff0\\uc1{\\fonttbl{\\f0\\fswiss Arial;}}\\paperw16838\\paperh11906\\landscape"
            . "\\margl1134\\margr1134\\margt1134\\margb1134\\f0\\fs18\n");
        if ($this->titolo !== '') {
            $this->out('\\pard\\b\\fs24 ' . self::rtf($this->titolo) . "\\b0\\fs18\\par\\par\n");
        }
        $this->riga_rtf($this->colonne, true);
    }

    protected function scrivi_riga(array $valori): void {
        $this->riga_rtf($valori, false);
    }

    protected function termina(): void {
        $this->out("\\pard\\par}\n");
    }
}
