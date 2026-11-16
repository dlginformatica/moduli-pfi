<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Modelli;

/**
 * Motore dei modelli (release 9.0, blocco M3; proposta 49 § 3.5): sottoinsieme della sintassi di Mustache, la stessa
 * dei modelli di Moodle, senza logica nei modelli.
 *
 * Ammessi: {{nome}} (valore con escape HTML nei modelli HTML), {{{nome}}} e {{&nome}} (valore senza escape),
 * {{#nome}}…{{/nome}} (sezione: ripetuta per ogni elemento di un elenco, mostrata se il valore è vero; dentro la sezione
 * i nomi si cercano prima nell'elemento e poi all'esterno), {{^nome}}…{{/nome}} (sezione inversa), {{! commento}},
 * nomi con il punto (persona.nome) e {{.}} per l'elemento corrente.
 * Esclusi, perché eseguirebbero codice o caricherebbero altri file: parziali ({{> …}}), lambda, cambio dei
 * delimitatori. Un modello scritto con questi elementi dà un errore con riga e colonna.
 */
final class Motore {

    /** @return list<array> albero del modello */
    public static function analizza(string $testo): array {
        $radice = [];
        $pila = [[null, &$radice, 0]];
        $pos = 0;
        $n = strlen($testo);
        while ($pos < $n) {
            $apre = strpos($testo, '{{', $pos);
            if ($apre === false) {
                $pila[count($pila) - 1][1][] = ['testo', substr($testo, $pos)];
                break;
            }
            if ($apre > $pos) {
                $pila[count($pila) - 1][1][] = ['testo', substr($testo, $pos, $apre - $pos)];
            }
            $triplo = substr($testo, $apre, 3) === '{{{';
            $chiude = strpos($testo, $triplo ? '}}}' : '}}', $apre + ($triplo ? 3 : 2));
            if ($chiude === false) {
                throw self::errore('Segnaposto non chiuso', $testo, $apre);
            }
            $interno = trim(substr($testo, $apre + ($triplo ? 3 : 2), $chiude - $apre - ($triplo ? 3 : 2)));
            $pos = $chiude + ($triplo ? 3 : 2);
            if ($triplo) {
                $pila[count($pila) - 1][1][] = ['grezzo', self::nome($interno, $testo, $apre)];
                continue;
            }
            $segno = $interno[0] ?? '';
            $resto = trim(substr($interno, 1));
            switch ($segno) {
                case '!':
                    break;
                case '&':
                    $pila[count($pila) - 1][1][] = ['grezzo', self::nome($resto, $testo, $apre)];
                    break;
                case '#':
                case '^':
                    $nodo = [$segno === '#' ? 'sezione' : 'inversa', self::nome($resto, $testo, $apre), []];
                    $figli = &$pila[count($pila) - 1][1];
                    $figli[] = $nodo;
                    $pila[] = [$nodo[1], &$figli[array_key_last($figli)][2], $apre];
                    unset($figli);
                    break;
                case '/':
                    $nome = self::nome($resto, $testo, $apre);
                    if (count($pila) === 1 || $pila[count($pila) - 1][0] !== $nome) {
                        throw self::errore("Chiusura «{$nome}» senza apertura corrispondente", $testo, $apre);
                    }
                    array_pop($pila);
                    break;
                case '>':
                case '<':
                case '=':
                case '$':
                    throw self::errore('Elemento di Mustache non ammesso nei modelli del portale (parziali, blocchi o delimitatori)', $testo, $apre);
                default:
                    $pila[count($pila) - 1][1][] = ['valore', self::nome($interno, $testo, $apre)];
            }
        }
        if (count($pila) > 1) {
            throw self::errore("Sezione «{$pila[count($pila) - 1][0]}» non chiusa", $testo, $pila[count($pila) - 1][2]);
        }
        return $radice;
    }

