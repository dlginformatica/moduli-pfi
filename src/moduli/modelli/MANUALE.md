---
titolo: "Manuale di pfi/modelli"
sottotitolo: "Moduli universali del Portale di autoformazione IT: modelli di testo modificabili, versionati e verificati sullo schema dei segnaposti"
versione: "1.0.0"
data: "2026-11-16"
verifica_fonti: "2026-11-16"
prossima_revisione: "a ogni nuova versione del modulo"
rilascio: "pfi/modelli 1.0.0"
autori: "Gaetano Giarrusso e Ufficio USIS"
hash: "n.d. (calcolato nel MANIFEST)"
classificazione: "USO INTERNO"
---

# Manuale di pfi/modelli

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale. Copyright 2026 Gaetano Giarrusso, licenza GPL v3 o successiva.

Il manuale è scritto per uno sviluppatore che riusa il modulo in un'applicazione PHP diversa dal portale e per un agente di intelligenza artificiale che deve scrivere codice che lo usa. Il riferimento dell'interfaccia (capitolo 12) è estratto dal codice; gli esempi sono eseguiti in un progetto pulito e l'uscita riportata è quella ottenuta. Distribuzione, versioni e regole comuni sono nella guida generale (`src/moduli/MANUALE-GENERALE.md`).

# 1. A che cosa serve

`pfi/modelli` separa i **testi** che un'applicazione produce (messaggi di posta, notifiche, intestazioni dei documenti) dal **codice** che li riempie. Il codice dichiara uno **schema**, cioè l'elenco dei segnaposti disponibili con descrizione ed esempio, e fornisce i **modelli predefiniti**; un amministratore può modificare i testi senza toccare il codice, vederne l'anteprima con i dati di esempio, salvarli come **nuove versioni** e tornare a una versione precedente o al predefinito.

La sintassi è un sottoinsieme di Mustache, la stessa dei modelli di Moodle, **senza logica**: nessun codice nei modelli, nessun file incluso. Un errore di sintassi si segnala con riga e colonna; le differenze rispetto allo schema (segnaposti sconosciuti, valori senza escape HTML) diventano avvisi.

Il modulo è la base di `pfi/comunicazione` (messaggi) e di `pfi/stampa` (documenti), ma si usa anche da solo. Non è un motore di pagine web: non ha ereditarietà, filtri né funzioni.

# 2. Installazione

Requisiti: PHP 8.2 o successivo con le estensioni `mbstring` e `json`; `pfi/contratti` ^1.0; PDO con il driver del database se si usa l'archivio `Tabella`.

```bash
composer require pfi/modelli:^1.0
```

Fuori da Moodle la tabella delle versioni si crea una volta con `(new \Pfi\Modelli\Archivi\Tabella($pdo))->crea_tabella()`.

# 3. Concetti

**Sintassi.**

| Forma | Effetto |
|---|---|
| `{{nome}}` | valore; nelle parti HTML con escape |
| `{{{nome}}}`, `{{&nome}}` | valore senza escape, solo per HTML già sicuro (la verifica lo segnala) |
| `{{#nome}}…{{/nome}}` | ripetuta per ogni elemento di un elenco; mostrata una volta se il valore è vero |
| `{{^nome}}…{{/nome}}` | mostrata se il valore è falso, assente o è un elenco vuoto |
| `{{persona.nome}}`, `{{.}}` | nome con il punto; elemento corrente di un elenco |
| `{{! commento}}` | non compare |

Non sono ammessi i parziali (`{{> altro}}`), le lambda e il cambio dei delimitatori: eseguirebbero codice o caricherebbero altri file. Dentro una sezione un nome si cerca prima nell'elemento corrente e poi fuori. I nomi hanno lettere, cifre e trattino basso, con il punto come separatore.

**Schema.** `new Schema('componente/nome', $descrizione)`, con chiave di lettere minuscole, cifre e trattino basso (`corsi/foglio_presenze`, non `corsi/foglio-presenze`); i segnaposti si aggiungono con `segnaposto($nome, $descrizione, $esempio, $elenco)`. Per un elenco si dichiara il nome con `$elenco = true` e i campi dei suoi elementi con il punto (`fatture.numero`). `esempi()` costruisce i dati per l'anteprima.

