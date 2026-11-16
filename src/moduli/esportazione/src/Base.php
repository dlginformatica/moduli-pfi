<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esportazione;

use Pfi\Contratti\Eccezione;

/** Base comune: controllo del numero dei valori e conversione dei valori in testo. */
abstract class Base implements Formato {

    /** @var resource|null */
    protected $uscita = null;

    /** @var list<string> */
    protected array $colonne = [];

    protected string $titolo = '';

    protected int $righe = 0;

    public function inizia($uscita, array $colonne, string $titolo = ''): void {
        if (!is_resource($uscita)) {
            throw new Eccezione('Uscita dell\'esportazione non valida.');
        }
        $this->uscita = $uscita;
        $this->colonne = array_map(fn($c) => self::testo($c), array_values($colonne));
        $this->titolo = $titolo;
        $this->righe = 0;
        $this->apri();
    }

    public function riga(array $valori): void {
        if ($this->uscita === null) {
            throw new Eccezione('Esportazione non iniziata.');
        }
        $valori = array_values($valori);
        if (count($valori) !== count($this->colonne)) {
            throw new Eccezione('Riga ' . ($this->righe + 1) . ': ' . count($valori) . ' valori per ' . count($this->colonne) . ' colonne.');
        }
        $this->righe++;
        $this->scrivi_riga($valori);
    }

    public function chiudi(): void {
        if ($this->uscita !== null) {
            $this->termina();
            $this->uscita = null;
        }
    }

    abstract protected function apri(): void;

    /** @param list<mixed> $valori */
    abstract protected function scrivi_riga(array $valori): void;

    abstract protected function termina(): void;

    protected function out(string $s): void {
        if (fwrite($this->uscita, $s) !== strlen($s)) {
            throw new Eccezione('Scrittura dell\'esportazione non riuscita (spazio o flusso chiuso).');
        }
    }

    /** Valore in testo: date in ISO 8601, booleani 1 e 0, null vuoto, numeri senza notazione scientifica. */
    public static function testo(mixed $v): string {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? '1' : '0',
            is_float($v) => rtrim(rtrim(number_format($v, 10, '.', ''), '0'), '.'),
            $v instanceof \DateTimeInterface => $v->format('Y-m-d\TH:i:sP'),
            is_scalar($v), $v instanceof \Stringable => (string) $v,
            default => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        };
    }

    /** Testo XML 1.0: caratteri di controllo non ammessi tolti, escape di & < > " '. */
    protected static function xml(string $s): string {
        return htmlspecialchars(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '',
            ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected static function numerico(mixed $v): bool {
        return (is_int($v) || is_float($v)) && is_finite((float) $v);
    }
}
