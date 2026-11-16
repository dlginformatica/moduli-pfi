<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esportazione\Formati;

use Pfi\Contratti\Eccezione;
use Pfi\Esportazione\Base;

/**
 * Testo a larghezza fissa: ogni colonna occupa il numero di caratteri indicato, allineata a sinistra o a destra (i
 * numeri a destra se non indicato), riempita con spazi; un valore più lungo si tronca. Intestazioni facoltative.
 */
final class LarghezzaFissa extends Base {

    /**
     * @param list<int> $larghezze
     * @param list<string> $allineamenti «s» o «d» per colonna; vuoto: numeri a destra, testo a sinistra
     */
    public function __construct(
        private readonly array $larghezze,
        private readonly array $allineamenti = [],
        private readonly bool $intestazioni = true,
        private readonly string $fineriga = "\r\n",
    ) {
        foreach ($larghezze as $l) {
            if (!is_int($l) || $l < 1 || $l > 10000) {
                throw new Eccezione('Larghezza di colonna non valida.');
            }
        }
    }

    public function nome(): string {
        return 'fisso';
    }

    public function estensione(): string {
        return 'txt';
    }

    public function tipo(): string {
        return 'text/plain';
    }

    protected function apri(): void {
        if (count($this->larghezze) !== count($this->colonne)) {
            throw new Eccezione('Servono tante larghezze quante colonne.');
        }
        if ($this->intestazioni) {
            $this->linea($this->colonne);
        }
    }

    protected function scrivi_riga(array $valori): void {
        $this->linea($valori);
    }

    private function linea(array $valori): void {
        $s = '';
        foreach ($valori as $i => $v) {
            $t = preg_replace('/[\r\n\t]+/', ' ', self::testo($v));
            $l = $this->larghezze[$i];
            $t = mb_substr($t, 0, $l);
            $spazi = str_repeat(' ', $l - mb_strlen($t));
            $destra = ($this->allineamenti[$i] ?? (self::numerico($v) ? 'd' : 's')) === 'd';
            $s .= $destra ? $spazi . $t : $t . $spazi;
        }
        $this->out($s . $this->fineriga);
    }

    protected function termina(): void {
    }
}
