---
titolo: "Manuale di pfi/diagnostica"
sottotitolo: "Moduli universali del Portale di autoformazione IT: esecuzione delle prove di salute e rapporti per il monitoraggio"
versione: "1.0.0"
data: "2026-11-16"
verifica_fonti: "2026-11-16"
prossima_revisione: "a ogni nuova versione del modulo"
rilascio: "pfi/diagnostica 1.0.0"
autori: "Gaetano Giarrusso e Ufficio USIS"
hash: "n.d. (calcolato nel MANIFEST)"
classificazione: "USO INTERNO"
---

# Manuale di pfi/diagnostica

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale. Copyright 2026 Gaetano Giarrusso, licenza GPL v3 o successiva.

Il manuale è scritto per uno sviluppatore che riusa il modulo in un'applicazione PHP diversa dal portale e per un agente di intelligenza artificiale che deve scrivere codice che lo usa. Il riferimento dell'interfaccia (capitolo 12) è estratto dal codice; gli esempi sono eseguiti in un progetto pulito e l'uscita riportata è quella ottenuta. Distribuzione, versioni e regole comuni sono nella guida generale (`src/moduli/MANUALE-GENERALE.md`).

# 1. A che cosa serve

`pfi/diagnostica` esegue un insieme di **prove di salute** (oggetti che implementano `Pfi\Contratti\ProvaDiSalute`) e ne ricava uno **stato complessivo** (`buono`, `avviso`, `guasto`) e due rapporti: uno in JSON per i sistemi di monitoraggio e uno in Markdown per le persone. Le prove possono venire dai manifesti dei moduli (campo `salute` di `modulo.json`) oppure essere costruite dall'applicazione.

La diagnostica non si interrompe mai per colpa di una prova: un'eccezione, una classe inesistente o una classe che non si può costruire diventano un risultato `guasto` con il motivo, e le altre prove continuano.

Usarla per l'indirizzo di salute di un'applicazione (per esempio `/salute` interrogato dal monitoraggio), per un comando di verifica dopo l'installazione o prima e dopo un aggiornamento. Non è un sistema di monitoraggio: non pianifica, non conserva lo storico e non invia avvisi.

# 2. Installazione

Requisiti: PHP 8.2 o successivo; `pfi/contratti` ^1.0.

```bash
composer require pfi/diagnostica:^1.0
```

Senza Composer, con i moduli installati in una cartella comune:

```php
require '/usr/local/lib/pfi/moduli/contratti/attuale/avvio.php';
\Pfi\Contratti\Caricatore::registra('/usr/local/lib/pfi/moduli');
```

# 3. Concetti

**Prova di salute.** Un oggetto con tre metodi: `codice()` (identificativo stabile, per esempio `fatture.servizio`), `descrizione()` (frase per le persone) ed `esegui()`, che restituisce un `Pfi\Contratti\EsitoSalute`. Una prova deve essere rapida, in sola lettura e con messaggi in italiano privi di segreti; le operazioni di rete devono avere un tempo massimo proprio.

**Esito.** `EsitoSalute::buono()`, `::avviso()` o `::guasto()` con un messaggio e dettagli facoltativi (un array). I dettagli compaiono nei risultati (`Risultato::in_array()`) ma non nei rapporti JSON e Markdown.

**Prove dai manifesti.** `da_manifesti()` legge il campo `salute` di ogni manifesto: per ogni classe elencata crea l'oggetto se il costruttore non ha argomenti obbligatori. Se la prova ha bisogno di dipendenze (un deposito di segreti, una connessione), l'applicazione la costruisce e la passa nell'array `$sostituzioni` con la classe come chiave. Una classe non caricabile, che non implementa l'interfaccia o con argomenti obbligatori non sostituiti dà il risultato `guasto` con codice `diagnostica.caricamento`.

**Prova lenta.** Se una prova `buona` dura più della soglia (predefinita 2000 ms) il suo esito diventa `avviso` con l'indicazione «prova lenta». La durata si misura alla fine: una prova bloccata blocca la diagnostica.