**Modello.** `new Modello($chiave, $canale, $lingua, $parti)`: la chiave è quella dello schema; il canale indica dove va il testo (`posta`, `telegram`, `documento`…; lettere minuscole, cifre, trattino basso); la lingua ha la forma `it` o `it_it`; le parti sono un array `nome => testo` (per la posta tipicamente `oggetto`, `html`, `testo`). Il modello si analizza alla costruzione: un errore di sintassi lancia subito `ErroreModello`.

**Regole delle parti.** Una parte il cui nome **finisce** con `html` (per esempio `html`, `corpohtml`) ha l'escape HTML dei valori; le altre no. La parte `oggetto` è ridotta a una riga (spazi e a capo compressi).

**Modelli e archivio.** `Modelli($archivio, $linguapredefinita)` è il punto d'accesso: `dichiara()` registra schema e predefiniti; `trova()` cerca la versione attuale nell'archivio per la lingua chiesta, poi il predefinito, poi le stesse due cose nella lingua predefinita; `rendi()` trova e riempie. `salva()` scrive una nuova versione e restituisce `[modello salvato, avvisi]`; `ripristina($chiave, $canale, $lingua, $versione, $autore)` ricopia una versione come nuova (la versione 0 è il predefinito del codice). Le versioni non si cancellano. Archivi disponibili: `Archivi\Tabella` (PDO) e `Archivi\Memoria`; un'applicazione può scriverne uno proprio implementando `Pfi\Modelli\Archivio`.

# 4. Uso passo per passo

L'esempio dichiara uno schema con un elenco, rende il modello predefinito in italiano (con escape nella sola parte HTML), usa la lingua di ripiego, salva una versione modificata che produce due avvisi, mostra tre errori di sintassi, ripristina il predefinito, elenca le versioni e produce l'anteprima.

