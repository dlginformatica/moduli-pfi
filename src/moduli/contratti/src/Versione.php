<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Contratti;

/**
 * Versione semantica MAGGIORE.MINORE.CORREZIONE e vincoli nella forma di Composer: «1.2.3», «^1.2», «~1.2», «>=1.0 <2.0»,
 * «*» e alternative separate da «||».
 */
final class Versione {

    public function __construct(public readonly int $maggiore, public readonly int $minore, public readonly int $correzione) {
    }

    /**
     * Legge una versione «M.m.c» (anche «M.m» o «M», completate con zeri).
     */
    public static function da_testo(string $testo): self {
        if (!preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', trim($testo), $m)) {
            throw new Eccezione("Versione non valida: «{$testo}»");
        }
        return new self((int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0));
    }

    public function __toString(): string {
        return "{$this->maggiore}.{$this->minore}.{$this->correzione}";
    }

    /**
     * Confronto: negativo, zero o positivo come strcmp.
     */
    public function confronta(self $altra): int {
        return [$this->maggiore, $this->minore, $this->correzione] <=> [$altra->maggiore, $altra->minore, $altra->correzione];
    }

    /**
     * La versione soddisfa il vincolo.
     */
    public function soddisfa(string $vincolo): bool {
        foreach (explode('||', $vincolo) as $alternativa) {
            $parti = preg_split('/\s+/', trim($alternativa), -1, PREG_SPLIT_NO_EMPTY);
            if (!$parti) {
                continue;
            }
            $tutte = true;
            foreach ($parti as $p) {
                if (!$this->soddisfa_semplice($p)) {
                    $tutte = false;
                    break;
                }
            }
            if ($tutte) {
                return true;
            }
        }
        return false;
    }

    private function soddisfa_semplice(string $v): bool {
        if ($v === '*') {
            return true;
        }
        if (!preg_match('/^(\^|~|>=|<=|>|<|=)?(.+)$/', $v, $m)) {
            throw new Eccezione("Vincolo non valido: «{$v}»");
        }
        $op = $m[1];
        $numeri = explode('.', ltrim($m[2], 'v'));
        $base = self::da_testo($m[2]);
        $c = $this->confronta($base);
        switch ($op) {
            case '^':
                // Compatibile: stessa maggiore (o stessa minore se la maggiore è 0).
                if ($base->maggiore > 0) {
                    return $c >= 0 && $this->maggiore === $base->maggiore;
                }
                return $c >= 0 && $this->maggiore === 0 && $this->minore === $base->minore;
            case '~':
                // «~1.2» ammette 1.x con x ≥ 2; «~1.2.3» ammette 1.2.x con x ≥ 3.
                if (count($numeri) >= 3) {
                    return $c >= 0 && $this->maggiore === $base->maggiore && $this->minore === $base->minore;
                }
                return $c >= 0 && $this->maggiore === $base->maggiore;
            case '>=':
                return $c >= 0;
            case '<=':
                return $c <= 0;
            case '>':
                return $c > 0;
            case '<':
                return $c < 0;
            default:
                return $c === 0;
        }
    }
}
