<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esportazione\Formati;

use Pfi\Contratti\Eccezione;
use Pfi\Esportazione\Base;

/**
 * Testo delimitato: CSV (RFC 4180), TSV e separatore libero, con delimitatore di testo, codifica, fine riga e segno
 * BOM scelti. Con $protezione (predefinita) un valore che inizia con «=», «+», «-», «@», tabulazione o ritorno a capo
 * è preceduto da un apostrofo, perché un foglio di calcolo non lo esegua come formula (CSV injection); i numeri
 * negativi veri restano tali.
 */
final class Delimitato extends Base {

    public function __construct(
        private readonly string $separatore = ',',
        private readonly string $delimitatore = '"',
        private readonly string $codifica = 'UTF-8',
        private readonly bool $bom = true,
        private readonly string $fineriga = "\r\n",
        private readonly bool $protezione = true,
        private readonly string $nome = 'csv',
    ) {
        if ($separatore === '' || strlen($separatore) > 4 || strlen($delimitatore) > 1 || $separatore === $delimitatore
            || !in_array($fineriga, ["\r\n", "\n"], true)) {
            throw new Eccezione('Separatore, delimitatore o fine riga non validi.');
        }
        if (!in_array(strtoupper($codifica), array_map('strtoupper', mb_list_encodings()), true)) {
            throw new Eccezione("Codifica sconosciuta: «{$codifica}»");
        }
    }

    public static function csv(string $separatore = ',', string $codifica = 'UTF-8'): self {
        return new self($separatore, '"', $codifica);
    }

    public static function tsv(): self {
        return new self("\t", '', 'UTF-8', false, "\n", true, 'tsv');
    }

    public function nome(): string {
        return $this->nome;
    }

    public function estensione(): string {
        return $this->nome === 'tsv' ? 'tsv' : ($this->separatore === ',' || $this->separatore === ';' ? 'csv' : 'txt');
    }

    public function tipo(): string {
        return $this->nome === 'tsv' ? 'text/tab-separated-values' : 'text/csv';
    }

    protected function apri(): void {
        if ($this->bom && strtoupper($this->codifica) === 'UTF-8') {
            $this->out("\xEF\xBB\xBF");
        }
        $this->linea($this->colonne);
    }

    protected function scrivi_riga(array $valori): void {
        $this->linea($valori);
    }

    private function linea(array $valori): void {
        $celle = [];
        foreach ($valori as $v) {
            $t = self::testo($v);
            if ($this->protezione && !self::numerico($v) && preg_match('/^[=+\-@\t\r]/', $t)) {
                $t = "'" . $t;
            }
            if ($this->delimitatore === '') {
                $t = str_replace([$this->separatore, "\r", "\n"], ' ', $t);
            } elseif (str_contains($t, $this->separatore) || str_contains($t, $this->delimitatore) || preg_match('/[\r\n]/', $t)
                || $t !== trim($t)) {
                $t = $this->delimitatore . str_replace($this->delimitatore, $this->delimitatore . $this->delimitatore, $t) . $this->delimitatore;
            }
            $celle[] = $t;
        }
        $s = implode($this->separatore, $celle) . $this->fineriga;
        if (strtoupper($this->codifica) !== 'UTF-8') {
            $s = mb_convert_encoding($s, $this->codifica, 'UTF-8');
        }
        $this->out($s);
    }

    protected function termina(): void {
    }
}