**Stato complessivo.** `guasto` se almeno una prova è guasta; altrimenti `avviso` se almeno una è in avviso; altrimenti `buono`. Senza prove lo stato è `buono`.

# 4. Uso passo per passo

L'esempio costruisce quattro prove: una creata dalla diagnostica dal manifesto, una con dipendenza passata come sostituzione, una classe inesistente e una prova che lancia un'eccezione con un testo riservato, che non compare nel rapporto.

<!-- esempio:uso.php:inizio -->
```php
<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/diagnostica fuori da Moodle: prove dai manifesti e costruite dall'applicazione, stato, rapporti.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Contratti\EsitoSalute;
use Pfi\Contratti\Manifesto;
use Pfi\Contratti\ProvaDiSalute;
use Pfi\Diagnostica\Diagnostica;

// Una prova senza argomenti nel costruttore: la diagnostica la costruisce da sola leggendo il manifesto.
final class EstensioniPhp implements ProvaDiSalute {
    public function codice(): string { return 'fatture.php'; }
    public function descrizione(): string { return 'Estensioni PHP richieste'; }
    public function esegui(): EsitoSalute {
        $mancanti = array_values(array_filter(['json', 'mbstring'], fn($e) => !extension_loaded($e)));
        return $mancanti ? EsitoSalute::guasto('Estensioni mancanti: ' . implode(', ', $mancanti))
            : EsitoSalute::buono('Estensioni presenti');
    }
}

// Una prova con una dipendenza: l'applicazione la costruisce e la passa come sostituzione.
final class ServizioRaggiungibile implements ProvaDiSalute {
    public function __construct(private bool $raggiungibile) {}
    public function codice(): string { return 'fatture.servizio'; }
    public function descrizione(): string { return 'Il servizio esterno risponde'; }
    public function esegui(): EsitoSalute {
        return $this->raggiungibile ? EsitoSalute::buono('Risponde') : EsitoSalute::guasto('Non risponde');
    }
}

// Una prova difettosa: l'eccezione non ferma la diagnostica e il suo testo non finisce nel rapporto.
final class ProvaDifettosa implements ProvaDiSalute {
    public function codice(): string { return 'fatture.difettosa'; }
    public function descrizione(): string { return 'Prova che lancia un\'eccezione'; }
    public function esegui(): EsitoSalute { throw new RuntimeException('password=segreta'); }
}

$manifesto = Manifesto::da_json(json_encode([
    'nome' => 'pfi/fatture', 'versione' => '1.0.0', 'descrizione' => 'Applicazione di prova', 'spazio' => 'App\\',
    'salute' => [EstensioniPhp::class, ServizioRaggiungibile::class, 'App\\ClasseInesistente'],
]));

$diagnostica = (new Diagnostica())
    ->da_manifesti([$manifesto], [ServizioRaggiungibile::class => new ServizioRaggiungibile(true)])
    ->aggiungi('pfi/fatture', new ProvaDifettosa());
$risultati = $diagnostica->esegui();

echo Diagnostica::markdown($risultati), "\n";
echo Diagnostica::json($risultati, new DateTimeImmutable('2026-11-16T09:00:00+01:00')), "\n";
echo 'Codice di uscita per il monitoraggio: ', Diagnostica::stato($risultati) === 'guasto' ? 1 : 0, "\n";
```

