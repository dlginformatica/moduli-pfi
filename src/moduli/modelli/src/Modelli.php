<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Modelli;

use Pfi\Contratti\Eccezione;

/**
 * Punto d'accesso dei componenti: schemi dichiarati, modelli predefiniti forniti dal codice, modelli modificati
 * nell'archivio. La ricerca di un modello prova la lingua richiesta, poi la lingua predefinita; per ciascuna prima
 * l'archivio e poi il predefinito.
 */
final class Modelli {

    /** @var array<string,Schema> */
    private array $schemi = [];

    /** @var array<string,Modello> */
    private array $predefiniti = [];

    public function __construct(private readonly Archivio $archivio, private readonly string $linguapredefinita = 'it') {
    }

    public function dichiara(Schema $schema, Modello ...$predefiniti): self {
        $this->schemi[$schema->chiave] = $schema;
        foreach ($predefiniti as $m) {
            if ($m->chiave !== $schema->chiave) {
                throw new Eccezione('Modello predefinito di un altro schema.');
            }
            $this->predefiniti["{$m->chiave}|{$m->canale}|{$m->lingua}"] = $m;
        }
        return $this;
    }

    /** @return array<string,Schema> */
    public function schemi(): array {
        return $this->schemi;
    }

    public function schema(string $chiave): Schema {
        return $this->schemi[$chiave] ?? throw new Eccezione("Tipo di modello non dichiarato: «{$chiave}»");
    }

    /**
     * Modelli predefiniti dichiarati, di uno schema o di tutti (per l'editor: canali e lingue disponibili).
     *
     * @return list<Modello>
     */
    public function predefiniti(?string $chiave = null): array {
        return array_values(array_filter($this->predefiniti, fn(Modello $m) => $chiave === null || $m->chiave === $chiave));
    }

    public function predefinito(string $chiave, string $canale, string $lingua): ?Modello {
        return $this->predefiniti["{$chiave}|{$canale}|{$lingua}"] ?? null;
    }

    public function trova(string $chiave, string $canale, string $lingua): Modello {
        $this->schema($chiave);
        foreach (array_unique([$lingua, $this->linguapredefinita]) as $l) {
            $m = $this->archivio->attuale($chiave, $canale, $l) ?? $this->predefinito($chiave, $canale, $l);
            if ($m !== null) {
                return $m;
            }
        }
        throw new Eccezione("Nessun modello «{$chiave}» per il canale «{$canale}».");
    }

    /** @return array<string,string> */
    public function rendi(string $chiave, string $canale, string $lingua, array $dati): array {
        return $this->trova($chiave, $canale, $lingua)->rendi($dati);
    }

    /**
     * Salva un modello modificato nell'editor dopo la verifica sintattica; gli avvisi della verifica sullo schema
     * tornano al chiamante, che li mostra e decide se il salvataggio va confermato.
     *
     * @return array{0:Modello,1:list<string>}
     */
    public function salva(Modello $modello, string $autore, string $nota = ''): array {
        $problemi = $modello->verifica($this->schema($modello->chiave));
        return [$this->archivio->salva($modello, $autore, $nota), $problemi];
    }

    /** Anteprima con i dati di esempio dello schema. */
    public function anteprima(Modello $modello): array {
        return $modello->rendi($this->schema($modello->chiave)->esempi());
    }

    /** Ripristina una versione precedente come nuova versione; la versione 0 è il modello predefinito del codice. */
    public function ripristina(string $chiave, string $canale, string $lingua, int $versione, string $autore): Modello {
        $vecchio = $versione === 0 ? $this->predefinito($chiave, $canale, $lingua)
            : $this->archivio->versione($chiave, $canale, $lingua, $versione);
        if ($vecchio === null) {
            throw new Eccezione("Versione {$versione} inesistente.");
        }
        return $this->archivio->salva($vecchio, $autore, $versione === 0 ? 'Ripristino del modello predefinito'
            : "Ripristino della versione {$versione}");
    }
}