<!-- esempio:uso.php:inizio -->
```php
<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/modelli fuori da Moodle: schema dei segnaposti, modelli predefiniti, resa con escape HTML,
// lingua di ripiego, versioni modificate in un archivio SQLite, errori di sintassi e ripristino.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Modelli\Archivi\Tabella;
use Pfi\Modelli\ErroreModello;
use Pfi\Modelli\Modelli;
use Pfi\Modelli\Modello;
use Pfi\Modelli\Schema;

// 1. Il componente dichiara i segnaposti del tipo di messaggio e i modelli predefiniti, scritti nel codice.
$schema = (new Schema('fatture/sollecito', 'Sollecito di pagamento'))
    ->segnaposto('cliente', 'Nome del cliente', 'Maria Bianchi')
    ->segnaposto('scadenza', 'Data di scadenza già formattata', '30 novembre 2026')
    ->segnaposto('fatture', 'Fatture aperte, ciascuna con numero e importo', [['numero' => 'F-1', 'importo' => '120,00 €']], true)
    ->segnaposto('fatture.numero', 'Numero della fattura', 'F-1')
    ->segnaposto('fatture.importo', 'Importo già formattato', '120,00 €');

$predefinito = new Modello('fatture/sollecito', 'posta', 'it', [
    'oggetto' => "Sollecito: {{#fatture}}{{numero}} {{/fatture}}\n in scadenza",
    'html' => '<p>Gentile {{cliente}},</p><ul>{{#fatture}}<li>{{numero}}: {{importo}}</li>{{/fatture}}</ul>'
        . '{{^fatture}}<p>Nessuna fattura aperta.</p>{{/fatture}}<p>Scadenza: {{scadenza}}</p>',
    'testo' => "Gentile {{cliente}},\n{{#fatture}}- {{numero}}: {{importo}}\n{{/fatture}}Scadenza: {{scadenza}}",
]);

$pdo = new PDO('sqlite::memory:');
$archivio = new Tabella($pdo);
$archivio->crea_tabella();
$modelli = (new Modelli($archivio, 'it'))->dichiara($schema, $predefinito);

// 2. Resa: nelle parti che finiscono con «html» i valori hanno l'escape; l'oggetto sta su una riga.
$dati = ['cliente' => 'Rossi & <Figli>', 'scadenza' => '30 novembre 2026',
    'fatture' => [['numero' => 'F-118', 'importo' => '250,00 €'], ['numero' => 'F-121', 'importo' => '90,50 €']]];
foreach ($modelli->rendi('fatture/sollecito', 'posta', 'it', $dati) as $parte => $testo) {
    echo "[{$parte}]\n{$testo}\n";
}

// 3. Lingua senza modello: si usa la lingua predefinita.
echo "\nIn francese (ripiego sull'italiano): ", $modelli->rendi('fatture/sollecito', 'posta', 'fr', $dati)['oggetto'], "\n";

// 4. Un amministratore modifica il modello: si salva come nuova versione, con gli avvisi sullo schema.
[$salvato, $avvisi] = $modelli->salva(new Modello('fatture/sollecito', 'posta', 'it', [
    'oggetto' => 'Promemoria per {{cliente}}',
    'html' => '<p>Gentile {{cliente}}, codice {{{codice_cliente}}}</p>',
    'testo' => 'Gentile {{cliente}}',
]), 'admin', 'Testo più breve');
echo "\nSalvata la versione {$salvato->versione}; avvisi:\n- ", implode("\n- ", $avvisi), "\n";
echo "Oggetto attuale: ", $modelli->rendi('fatture/sollecito', 'posta', 'it', $dati)['oggetto'], "\n";

// 5. Errori di sintassi: si segnalano con riga e colonna; i parziali non sono ammessi.
foreach (["Riga uno\nCiao {{cliente}", 'Allegato: {{> altro_modello}}', '{{#fatture}}{{numero}}'] as $sbagliato) {
    try {
        new Modello('fatture/sollecito', 'posta', 'it', ['testo' => $sbagliato]);
    } catch (ErroreModello $e) {
        echo "Errore: {$e->getMessage()}; posizione nelle proprietà: {$e->riga}:{$e->colonna}\n";
    }
}

// 6. Ripristino del predefinito del codice (versione 0) come nuova versione; storico delle versioni.
$modelli->ripristina('fatture/sollecito', 'posta', 'it', 0, 'admin');
echo "\nDopo il ripristino: ", $modelli->rendi('fatture/sollecito', 'posta', 'it', $dati)['oggetto'], "\n";
foreach ($archivio->versioni('fatture/sollecito', 'posta', 'it') as $v) {
    echo "versione {$v['versione']} di {$v['autore']}: {$v['nota']}\n";
}

// 7. Anteprima con i dati di esempio dello schema.
echo "\nAnteprima: ", $modelli->anteprima($predefinito)['testo'], "\n";
```

