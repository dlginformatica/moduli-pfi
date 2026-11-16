<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Modelli\Archivi;

use Pfi\Modelli\Archivio;
use Pfi\Modelli\Modello;

/** Archivio in memoria, per le prove e per le applicazioni senza database. */
final class Memoria implements Archivio {

    /** @var array<string,list<array{modello:Modello,autore:string,nota:string,creato:int}>> */
    private array $voci = [];

    private static function k(string $c, string $ca, string $l): string {
        return "{$c}|{$ca}|{$l}";
    }

    public function salva(Modello $m, string $autore, string $nota = ''): Modello {
        $k = self::k($m->chiave, $m->canale, $m->lingua);
        $n = count($this->voci[$k] ?? []) + 1;
        $nuovo = new Modello($m->chiave, $m->canale, $m->lingua, $m->parti, $n);
        $this->voci[$k][] = ['modello' => $nuovo, 'autore' => $autore, 'nota' => $nota, 'creato' => time()];
        return $nuovo;
    }

    public function attuale(string $chiave, string $canale, string $lingua): ?Modello {
        $v = $this->voci[self::k($chiave, $canale, $lingua)] ?? [];
        return $v ? end($v)['modello'] : null;
    }

    public function versione(string $chiave, string $canale, string $lingua, int $versione): ?Modello {
        return $this->voci[self::k($chiave, $canale, $lingua)][$versione - 1]['modello'] ?? null;
    }

    public function versioni(string $chiave, string $canale, string $lingua): array {
        $fuori = [];
        foreach ($this->voci[self::k($chiave, $canale, $lingua)] ?? [] as $v) {
            $fuori[] = ['versione' => $v['modello']->versione, 'autore' => $v['autore'], 'nota' => $v['nota'], 'creato' => $v['creato']];
        }
        return array_reverse($fuori);
    }
}
