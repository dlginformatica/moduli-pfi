<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Contratti;

/**
 * Manifesto di un modulo («modulo.json»): identità, versione, requisiti e prove di salute.
 *
 * Campi: nome («pfi/<nome>»), versione (semantica), descrizione, spazio (spazio dei nomi PHP, per esempio «Pfi\\Registro\\»),
 * php (vincolo sulla versione di PHP), richiede (oggetto nome del modulo => vincolo), salute (elenco di classi che implementano
 * ProvaDiSalute).
 */
final class Manifesto {

    /**
     * @param array<string,string> $richiede
     * @param string[] $salute
     */
    public function __construct(
        public readonly string $nome,
        public readonly Versione $versione,
        public readonly string $descrizione,
        public readonly string $spazio,
        public readonly string $php,
        public readonly array $richiede,
        public readonly array $salute,
    ) {
    }

    public static function da_file(string $percorso): self {
        $testo = @file_get_contents($percorso);
        if ($testo === false) {
            throw new Eccezione("Manifesto non leggibile: $percorso");
        }
        return self::da_json($testo);
    }

    public static function da_json(string $json): self {
        $d = json_decode($json, true);
        if (!is_array($d)) {
            throw new Eccezione('Manifesto non valido: JSON non leggibile');
        }
        foreach (['nome', 'versione', 'descrizione', 'spazio'] as $campo) {
            if (!isset($d[$campo]) || !is_string($d[$campo]) || trim($d[$campo]) === '') {
                throw new Eccezione("Manifesto non valido: manca il campo «{$campo}»");
            }
        }
        if (!preg_match('#^pfi/[a-z][a-z0-9-]*$#', $d['nome'])) {
            throw new Eccezione("Manifesto non valido: nome «{$d['nome']}» (atteso pfi/<nome>)");
        }
        if (!preg_match('/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)*\\\\$/', $d['spazio'])) {
            throw new Eccezione("Manifesto non valido: spazio dei nomi «{$d['spazio']}»");
        }
        $richiede = $d['richiede'] ?? [];
        if (!is_array($richiede)) {
            throw new Eccezione('Manifesto non valido: «richiede» deve essere un oggetto');
        }
        foreach ($richiede as $modulo => $vincolo) {
            if (!is_string($modulo) || !preg_match('#^pfi/[a-z][a-z0-9-]*$#', $modulo) || !is_string($vincolo)) {
                throw new Eccezione('Manifesto non valido: requisito «' . $modulo . '»');
            }
            Versione::da_testo('0.0.0')->soddisfa($vincolo); // controlla la sintassi del vincolo
        }
        $salute = $d['salute'] ?? [];
        if (!is_array($salute) || array_filter($salute, fn($c) => !is_string($c))) {
            throw new Eccezione('Manifesto non valido: «salute» deve essere un elenco di classi');
        }
        return new self($d['nome'], Versione::da_testo($d['versione']), trim($d['descrizione']), $d['spazio'],
            (string) ($d['php'] ?? '>=8.2'), $richiede, array_values($salute));
    }

    /**
     * Requisiti non soddisfatti dai moduli disponibili.
     *
     * @param array<string,Versione> $disponibili nome del modulo => versione installata
     * @return string[] descrizioni in italiano, vuoto se tutto è soddisfatto
     */
    public function requisiti_mancanti(array $disponibili, ?string $versionephp = null): array {
        $mancanti = [];
        $php = Versione::da_testo(preg_replace('/^(\d+\.\d+\.\d+).*$/', '$1', $versionephp ?? PHP_VERSION));
        if (!$php->soddisfa($this->php)) {
            $mancanti[] = "PHP $php non soddisfa «{$this->php}»";
        }
        foreach ($this->richiede as $modulo => $vincolo) {
            if (!isset($disponibili[$modulo])) {
                $mancanti[] = "manca $modulo («{$vincolo}»)";
            } else if (!$disponibili[$modulo]->soddisfa($vincolo)) {
                $mancanti[] = "$modulo {$disponibili[$modulo]} non soddisfa «{$vincolo}»";
            }
        }
        return $mancanti;
    }
}
