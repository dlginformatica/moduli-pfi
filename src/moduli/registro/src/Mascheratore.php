<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Registro;

/**
 * Mascheramento dei segreti prima della scrittura (proposta 49 § 3.3).
 *
 * Tre livelli: a) valori del contesto con chiave sensibile (password, token, chiave, segreto, autorizzazione, cookie…);
 * b) forme riconoscibili nel testo («password=…», «Authorization: …», «Bearer …», credenziali in un indirizzo);
 * c) valori noti registrati con valore_segreto(), sostituiti ovunque compaiano (per esempio i segreti letti dal deposito).
 */
final class Mascheratore {

    public const MASCHERA = '[MASCHERATO]';

    private const CHIAVI = '/pass(word|wd|phrase)?|pwd|secret|segret|token|chiave|key|authori[sz]ation|cookie|credenzial|'
        . 'firma|signature|sessione|session|private|privat|bearer/i';

    /** @var list<string> */
    private array $valori = [];

    /** Registra un valore da non scrivere mai (lunghezza minima 6, per non mascherare parole comuni). */
    public function valore_segreto(string $valore): void {
        $valore = trim($valore);
        if (strlen($valore) >= 6 && !in_array($valore, $this->valori, true)) {
            $this->valori[] = $valore;
            usort($this->valori, fn($a, $b) => strlen($b) <=> strlen($a));
        }
    }

    public function chiave_sensibile(string $chiave): bool {
        return (bool) preg_match(self::CHIAVI, $chiave);
    }

    public function testo(string $testo): string {
        foreach ($this->valori as $v) {
            $testo = str_replace($v, self::MASCHERA, $testo);
        }
        $m = self::MASCHERA;
        $testo = preg_replace('~(Authorization|Proxy-Authorization|Cookie|Set-Cookie|X-Api-Key)\s*:\s*[^\r\n]+~i', "$1: $m", $testo);
        $testo = preg_replace('#\b(Bearer|Basic)\s+[A-Za-z0-9._~+/=-]{8,}#', "$1 $m", $testo);
        $testo = preg_replace('~\b((?:pass(?:word|wd|phrase)?|pwd|secret|segreto|token|api[_-]?key|chiave|access[_-]?key|client[_-]?secret)'
            . '["\']?\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s&,;]+)~i', "$1$m", $testo);
        $testo = preg_replace('~([a-z][a-z0-9+.-]*://[^:/@\s]+):[^@\s/]+@~i', "$1:$m@", $testo);
        return $testo;
    }

    /**
     * Contesto pronto per la scrittura: valori scalari o liste di scalari, chiavi sensibili mascherate, eccezioni ridotte a
     * classe e messaggio mascherato, oggetti ridotti alla classe.
     *
     * @param array<string,mixed> $contesto
     * @return array<string,mixed>
     */
    public function contesto(array $contesto, int $profondita = 0): array {
        $fuori = [];
        foreach ($contesto as $k => $v) {
            $k = (string) $k;
            if ($this->chiave_sensibile($k) && $v !== null && $v !== '') {
                $fuori[$k] = self::MASCHERA;
            } else {
                $fuori[$k] = $this->valore($v, $profondita);
            }
        }
        return $fuori;
    }

    private function valore(mixed $v, int $profondita): mixed {
        if ($v === null || is_bool($v) || is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_string($v)) {
            return $this->testo($v);
        }
        if ($v instanceof \Throwable) {
            return get_class($v) . ': ' . $this->testo($v->getMessage());
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format(DATE_RFC3339);
        }
        if ($v instanceof \Stringable) {
            return $this->testo((string) $v);
        }
        if (is_array($v)) {
            return $profondita >= 3 ? '[…]' : $this->contesto($v, $profondita + 1);
        }
        return is_object($v) ? '[' . get_class($v) . ']' : '[' . gettype($v) . ']';
    }
}