Uscita (verificata eseguendo l'esempio in un progetto pulito):

```text
Stato complessivo: guasto

| Modulo | Prova | Esito | Messaggio |
|---|---|---|---|
| pfi/fatture | Estensioni PHP richieste | buono | Estensioni presenti |
| pfi/fatture | Il servizio esterno risponde | buono | Risponde |
| pfi/fatture | Caricamento della prova App\ClasseInesistente | guasto | Prova non caricabile: Class "App\ClasseInesistente" does not exist |
| pfi/fatture | Prova che lancia un'eccezione | guasto | La prova si è interrotta: RuntimeException |

{
    "stato": "guasto",
    "quando": "2026-11-16T09:00:00+01:00",
    "prove": [
        {
            "modulo": "pfi/fatture",
            "codice": "fatture.php",
            "stato": "buono",
            "messaggio": "Estensioni presenti"
        },
        {
            "modulo": "pfi/fatture",
            "codice": "fatture.servizio",
            "stato": "buono",
            "messaggio": "Risponde"
        },
        {
            "modulo": "pfi/fatture",
            "codice": "diagnostica.caricamento",
            "stato": "guasto",
            "messaggio": "Prova non caricabile: Class \"App\\ClasseInesistente\" does not exist"
        },
        {
            "modulo": "pfi/fatture",
            "codice": "fatture.difettosa",
            "stato": "guasto",
            "messaggio": "La prova si è interrotta: RuntimeException"
        }
    ]
}
Codice di uscita per il monitoraggio: 1
```
<!-- esempio:uso.php:fine -->

Indirizzo di salute minimo di un'applicazione (risposta 200 se lo stato non è `guasto`, 503 altrimenti):

```php
$risultati = $diagnostica->esegui();
http_response_code(\Pfi\Diagnostica\Diagnostica::stato($risultati) === 'guasto' ? 503 : 200);
header('Content-Type: application/json; charset=utf-8');
echo \Pfi\Diagnostica\Diagnostica::json($risultati, new DateTimeImmutable());
```

# 5. Configurazione

| Parametro del costruttore | Significato | Predefinito |
|---|---|---|
| `$registro` | registro (`Pfi\Contratti\Registro`) che riceve un evento per ogni prova non buona: `error` per i guasti, `warning` per gli avvisi | registro nullo |
| `$soglialenta` | durata in millisecondi oltre la quale una prova buona diventa avviso | `2000.0` |

Non ci sono file di configurazione: le prove si scelgono nel codice o nei manifesti.

# 6. Errori ed eccezioni

Il modulo non lancia eccezioni proprie durante l'esecuzione delle prove. Gli errori diventano risultati:

| Situazione | Codice | Esito e messaggio |
|---|---|---|
| la prova lancia un'eccezione | codice della prova | `guasto` «La prova si è interrotta: <classe dell'eccezione>» |
| classe del manifesto inesistente, non conforme o con argomenti obbligatori | `diagnostica.caricamento` | `guasto` «Prova non caricabile: <motivo>» |
| prova buona più lenta della soglia | codice della prova | `avviso` «<messaggio> (prova lenta: N ms)» |

`json()` può lanciare `JsonException` solo se un messaggio contiene byte non UTF-8; i messaggi delle prove devono essere in UTF-8.

# 7. Sicurezza

- Di un'eccezione si riporta solo la classe, non il testo, perché il testo potrebbe contenere password o indirizzi interni (nell'esempio «password=segreta» non compare).
- Il rapporto JSON non contiene i dettagli delle prove; può essere esposto al sistema di monitoraggio. Non esporlo a Internet senza autenticazione o restrizione di indirizzo: codici e messaggi descrivono l'architettura.
- Il messaggio di una prova non caricabile contiene il messaggio dell'errore PHP (per esempio il nome della classe): non contiene segreti, ma rivela nomi interni.
- Le prove scritte dall'applicazione sono responsabili dei propri messaggi: niente valori di segreti, percorsi di chiavi o stringhe di connessione.

# 8. Prove di salute

Il modulo non dichiara prove proprie (campo `salute` vuoto): esegue quelle degli altri. Le prove che ne usa il portale, per orientamento: `contratti`, `modelli` ed `esportazione` nessuna; `registro`, `segreti`, `connettori`, `esecutore`, `stampa`, `comunicazione`, `backup` e `gestore` una ciascuno (vedere i rispettivi manuali al capitolo 8).

# 9. Uso nel portale

