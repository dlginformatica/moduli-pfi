<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Modelli;

use Pfi\Contratti\Eccezione;

/**
 * Segnaposti dichiarati da un componente per un tipo di modello (proposta 49 § 3.5): nome, descrizione ed esempio di
 * ciascuno. I campi degli elenchi si dichiarano con il nome dell'elenco davanti («corsi.titolo» dentro «corsi»).
 */
final class Schema {

    /** @var array<string,array{descrizione:string,esempio:mixed,elenco:bool}> */
    private array $segnaposti = [];

    public function __construct(public readonly string $chiave, public readonly string $descrizione = '') {
        if (!preg_match('~^[a-z][a-z0-9_]*/[a-z][a-z0-9_]*$~', $chiave)) {
            throw new Eccezione("Chiave di modello non valida: «{$chiave}» (forma componente/nome)");
        }
    }

    public function segnaposto(string $nome, string $descrizione, mixed $esempio = null, bool $elenco = false): self {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $nome)) {
            throw new Eccezione("Nome di segnaposto non valido: «{$nome}»");
        }
        $this->segnaposti[$nome] = ['descrizione' => $descrizione, 'esempio' => $esempio, 'elenco' => $elenco];
        return $this;
    }

    /** @return array<string,array{descrizione:string,esempio:mixed,elenco:bool}> */
    public function segnaposti(): array {
        return $this->segnaposti;
    }

    /**
     * Il nome usato nel modello corrisponde a un segnaposto dichiarato: il nome intero oppure, dentro le sezioni, il
     * nome senza le sezioni esterne (come nella ricerca di Mustache, che risale ai contesti esterni).
     */
    public function conosce(string $nome): bool {
        $parti = explode('.', $nome);
        for ($i = 0; $i < count($parti); $i++) {
            if (isset($this->segnaposti[implode('.', array_slice($parti, $i))])) {
                return true;
            }
        }
        return false;
    }

    /** Dati di esempio per l'anteprima: un elemento per elenco, costruito dai campi dichiarati se manca l'esempio. */
    public function esempi(): array {
        $dati = [];
        $nomi = array_keys($this->segnaposti);
        sort($nomi);
        foreach ($nomi as $nome) {
            $s = $this->segnaposti[$nome];
            $percorso = explode('.', $nome);
            $rif = &$dati;
            foreach (array_slice($percorso, 0, -1) as $p) {
                if (isset($rif[$p]) && is_array($rif[$p]) && array_is_list($rif[$p]) && $rif[$p]) {
                    $rif = &$rif[$p][0];
                } else {
                    $rif[$p] ??= [];
                    $rif = &$rif[$p];
                }
            }
            $ultimo = end($percorso);
            if ($s['elenco']) {
                $rif[$ultimo] = is_array($s['esempio']) && array_is_list($s['esempio']) ? $s['esempio'] : [[]];
            } elseif (!isset($rif[$ultimo])) {
                $rif[$ultimo] = $s['esempio'] ?? '«' . $nome . '»';
            }
            unset($rif);
        }
        return $dati;
    }
}
