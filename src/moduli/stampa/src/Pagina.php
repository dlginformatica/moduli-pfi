<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Stampa;

use Pfi\Contratti\Eccezione;

/**
 * Impostazioni di pagina di un modello di stampa (proposta 49 § 3.7): formato, orientamento, margini in millimetri,
 * intestazione e piè di pagina di testo con i segnaposto {pagina} e {pagine}, resi con le caselle dei margini di CSS
 * (@page) che Chromium impagina.
 */
final class Pagina {

    public const FORMATI = ['A4', 'A3', 'A5', 'letter', 'legal'];

    /** @param array{0:int,1:int,2:int,3:int} $margini alto, destra, basso, sinistra in mm */
    public function __construct(
        public readonly string $formato = 'A4',
        public readonly bool $orizzontale = false,
        public readonly array $margini = [20, 15, 20, 15],
        public readonly string $intestazione = '',
        public readonly string $piedipagina = 'Pagina {pagina} di {pagine}',
    ) {
        if (!in_array($formato, self::FORMATI, true) || count($margini) !== 4) {
            throw new Eccezione('Formato o margini della pagina non validi.');
        }
        foreach ($margini as $m) {
            if (!is_int($m) || $m < 0 || $m > 100) {
                throw new Eccezione('Margine della pagina fuori dall\'intervallo 0-100 mm.');
            }
        }
    }

    /** Valore CSS di content per un testo con i segnaposto di pagina. */
    private static function contenuto(string $t): string {
        $parti = preg_split('/(\{pagina\}|\{pagine\})/', $t, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        return implode(' ', array_map(fn($p) => match ($p) {
            '{pagina}' => 'counter(page)',
            '{pagine}' => 'counter(pages)',
            default => '"' . addcslashes(str_replace(["\r", "\n"], ' ', $p), '"\\') . '"',
        }, $parti ?: ['""']));
    }

    public function css(): string {
        [$a, $d, $b, $s] = $this->margini;
        $css = '@page{size:' . $this->formato . ($this->orizzontale ? ' landscape' : '') . ";margin:{$a}mm {$d}mm {$b}mm {$s}mm;";
        if ($this->intestazione !== '') {
            $css .= '@top-center{content:' . self::contenuto($this->intestazione) . ';font-size:8pt;color:#555;font-family:"DejaVu Sans",Arial,sans-serif}';
        }
        if ($this->piedipagina !== '') {
            $css .= '@bottom-center{content:' . self::contenuto($this->piedipagina) . ';font-size:8pt;color:#555;font-family:"DejaVu Sans",Arial,sans-serif}';
        }
        return $css . '}';
    }
}
