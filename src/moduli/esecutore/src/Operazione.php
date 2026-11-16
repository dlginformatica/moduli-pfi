<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esecutore;

use Pfi\Contratti\Eccezione;

/**
 * Operazione privilegiata ammessa (proposta 49 § 3.10): comando con argomenti fissi e parametri controllati.
 *
 * Gli argomenti del comando sono un elenco (mai una riga di shell). Un elemento «{nome}» diventa il valore del
 * parametro, un elemento «{?nome:testo}» diventa «testo» se il parametro booleano è vero e scompare se è falso; ogni
 * altro elemento resta letterale. Un valore non si incolla mai dentro un altro argomento.
 *
 * Tipi dei parametri: testo (con «modello», espressione regolare obbligatoria), intero («min», «max»), scelta
 * («valori»), booleano, percorso («radice»: il percorso reale deve stare sotto la radice, senza collegamenti che ne
 * escano). «obbligatorio» vale vero se non indicato; i booleani mancanti sono falsi.
 */
final class Operazione {

    /**
     * @param list<string> $comando
     * @param array<string,array<string,mixed>> $parametri
     */
    public function __construct(
        public readonly string $nome,
        public readonly string $descrizione,
        public readonly array $comando,
        public readonly array $parametri = [],
        public readonly int $tempomassimo = 600,
        public readonly int $uscitamassima = 65536,
    ) {
        if (!preg_match('/^[a-z][a-z0-9.-]{1,63}$/', $nome)) {
            throw new Eccezione("Nome di operazione non valido: «{$nome}»");
        }
        if (!$comando || !str_starts_with((string) $comando[0], '/')) {
            throw new Eccezione("Operazione {$nome}: il comando deve iniziare con un percorso assoluto.");
        }
        foreach ($parametri as $p => $d) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', (string) $p)) {
                throw new Eccezione("Operazione {$nome}: nome di parametro non valido.");
            }
            $tipo = $d['tipo'] ?? '';
            if (!in_array($tipo, ['testo', 'intero', 'scelta', 'booleano', 'percorso'], true)
                || ($tipo === 'testo' && empty($d['modello'])) || ($tipo === 'scelta' && empty($d['valori']))
                || ($tipo === 'percorso' && empty($d['radice']))) {
                throw new Eccezione("Operazione {$nome}: parametro «{$p}» dichiarato in modo incompleto.");
            }
        }
        foreach ($comando as $a) {
            if (preg_match('/^\{\??([a-z0-9_]+)(:.*)?\}$/', (string) $a, $m) && !isset($parametri[$m[1]])) {
                throw new Eccezione("Operazione {$nome}: il comando usa il parametro non dichiarato «{$m[1]}».");
            }
        }
    }

    public static function da_array(string $nome, array $d): self {
        return new self($nome, (string) ($d['descrizione'] ?? $nome), array_values($d['comando'] ?? []),
            $d['parametri'] ?? [], (int) ($d['tempomassimo'] ?? 600), (int) ($d['uscitamassima'] ?? 65536));
    }

    /**
     * Controlla e normalizza i parametri ricevuti; Eccezione con un messaggio senza i valori.
     *
     * @return array<string,string|int|bool>
     */
    public function controlla(array $ricevuti): array {
        foreach (array_keys($ricevuti) as $k) {
            if (!isset($this->parametri[$k])) {
                throw new Eccezione("Operazione {$this->nome}: parametro non ammesso «" . substr((string) $k, 0, 32) . '».');
            }
        }
        $fuori = [];
        foreach ($this->parametri as $p => $d) {
            $tipo = $d['tipo'];
            if (!array_key_exists($p, $ricevuti)) {
                if ($tipo === 'booleano') {
                    $fuori[$p] = false;
                    continue;
                }
                if ($d['obbligatorio'] ?? true) {
                    throw new Eccezione("Operazione {$this->nome}: manca il parametro «{$p}».");
                }
                continue;
            }
            $v = $ricevuti[$p];
            $fuori[$p] = match ($tipo) {
                'booleano' => is_bool($v) ? $v : throw new Eccezione("Parametro «{$p}»: atteso vero o falso."),
                'intero' => $this->intero($p, $v, $d),
                'scelta' => is_string($v) && in_array($v, $d['valori'], true) ? $v
                    : throw new Eccezione("Parametro «{$p}»: valore non fra quelli ammessi."),
                'testo' => is_string($v) && strlen($v) <= 1024 && !str_contains($v, "\0") && preg_match('~' . str_replace('~', '\~', $d['modello']) . '~D', $v)
                    ? $v : throw new Eccezione("Parametro «{$p}»: formato non ammesso."),
                'percorso' => $this->percorso($p, $v, (string) $d['radice']),
            };
        }
        return $fuori;
    }

    private function intero(string $p, mixed $v, array $d): int {
        if (!is_int($v) || (isset($d['min']) && $v < $d['min']) || (isset($d['max']) && $v > $d['max'])) {
            throw new Eccezione("Parametro «{$p}»: intero fuori dall'intervallo ammesso.");
        }
        return $v;
    }

    private function percorso(string $p, mixed $v, string $radice): string {
        $base = realpath($radice);
        $reale = is_string($v) && !str_contains($v, "\0") ? realpath($v) : false;
        if ($base === false || $reale === false || !str_starts_with($reale, rtrim($base, '/') . '/')) {
            throw new Eccezione("Parametro «{$p}»: percorso inesistente o fuori dalla cartella ammessa.");
        }
        return $reale;
    }

    /**
     * Argomenti del comando con i parametri già controllati.
     *
     * @param array<string,string|int|bool> $valori
     * @return list<string>
     */
    public function argomenti(array $valori): array {
        $fuori = [];
        foreach ($this->comando as $a) {
            $a = (string) $a;
            if (preg_match('/^\{\?([a-z0-9_]+):(.*)\}$/s', $a, $m)) {
                if (!empty($valori[$m[1]])) {
                    $fuori[] = $m[2];
                }
            } elseif (preg_match('/^\{([a-z0-9_]+)\}$/', $a, $m)) {
                if (array_key_exists($m[1], $valori)) {
                    $v = $valori[$m[1]];
                    $fuori[] = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
                }
            } else {
                $fuori[] = $a;
            }
        }
        return $fuori;
    }
}
