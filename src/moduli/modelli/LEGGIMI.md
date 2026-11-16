# pfi/modelli

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva.

Modelli di testo dei moduli universali (release 9.0, blocco M3; proposta 49 § 3.5): li usano la comunicazione (oggetto e corpo dei messaggi) e la stampa (documenti PDF). Richiede `pfi/contratti` ^1.0 e l'estensione mbstring. Nel portale l'editor è nell'adattatore `tool_pfimodelli`.

## Sintassi

Sottoinsieme della sintassi di Mustache, la stessa dei modelli di Moodle:

| Forma | Effetto |
|---|---|
| `{{nome}}` | valore; nelle parti HTML con escape |
| `{{{nome}}}`, `{{&nome}}` | valore senza escape, solo per testo HTML già sicuro (la verifica lo segnala) |
| `{{#nome}}…{{/nome}}` | ripetuta per ogni elemento di un elenco; mostrata una volta se il valore è vero |
| `{{^nome}}…{{/nome}}` | mostrata se il valore è falso o l'elenco è vuoto |
| `{{persona.nome}}`, `{{.}}` | nome con il punto; elemento corrente di un elenco |
| `{{! commento}}` | non compare |

Non sono ammessi parziali (`{{> …}}`), blocchi, lambda e cambio dei delimitatori: un modello modificato nel portale non può caricare altri file né eseguire codice. Per questo il motore è proprio e non la libreria Mustache di Moodle, che comprende anche quelle forme.

## Interfaccia pubblica

| Elemento | Scopo |
|---|---|
| `Schema($chiave, $descrizione)` | `segnaposto($nome, $descrizione, $esempio, $elenco)`; `esempi()` per l'anteprima; la chiave ha la forma `componente/nome` |
| `Modello($chiave, $canale, $lingua, $parti)` | `rendi($dati)`, `nomi()`, `verifica($schema)`; le parti che terminano con `html` hanno l'escape, `oggetto` è su una riga |
| `Modelli($archivio, $linguapredefinita)` | `dichiara($schema, ...$predefiniti)`, `predefiniti($chiave)`, `trova()`, `rendi()`, `salva()` (restituisce anche gli avvisi), `anteprima()`, `ripristina()` (versione 0: predefinito del codice) |
| `Archivio` | `salva`, `attuale`, `versione`, `versioni`; implementazioni `Archivi\Memoria` e `Archivi\Tabella` (PDO, `crea_tabella()`) |
| `ErroreModello` | errore di sintassi con `riga` e `colonna` |

## Uso fuori da Moodle

```php
use Pfi\Modelli\{Modelli, Modello, Schema};
use Pfi\Modelli\Archivi\Tabella;

$modelli = (new Modelli(new Tabella($pdo)))->dichiara(
    (new Schema('ordini/conferma'))->segnaposto('cliente', 'Nome del cliente', 'Maria'),
    new Modello('ordini/conferma', 'posta', 'it', ['oggetto' => 'Ordine confermato', 'html' => '<p>Gentile {{cliente}}</p>']));
$parti = $modelli->rendi('ordini/conferma', 'posta', 'it', ['cliente' => $nome]);
```

## Limiti

- Le date e i numeri arrivano al modello già formattati dal componente: il motore non ha filtri.
- La verifica riconosce un nome dentro una sezione anche se è dichiarato all'esterno, come fa Mustache; non controlla che un nome dichiarato come elenco sia usato come sezione.