    private static function nome(string $nome, string $testo, int $pos): string {
        if ($nome === '.' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $nome)) {
            return $nome;
        }
        throw self::errore('Nome di segnaposto non valido «' . mb_substr($nome, 0, 40) . '»', $testo, $pos);
    }

    private static function errore(string $msg, string $testo, int $pos): ErroreModello {
        $prima = substr($testo, 0, $pos);
        $riga = substr_count($prima, "\n") + 1;
        $colonna = mb_strlen(substr($prima, (int) strrpos("\n" . $prima, "\n"))) + 1;
        return new ErroreModello($msg, $riga, $colonna);
    }

    /**
     * Nomi usati dal modello, con il percorso delle sezioni che li contengono («corsi.titolo» per {{titolo}} dentro
     * {{#corsi}}): servono al controllo dei segnaposto ignoti.
     *
     * @return list<string>
     */
    public static function nomi(array $albero, string $prefisso = ''): array {
        $fuori = [];
        foreach ($albero as $nodo) {
            if ($nodo[0] === 'testo' || $nodo[1] === '.') {
                continue;
            }
            $fuori[] = $prefisso . $nodo[1];
            if ($nodo[0] === 'sezione' || $nodo[0] === 'inversa') {
                array_push($fuori, ...self::nomi($nodo[2], $prefisso . $nodo[1] . '.'));
            }
        }
        return array_values(array_unique($fuori));
    }

    /** Rende il modello; $html vero applica l'escape HTML ai valori {{…}}. */
    public static function rendi(array $albero, array $dati, bool $html): string {
        return self::nodi($albero, [$dati], $html);
    }

    /** @param list<mixed> $pila contesti dal più esterno al più interno */
    private static function nodi(array $albero, array $pila, bool $html): string {
        $fuori = '';
        foreach ($albero as $nodo) {
            switch ($nodo[0]) {
                case 'testo':
                    $fuori .= $nodo[1];
                    break;
                case 'valore':
                case 'grezzo':
                    $v = self::testo(self::cerca($nodo[1], $pila));
                    $fuori .= ($nodo[0] === 'valore' && $html) ? htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $v;
                    break;
                case 'sezione':
                    $v = self::cerca($nodo[1], $pila);
                    if (is_array($v) && array_is_list($v)) {
                        foreach ($v as $elemento) {
                            $fuori .= self::nodi($nodo[2], [...$pila, $elemento], $html);
                        }
                    } elseif (self::vero($v)) {
                        $fuori .= self::nodi($nodo[2], is_array($v) ? [...$pila, $v] : $pila, $html);
                    }
                    break;
                case 'inversa':
                    if (!self::vero(self::cerca($nodo[1], $pila))) {
                        $fuori .= self::nodi($nodo[2], $pila, $html);
                    }
                    break;
            }
        }
        return $fuori;
    }

    private static function cerca(string $nome, array $pila): mixed {
        if ($nome === '.') {
            return end($pila);
        }
        $parti = explode('.', $nome);
        for ($i = count($pila) - 1; $i >= 0; $i--) {
            $c = $pila[$i];
            if (is_array($c) && array_key_exists($parti[0], $c)) {
                $v = $c[$parti[0]];
                foreach (array_slice($parti, 1) as $p) {
                    if (!is_array($v) || !array_key_exists($p, $v)) {
                        return null;
                    }
                    $v = $v[$p];
                }
                return $v;
            }
        }
        return null;
    }

    private static function vero(mixed $v): bool {
        return !($v === null || $v === false || $v === '' || $v === [] || $v === 0 || $v === '0');
    }

    private static function testo(mixed $v): string {
        return match (true) {
            $v === null, $v === false => '',
            $v === true => '1',
            is_scalar($v) => (string) $v,
            $v instanceof \Stringable => (string) $v,
            $v instanceof \DateTimeInterface => $v->format('d/m/Y H:i'),
            default => '',
        };
    }
}
