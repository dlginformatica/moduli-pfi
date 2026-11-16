<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esecutore;

use Pfi\Contratti\Eccezione;

/**
 * Elenco chiuso delle operazioni ammesse. In esercizio si legge da un file JSON di proprietà di root e non scrivibile da
 * altri (per esempio /etc/pfi/esecutore.json): {"operazioni": {"nome": {"descrizione", "comando", "parametri",
 * "tempomassimo"}}}. Un file con proprietario o permessi diversi si rifiuta.
 */
final class Catalogo {

    /** @var array<string,Operazione> */
    private array $operazioni = [];

    public function aggiungi(Operazione $o): self {
        $this->operazioni[$o->nome] = $o;
        return $this;
    }

    public function operazione(string $nome): Operazione {
        return $this->operazioni[$nome] ?? throw new Eccezione('Operazione non ammessa: «' . substr($nome, 0, 64) . '».');
    }

    /** @return array<string,Operazione> */
    public function operazioni(): array {
        return $this->operazioni;
    }

    public static function da_file(string $file, bool $controllaproprietario = true): self {
        clearstatcache(true, $file);
        if (!is_file($file) || is_link($file)) {
            throw new Eccezione('Catalogo delle operazioni assente.');
        }
        $st = stat($file);
        if ($controllaproprietario && ($st['uid'] !== 0 || ($st['mode'] & 0022) !== 0)) {
            throw new Eccezione('Il catalogo delle operazioni deve appartenere a root e non essere scrivibile da altri.');
        }
        return self::da_json((string) file_get_contents($file));
    }

    public static function da_json(string $json): self {
        try {
            $d = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new Eccezione('Catalogo delle operazioni non leggibile: ' . $e->getMessage());
        }
        $c = new self();
        foreach ($d['operazioni'] ?? [] as $nome => $o) {
            $c->aggiungi(Operazione::da_array((string) $nome, (array) $o));
        }
        return $c;
    }
}