`tool_pfimoduli\moduli::salute()` costruisce la diagnostica con il registro del portale, legge i manifesti dei moduli caricati e passa come sostituzioni le prove che richiedono dipendenze (per esempio il deposito dei segreti configurato); il risultato alimenta il pannello «Moduli» e la verifica prima e dopo un aggiornamento. Lo script di verifica dell'appliance (`pfi-verifica`) usa lo stesso stato complessivo. Informazione utile solo per confronto.

# 10. Limiti noti

- Le prove si eseguono una dopo l'altra, nello stesso processo; non c'è un tempo massimo imposto dalla diagnostica.
- I nomi dei manifesti devono avere la forma `pfi/<nome>` (regola di `pfi/contratti`): un'applicazione che dichiara prove nel proprio manifesto usa quel prefisso, oppure aggiunge le prove con `aggiungi()` senza manifesto.
- Non c'è memoria dei risultati precedenti né confronto fra esecuzioni.

# 11. Indicazioni per gli agenti

- Per una nuova prova implementare `Pfi\Contratti\ProvaDiSalute`; tenere il costruttore senza argomenti se la prova deve funzionare dal manifesto, altrimenti passarla come sostituzione.
- Non fare `try/catch` attorno a `esegui()` della diagnostica per proteggersi dalle prove: lo fa già.
- Decidere i codici di uscita o di risposta HTTP con `Diagnostica::stato()`, non contando i risultati.
- Non includere i dettagli (`$esito->dettagli`) in rapporti esposti fuori dall'applicazione.
- Usare un orario passato dall'esterno in `json()` nelle prove automatiche, così il rapporto è ripetibile.

# 12. Riferimento dell'interfaccia

Generato dal codice della versione 1.0.0; lo spazio dei nomi è `Pfi\Diagnostica\`.

<!-- riferimento:inizio -->
#### `Diagnostica`

Classe finale. Esecuzione delle prove di salute dei moduli (release 9.0, blocco M1b; D-163).

| Metodo | Descrizione |
|---|---|
| `__construct(?Pfi\Contratti\Registro $registro = null, promossa float $soglialenta = 2000.0)` |  |
| `da_manifesti(iterable $manifesti, array $sostituzioni = []): self` | Aggiunge le prove dichiarate nei manifesti; le classi con costruttore che richiede argomenti si saltano con un avviso. |
| `aggiungi(string $modulo, Pfi\Contratti\ProvaDiSalute $prova): self` |  |
| `esegui(): array` | Esegue tutte le prove. |
| `static stato(array $risultati): string` | Stato complessivo: «guasto» se almeno una prova è guasta, «avviso» se almeno una è in avviso, altrimenti «buono». |
| `static json(array $risultati, DateTimeInterface $quando): string` | Rapporto per i sistemi di monitoraggio: JSON senza dettagli, solo stato, codici e messaggi. |
| `static markdown(array $risultati): string` | Rapporto in Markdown per le persone. |

#### `ProvaNonCaricabile`

Classe finale, implementa `Pfi\Contratti\ProvaDiSalute`. Prova dichiarata in un manifesto ma non costruibile: si riporta come guasto con il motivo.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $classe, promossa string $motivo)` |  |
| `codice(): string` | Codice stabile della prova, per esempio «registro.scrittura». |
| `descrizione(): string` | Descrizione in italiano di che cosa controlla. |
| `esegui(): Pfi\Contratti\EsitoSalute` |  |

#### `Risultato`

Classe finale. Risultato di una prova di salute eseguita: modulo, codice, descrizione, esito e durata.

Proprietà pubbliche: `readonly string $modulo`, `readonly string $codice`, `readonly string $descrizione`, `readonly Pfi\Contratti\EsitoSalute $esito`, `readonly float $millisecondi`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $modulo, promossa string $codice, promossa string $descrizione, promossa Pfi\Contratti\EsitoSalute $esito, promossa float $millisecondi)` |  |
| `in_array(): array` |  |

<!-- riferimento:fine -->

# Registro delle versioni del manuale

| Versione | Data | Modifiche |
|---|---|---|
| 1.0.0 | 16 novembre 2026 | prima versione, per pfi/diagnostica 1.0.0 |