Uscita (verificata eseguendo l'esempio in un progetto pulito):

```text
[oggetto]
Sollecito: F-118 F-121 in scadenza
[html]
<p>Gentile Rossi &amp; &lt;Figli&gt;,</p><ul><li>F-118: 250,00 €</li><li>F-121: 90,50 €</li></ul><p>Scadenza: 30 novembre 2026</p>
[testo]
Gentile Rossi & <Figli>,
- F-118: 250,00 €
- F-121: 90,50 €
Scadenza: 30 novembre 2026

In francese (ripiego sull'italiano): Sollecito: F-118 F-121 in scadenza

Salvata la versione 1; avvisi:
- Segnaposto non previsto: {{codice_cliente}}
- La parte «html» inserisce valori senza escape HTML ({{{…}}} o {{&…}}): solo per testo HTML già sicuro.
Oggetto attuale: Promemoria per Rossi & <Figli>
Errore: Parte «testo»: Segnaposto non chiuso (riga 2, colonna 6); posizione nelle proprietà: 2:6
Errore: Parte «testo»: Elemento di Mustache non ammesso nei modelli del portale (parziali, blocchi o delimitatori) (riga 1, colonna 11); posizione nelle proprietà: 1:11
Errore: Parte «testo»: Sezione «fatture» non chiusa (riga 1, colonna 1); posizione nelle proprietà: 1:1

Dopo il ripristino: Sollecito: F-118 F-121 in scadenza
versione 2 di admin: Ripristino del modello predefinito
versione 1 di admin: Testo più breve

Anteprima: Gentile Maria Bianchi,
- F-1: 120,00 €
Scadenza: 30 novembre 2026
```
<!-- esempio:uso.php:fine -->

# 5. Configurazione

| Costruttore | Parametri e predefiniti |
|---|---|
| `Modelli` | `$archivio`, `$linguapredefinita` (`'it'`) |
| `Archivi\Tabella` | `$pdo`, `$tabella` (`'pfi_modelli'`); colonne `id`, `chiave`, `canale`, `lingua`, `versione`, `parti` (JSON), `autore`, `nota`, `creato` (secondi Unix), con indice unico su chiave, canale, lingua e versione |
| `Modello` | `$chiave`, `$canale`, `$lingua`, `$parti`, `$versione` (0 per i predefiniti) |

Date, numeri e importi arrivano al modello già formattati dal codice (il motore non ha filtri): così lingua e formato restano decisi in un solo posto.

# 6. Errori ed eccezioni

| Situazione | Classe | Messaggio |
|---|---|---|
| sintassi errata (segnaposto o sezione non chiusi, nome non valido, chiusura senza apertura, parziali, delimitatori) | `ErroreModello` (con `riga` e `colonna`) | «Parte «…»: <problema> (riga R, colonna C)» |
| canale, lingua o nome di parte non validi; modello senza parti | `Eccezione` | «Canale o lingua del modello non validi.», «Il modello non ha parti.», «Nome di parte del modello non valido.» |
| chiave dello schema non nella forma `componente/nome`; nome di segnaposto non valido | `Eccezione` | «Chiave di modello non valida: «…» (forma componente/nome)», «Nome di segnaposto non valido: «…»» |
| schema non dichiarato | `Eccezione` | «Tipo di modello non dichiarato: «…»» |
| nessun modello per chiave e canale in nessuna delle due lingue | `Eccezione` | «Nessun modello «…» per il canale «…».» |
| predefinito con chiave di un altro schema; versione inesistente in `ripristina()` | `Eccezione` | «Modello predefinito di un altro schema.», «Versione N inesistente.» |

`ErroreModello` estende `Pfi\Contratti\Eccezione`. Un segnaposto senza valore nei dati non è un errore: produce testo vuoto.

# 7. Sicurezza

- Nelle parti HTML l'escape è automatico; `{{{…}}}` e `{{&…}}` vanno usati solo per HTML prodotto e ripulito dal codice, e la verifica li segnala all'amministratore.
- Nelle parti non HTML non c'è escape: il canale che le usa (per esempio un messaggio Telegram in Markdown) deve trattare il testo secondo le proprie regole.
- I modelli non possono eseguire codice né leggere file: chi può modificarli può cambiare il testo, non il comportamento dell'applicazione.
- I dati passati a `rendi()` possono contenere dati personali: il testo prodotto va trattato come i dati da cui nasce.

# 8. Prove di salute

Il modulo non dichiara prove di salute: non ha risorse esterne. Lo stato dell'archivio si controlla con la prova del database dell'applicazione.

# 9. Uso nel portale

Nel portale l'archivio è la tabella `tool_pfimodelli` gestita con l'API dei dati di Moodle (stessa struttura di `Archivi\Tabella`), e l'adattatore `tool_pfimodelli` offre l'editor con anteprima, avvisi, storico e ripristino. Informazione utile solo per confronto.

# 10. Limiti noti

- Nessun filtro o formattazione nel modello: tutto arriva già pronto dal codice.
- La verifica accetta un nome dentro una sezione anche se è dichiarato solo fuori, come fa Mustache; non controlla che un nome dichiarato come elenco sia usato come sezione.
- L'archivio non gestisce la modifica concorrente: due salvataggi contemporanei producono due versioni consecutive, vince l'ultima.

# 11. Indicazioni per gli agenti

- Dichiarare sempre lo schema completo, con esempi realistici: servono all'anteprima e alla verifica.
- Fornire nel codice un predefinito per ogni canale usato, almeno nella lingua predefinita.
- Chiamare `Modelli::rendi()` con la chiave, non costruire testi con concatenazioni nel codice.
- Mostrare all'utente gli avvisi restituiti da `salva()` e gli errori con riga e colonna.
- Non usare `{{{…}}}` per comodità: passare testo semplice e lasciare l'escape al motore.

# 12. Riferimento dell'interfaccia

Generato dal codice della versione 1.0.0; lo spazio dei nomi è `Pfi\Modelli\`.

<!-- riferimento:inizio -->
#### `Archivi\Memoria`

Classe finale, implementa `Pfi\Modelli\Archivio`. Archivio in memoria, per le prove e per le applicazioni senza database.

| Metodo | Descrizione |
|---|---|
| `salva(Pfi\Modelli\Modello $m, string $autore, string $nota = ''): Pfi\Modelli\Modello` | Salva le parti come nuova versione e restituisce il modello con il numero di versione. |
| `attuale(string $chiave, string $canale, string $lingua): ?Pfi\Modelli\Modello` | Versione in uso (l'ultima), o null. |
| `versione(string $chiave, string $canale, string $lingua, int $versione): ?Pfi\Modelli\Modello` |  |
| `versioni(string $chiave, string $canale, string $lingua): array` |  |

#### `Archivi\Tabella`

Classe finale, implementa `Pfi\Modelli\Archivio`. Archivio in una tabella via PDO (PostgreSQL, MySQL o MariaDB, SQLite). Nel portale la tabella è dichiarata da tool_pfimodelli; fuori da Moodle si crea con crea_tabella(). Il numero di versione è unico per chiave, canale e lingua.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa PDO $pdo, promossa string $tabella = 'pfi_modelli')` |  |
| `crea_tabella(): void` |  |
| `salva(Pfi\Modelli\Modello $m, string $autore, string $nota = ''): Pfi\Modelli\Modello` | Salva le parti come nuova versione e restituisce il modello con il numero di versione. |
| `attuale(string $chiave, string $canale, string $lingua): ?Pfi\Modelli\Modello` | Versione in uso (l'ultima), o null. |
| `versione(string $chiave, string $canale, string $lingua, int $versione): ?Pfi\Modelli\Modello` |  |
| `versioni(string $chiave, string $canale, string $lingua): array` |  |

#### `Archivio`

Interfaccia. Conservazione dei modelli con le versioni (proposta 49 § 3.5): ogni salvataggio crea una versione nuova, il ripristino salva come nuova una versione precedente, nessuna versione si cancella.

| Metodo | Descrizione |
|---|---|
| `salva(Pfi\Modelli\Modello $modello, string $autore, string $nota = ''): Pfi\Modelli\Modello` | Salva le parti come nuova versione e restituisce il modello con il numero di versione. |
| `attuale(string $chiave, string $canale, string $lingua): ?Pfi\Modelli\Modello` | Versione in uso (l'ultima), o null. |
| `versione(string $chiave, string $canale, string $lingua, int $versione): ?Pfi\Modelli\Modello` |  |
| `versioni(string $chiave, string $canale, string $lingua): array` |  |

#### `ErroreModello`

Classe finale, estende `Pfi\Contratti\Eccezione`; implementa `Throwable`. Errore di sintassi di un modello, con riga e colonna per l'editor.

Proprietà pubbliche: `readonly int $riga`, `readonly int $colonna`.

| Metodo | Descrizione |
|---|---|
| `__construct(string $messaggio, promossa int $riga = 0, promossa int $colonna = 0)` |  |

#### `Modelli`

Classe finale. Punto d'accesso dei componenti: schemi dichiarati, modelli predefiniti forniti dal codice, modelli modificati nell'archivio. La ricerca di un modello prova la lingua richiesta, poi la lingua predefinita; per ciascuna prima l'archivio e poi il predefinito.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa Pfi\Modelli\Archivio $archivio, promossa string $linguapredefinita = 'it')` |  |
| `dichiara(Pfi\Modelli\Schema $schema, Pfi\Modelli\Modello ...$predefiniti): self` |  |
| `schemi(): array` |  |
| `schema(string $chiave): Pfi\Modelli\Schema` |  |
| `predefiniti(?string $chiave = null): array` | Modelli predefiniti dichiarati, di uno schema o di tutti (per l'editor: canali e lingue disponibili). |
| `predefinito(string $chiave, string $canale, string $lingua): ?Pfi\Modelli\Modello` |  |
| `trova(string $chiave, string $canale, string $lingua): Pfi\Modelli\Modello` |  |
| `rendi(string $chiave, string $canale, string $lingua, array $dati): array` |  |
| `salva(Pfi\Modelli\Modello $modello, string $autore, string $nota = ''): array` | Salva un modello modificato nell'editor dopo la verifica sintattica; gli avvisi della verifica sullo schema tornano al chiamante, che li mostra e decide se il salvataggio va confermato. |
| `anteprima(Pfi\Modelli\Modello $modello): array` | Anteprima con i dati di esempio dello schema. |
| `ripristina(string $chiave, string $canale, string $lingua, int $versione, string $autore): Pfi\Modelli\Modello` | Ripristina una versione precedente come nuova versione; la versione 0 è il modello predefinito del codice. |

#### `Modello`

Classe finale. Modello di un tipo (chiave dello schema) per un canale e una lingua, con le sue parti (per la posta «oggetto», «html» e facoltativamente «testo»; per Telegram e notifica «testo»; per la stampa «html»). Le parti il cui nome termina con «html» si rendono con l'escape HTML; le altre come testo; l'oggetto su una sola riga.

Proprietà pubbliche: `readonly string $chiave`, `readonly string $canale`, `readonly string $lingua`, `readonly array $parti`, `readonly int $versione`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $chiave, promossa string $canale, promossa string $lingua, promossa array $parti, promossa int $versione = 0)` |  |
| `static html(string $nome): bool` |  |
| `rendi(array $dati): array` |  |
| `nomi(): array` |  |
| `verifica(Pfi\Modelli\Schema $schema): array` | Problemi rispetto allo schema, per l'editor: segnaposti ignoti, valori senza escape in parti HTML. |

#### `Motore`

Classe finale. Motore dei modelli (release 9.0, blocco M3; proposta 49 § 3.5): sottoinsieme della sintassi di Mustache, la stessa dei modelli di Moodle, senza logica nei modelli.

| Metodo | Descrizione |
|---|---|
| `static analizza(string $testo): array` |  |
| `static nomi(array $albero, string $prefisso = ''): array` | Nomi usati dal modello, con il percorso delle sezioni che li contengono («corsi.titolo» per {{titolo}} dentro {{#corsi}}): servono al controllo dei segnaposto ignoti. |
| `static rendi(array $albero, array $dati, bool $html): string` | Rende il modello; $html vero applica l'escape HTML ai valori {{…}}. |

#### `Schema`

Classe finale. Segnaposti dichiarati da un componente per un tipo di modello (proposta 49 § 3.5): nome, descrizione ed esempio di ciascuno. I campi degli elenchi si dichiarano con il nome dell'elenco davanti («corsi.titolo» dentro «corsi»).

Proprietà pubbliche: `readonly string $chiave`, `readonly string $descrizione`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $chiave, promossa string $descrizione = '')` |  |
| `segnaposto(string $nome, string $descrizione, mixed $esempio = null, bool $elenco = false): self` |  |
| `segnaposti(): array` |  |
| `conosce(string $nome): bool` | Il nome usato nel modello corrisponde a un segnaposto dichiarato: il nome intero oppure, dentro le sezioni, il nome senza le sezioni esterne (come nella ricerca di Mustache, che risale ai contesti esterni). |
| `esempi(): array` | Dati di esempio per l'anteprima: un elemento per elenco, costruito dai campi dichiarati se manca l'esempio. |

<!-- riferimento:fine -->

# Registro delle versioni del manuale

| Versione | Data | Modifiche |
|---|---|---|
| 1.0.0 | 16 novembre 2026 | prima versione, per pfi/modelli 1.0.0 |
