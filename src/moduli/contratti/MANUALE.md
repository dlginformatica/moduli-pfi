---
titolo: "Manuale di pfi/contratti"
sottotitolo: "Moduli universali del Portale di autoformazione IT: interfacce comuni, versioni, manifesto e caricatore"
versione: "1.0.0"
data: "2026-11-16"
verifica_fonti: "2026-11-16"
prossima_revisione: "a ogni nuova versione del modulo"
rilascio: "pfi/contratti 1.0.0"
autori: "Gaetano Giarrusso e Ufficio USIS"
hash: "n.d. (calcolato nel MANIFEST)"
classificazione: "USO INTERNO"
---

# Manuale di pfi/contratti

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale. Copyright 2026 Gaetano Giarrusso, licenza GPL v3 o successiva.

Il manuale è scritto per uno sviluppatore che riusa il modulo in un'applicazione PHP diversa dal portale e per un agente di intelligenza artificiale che deve scrivere codice che lo usa. Il riferimento dell'interfaccia (capitolo 11) è estratto dal codice con `tools/moduli_riferimento.php`; gli esempi sono eseguiti in un progetto pulito con `tools/moduli_esempi.py` e l'uscita riportata è quella ottenuta. La guida generale dei moduli (`src/moduli/MANUALE-GENERALE.md`) spiega distribuzione, versioni e regole comuni.

# 1. A che cosa serve

`pfi/contratti` è la base di tutti gli altri moduli universali. Contiene:

- le **interfacce comuni** che i moduli si scambiano senza conoscersi: `Registro` (registro degli eventi), `Segreti` (credenziali e chiavi), `ProvaDiSalute` con il suo `EsitoSalute`;
- `RegistroNullo`, un registro che non scrive nulla, da passare ai moduli quando l'applicazione non ha ancora un registro;
- `Versione`, che legge le versioni semantiche e valuta i vincoli nella forma di Composer;
- `Manifesto`, che legge e controlla il file `modulo.json` di un modulo e calcola i requisiti mancanti;
- `Caricatore`, che carica le classi dei moduli installati sul server senza Composer;
- `Eccezione`, l'eccezione di base, con messaggi in italiano.

Va usato direttamente quando l'applicazione scrive un proprio modulo compatibile (con manifesto e prove di salute), quando deve controllare versioni e dipendenze, o quando carica i moduli dal server senza Composer. Le applicazioni che usano soltanto un modulo come `pfi/registro` lo ricevono comunque come dipendenza e ne usano le interfacce in modo indiretto.

Non serve per registrare eventi (usare `pfi/registro`), per conservare segreti (`pfi/segreti`) o per eseguire le prove di salute in blocco (`pfi/diagnostica`).

# 2. Installazione

Requisiti: PHP 8.2 o successivo; nessuna estensione oltre a quelle standard; nessuna dipendenza.

Con Composer, dopo aver dichiarato il repository dei moduli (guida generale, capitolo 3):

```bash
composer require pfi/contratti:^1.0
```

Senza Composer, sul server del portale i moduli sono in `/usr/local/lib/pfi/moduli/<nome>/<versione>/` con il collegamento `attuale` alla versione in uso:

```php
require '/usr/local/lib/pfi/moduli/contratti/attuale/avvio.php';
\Pfi\Contratti\Caricatore::registra('/usr/local/lib/pfi/moduli');
```

`avvio.php` carica il caricatore e registra `pfi/contratti`; `registra()` registra poi tutti gli altri moduli della cartella. Un'applicazione con Composer non deve usare il caricatore: l'autoload di Composer basta.

# 3. Concetti

**Versione e vincoli.** Le versioni sono `MAGGIORE.MINORE.CORREZIONE`. La maggiore cambia quando l'interfaccia pubblica cambia in modo incompatibile, la minore quando si aggiungono funzioni compatibili, la correzione per le correzioni. I vincoli seguono Composer: `1.2.3` (esatta), `^1.2` (da 1.2.0 a meno di 2.0.0), `~1.2` (da 1.2.0 a meno di 2.0.0 per due cifre, da 1.2.3 a meno di 1.3.0 per tre), `>=1.0 <2.0` (intervallo, gli spazi separano condizioni in «e»), `*` (qualsiasi) e alternative con `||`. Non sono gestite le etichette di pre-rilascio come `1.0.0-beta`.

**Manifesto.** Ogni modulo ha un `modulo.json` con nome (`pfi/<nome>`), versione, descrizione, spazio dei nomi PSR-4 con la barra rovesciata finale, versione di PHP richiesta, moduli richiesti con il vincolo, e l'elenco delle classi di prova di salute. Il manifesto è letto dal caricatore, dal gestore dei moduli e dagli strumenti di controllo.

**Prova di salute.** È una verifica rapida, in sola lettura e senza effetti sui dati, che risponde «il modulo è in grado di lavorare qui?». Restituisce un `EsitoSalute` con stato `buono`, `avviso` o `guasto`, un messaggio in italiano senza segreti e dettagli facoltativi. Le prove si eseguono prima e dopo un aggiornamento (gestore) e su richiesta (diagnostica).

**Registro.** L'interfaccia ha un solo metodo, `registra($livello, $messaggio, $contesto)`, con gli otto livelli di RFC 5424 nei nomi di PSR-3 (`emergency` … `debug`) e messaggi con segnaposto `{chiave}` riempiti dal contesto. I moduli ricevono un `Registro` nel costruttore e non sanno dove finiscono gli eventi.

# 4. Uso passo per passo

L'esempio mostra le quattro funzioni più usate: confronto di versioni, lettura di un manifesto con i requisiti mancanti, una prova di salute scritta dall'applicazione e il registro nullo.

<!-- esempio:uso.php:inizio -->
```php
<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/contratti fuori da Moodle: versioni, manifesto, prova di salute, registro nullo.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Contratti\EsitoSalute;
use Pfi\Contratti\Manifesto;
use Pfi\Contratti\ProvaDiSalute;
use Pfi\Contratti\RegistroNullo;
use Pfi\Contratti\Versione;

// 1. Versioni e vincoli nella forma di Composer.
$v = Versione::da_testo('1.4.2');
foreach (['^1.0', '~1.3', '>=1.0 <1.4', '^2.0 || ^1.4'] as $vincolo) {
    printf("%s soddisfa %-14s %s\n", $v, $vincolo, $v->soddisfa($vincolo) ? 'sì' : 'no');
}

// 2. Manifesto di un modulo dell'applicazione e requisiti mancanti.
$manifesto = Manifesto::da_json(json_encode([
    'nome' => 'pfi/fatture',
    'versione' => '0.1.0',
    'descrizione' => 'Modulo di prova',
    'spazio' => 'App\\Fatture\\',
    'richiede' => ['pfi/contratti' => '^1.0', 'pfi/registro' => '^1.0'],
]));
$disponibili = ['pfi/contratti' => Versione::da_testo('1.0.0')];
echo "Requisiti mancanti di {$manifesto->nome}: " . implode('; ', $manifesto->requisiti_mancanti($disponibili, '8.3.0')) . "\n";

// 3. Una prova di salute: rapida, in sola lettura, messaggio in italiano senza segreti.
final class CartellaScrivibile implements ProvaDiSalute {
    public function __construct(private string $cartella) {}
    public function codice(): string { return 'fatture.cartella'; }
    public function descrizione(): string { return 'La cartella delle fatture esiste ed è scrivibile'; }
    public function esegui(): EsitoSalute {
        return is_dir($this->cartella) && is_writable($this->cartella)
            ? EsitoSalute::buono('Cartella scrivibile')
            : EsitoSalute::guasto('Cartella assente o non scrivibile', ['cartella' => $this->cartella]);
    }
}
$esito = (new CartellaScrivibile(sys_get_temp_dir()))->esegui();
echo "Prova fatture.cartella: {$esito->stato} ({$esito->messaggio})\n";

// 4. Registro nullo: lo si passa ai moduli quando l'applicazione non ha ancora un registro.
(new RegistroNullo())->registra(RegistroNullo::INFORMAZIONE, 'Avvio di {app}', ['app' => 'fatture']);
echo "Livello numerico di 'warning': " . RegistroNullo::LIVELLI['warning'] . "\n";
```

Uscita (verificata eseguendo l'esempio in un progetto pulito):

```text
1.4.2 soddisfa ^1.0           sì
1.4.2 soddisfa ~1.3           sì
1.4.2 soddisfa >=1.0 <1.4     no
1.4.2 soddisfa ^2.0 || ^1.4   sì
Requisiti mancanti di pfi/fatture: manca pfi/registro («^1.0»)
Prova fatture.cartella: buono (Cartella scrivibile)
Livello numerico di 'warning': 4
```
<!-- esempio:uso.php:fine -->

# 5. Configurazione

Il modulo non ha configurazione. I soli parametri sono gli argomenti dei metodi: la cartella base del caricatore (sul portale `/usr/local/lib/pfi/moduli`) e, per `requisiti_mancanti()`, la versione di PHP da usare al posto di quella in esecuzione (utile per controllare in anticipo un server diverso).

# 6. Errori ed eccezioni

Tutti gli errori sono `Pfi\Contratti\Eccezione` (estende `RuntimeException`) con messaggio in italiano:

| Situazione | Messaggio (inizio) |
|---|---|
| JSON del manifesto non leggibile | «Manifesto non valido: JSON non leggibile» |
| campo obbligatorio mancante o vuoto | «Manifesto non valido: manca il campo «…»» |
| nome non nella forma `pfi/<nome>` | «Manifesto non valido: nome …» |
| spazio dei nomi senza barra finale o con caratteri non ammessi | «Manifesto non valido: spazio dei nomi …» |
| vincolo di versione con sintassi errata | eccezione di `Versione` sul vincolo |
| versione non leggibile | eccezione di `Versione::da_testo()` |

`requisiti_mancanti()` non lancia eccezioni per requisiti assenti: restituisce l'elenco dei problemi in italiano, vuoto se tutto è soddisfatto. Il caricatore ignora le cartelle senza `attuale/modulo.json` e non lancia eccezioni se una classe non esiste (lascia proseguire gli altri caricatori).

# 7. Sicurezza

- Le interfacce `Segreti` e `Registro` fissano due regole che tutti i moduli rispettano: un segreto non va mai scritto nel registro né mostrato, per riconoscerlo si usa l'impronta (`impronta()`); il contesto del registro non deve contenere segreti.
- Il caricatore esegue codice PHP dalle cartelle dei moduli: la cartella base deve essere scrivibile solo da root (sul portale lo è). Non registrare cartelle scrivibili dal server web.
- I messaggi delle prove di salute vengono mostrati agli amministratori: non devono contenere password, token o dati personali.

# 8. Prove di salute

Il modulo non dichiara prove proprie (il suo manifesto ha `salute` vuoto): definisce l'interfaccia che gli altri moduli implementano. Per scrivere una prova: implementare `ProvaDiSalute`, dare un codice stabile nella forma `<modulo>.<aspetto>`, restare sotto un secondo di esecuzione, non modificare dati, restituire `EsitoSalute::avviso()` per una condizione accettabile ma da guardare e `guasto()` per una condizione che impedisce il funzionamento. Il costruttore non deve avere argomenti obbligatori se la prova va elencata nel manifesto.

# 9. Uso nel portale

Nel portale Moodle i moduli sono caricati dal plugin `tool_pfimoduli` (classe `tool_pfimoduli\moduli`) con `avvio.php` e `Caricatore::registra()` dalla cartella indicata in `$CFG->pfi_moduli` (predefinita `/usr/local/lib/pfi/moduli`), con il controllo delle versioni collaudate; la prova MU1 di `pfi-verifica` controlla che i dodici moduli installati abbiano la versione collaudata. Questo capitolo è solo informativo: un'altra applicazione non dipende da Moodle.

# 10. Limiti noti

- `Versione` non gestisce le etichette di pre-rilascio né i metadati di build (`1.0.0+build`): i moduli usano solo versioni stabili.
- Il caricatore registra un modulo per nome: se due cartelle base contengono lo stesso modulo vince la prima registrata.
- Il caricatore cerca i file con la regola PSR-4 di base: una classe per file, nome del file uguale al nome della classe.

# 11. Indicazioni per gli agenti

- Dichiarare `pfi/contratti` con il vincolo `^1.0`; non copiare le sue classi nell'applicazione.
- Passare sempre ai moduli un oggetto che implementa `Pfi\Contratti\Registro`; se non c'è un registro usare `new RegistroNullo()`, mai `null`.
- Per controllare compatibilità usare `Versione::soddisfa()` e `Manifesto::requisiti_mancanti()` invece di confronti fra stringhe.
- Una prova di salute non scrive nulla e non chiama servizi lenti; il messaggio è in italiano e senza segreti.
- Con Composer non chiamare il caricatore: si rischia di caricare due copie dello stesso modulo.

# 12. Riferimento dell'interfaccia

Generato dal codice della versione 1.0.0; lo spazio dei nomi è `Pfi\Contratti\`.

<!-- riferimento:inizio -->
#### `Caricatore`

Classe finale. Caricatore delle classi dei moduli installati sul server: legge da una cartella base le sottocartelle <nome>/attuale/ (collegamento alla versione in uso), il loro modulo.json e registra il caricamento PSR-4 dello spazio dei nomi del modulo dalla cartella src/. Un'applicazione che usa Composer non ne ha bisogno.

| Metodo | Descrizione |
|---|---|
| `static registra(string $base): array` | Registra i moduli della cartella base; si può chiamare più volte (le cartelle successive non sostituiscono i moduli già registrati). |
| `static registra_cartella(string $cartella): Pfi\Contratti\Manifesto` | Registra un singolo modulo da una cartella che contiene modulo.json e src/ (prove, sviluppo). |
| `static moduli(): array` |  |
| `static carica(string $classe): void` |  |

#### `Eccezione`

Classe, estende `RuntimeException`; implementa `Throwable`. Eccezione di base dei moduli universali: i moduli la estendono o la usano con un messaggio in italiano.

#### `EsitoSalute`

Classe finale. Esito di una prova di salute: stato, messaggio in italiano senza segreti e dettagli strutturati facoltativi.

Costanti: `BUONO = 'buono'`, `AVVISO = 'avviso'`, `GUASTO = 'guasto'`.

Proprietà pubbliche: `readonly string $stato`, `readonly string $messaggio`, `readonly array $dettagli`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $stato, promossa string $messaggio, promossa array $dettagli = [])` |  |
| `static buono(string $messaggio, array $dettagli = []): self` |  |
| `static avviso(string $messaggio, array $dettagli = []): self` |  |
| `static guasto(string $messaggio, array $dettagli = []): self` |  |

#### `Manifesto`

Classe finale. Manifesto di un modulo («modulo.json»): identità, versione, requisiti e prove di salute.

Proprietà pubbliche: `readonly string $nome`, `readonly Pfi\Contratti\Versione $versione`, `readonly string $descrizione`, `readonly string $spazio`, `readonly string $php`, `readonly array $richiede`, `readonly array $salute`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $nome, promossa Pfi\Contratti\Versione $versione, promossa string $descrizione, promossa string $spazio, promossa string $php, promossa array $richiede, promossa array $salute)` |  |
| `static da_file(string $percorso): self` |  |
| `static da_json(string $json): self` |  |
| `requisiti_mancanti(array $disponibili, ?string $versionephp = null): array` | Requisiti non soddisfatti dai moduli disponibili. |

#### `ProvaDiSalute`

Interfaccia. Prova di salute di un modulo: la eseguono la diagnostica (pfi/diagnostica, pfi-verifica) e il pannello dei moduli prima e dopo un aggiornamento. Deve essere rapida, in sola lettura e senza effetti sui dati.

| Metodo | Descrizione |
|---|---|
| `codice(): string` | Codice stabile della prova, per esempio «registro.scrittura». |
| `descrizione(): string` | Descrizione in italiano di che cosa controlla. |
| `esegui(): Pfi\Contratti\EsitoSalute` |  |

#### `Registro`

Interfaccia. Registro dell'applicazione: interfaccia minima comune a tutti i moduli, con i livelli di RFC 5424 e la stessa forma di PSR-3 (messaggio con segnaposto {chiave} e contesto). L'implementazione completa è il modulo pfi/registro; senza di esso si usa RegistroNullo.

Costanti: `EMERGENZA = 'emergency'`, `ALLARME = 'alert'`, `CRITICO = 'critical'`, `ERRORE = 'error'`, `AVVISO = 'warning'`, `NOTA = 'notice'`, `INFORMAZIONE = 'info'`, `DEBUG = 'debug'`, `LIVELLI = ['emergency' => 0, 'alert' => 1, 'critical' => 2, 'error' => 3, 'warning' => 4, 'notice' => 5, 'info' => 6, 'debug' => 7]`.

| Metodo | Descrizione |
|---|---|
| `registra(string $livello, string $messaggio, array $contesto = []): void` | Registra un evento. Il contesto non deve contenere segreti: il modulo pfi/registro li maschera comunque. |

#### `RegistroNullo`

Classe finale, implementa `Pfi\Contratti\Registro`. Registro che non scrive nulla: per le prove e per le applicazioni senza registro.

| Metodo | Descrizione |
|---|---|
| `registra(string $livello, string $messaggio, array $contesto = []): void` | Registra un evento. Il contesto non deve contenere segreti: il modulo pfi/registro li maschera comunque. |

#### `Segreti`

Interfaccia. Accesso ai segreti (credenziali, chiavi, token): interfaccia comune; l'implementazione è il modulo pfi/segreti. Il valore non va mai scritto nel registro né mostrato: per riconoscerlo si usa l'impronta.

| Metodo | Descrizione |
|---|---|
| `valore(string $nome): ?string` | Valore del segreto, o null se non c'è. |
| `presente(string $nome): bool` | Il segreto esiste ed è non vuoto. |
| `impronta(string $nome): ?string` | Impronta non reversibile del valore (per riconoscerlo senza mostrarlo), o null se non c'è. |

#### `Versione`

Classe finale. Versione semantica MAGGIORE.MINORE.CORREZIONE e vincoli nella forma di Composer: «1.2.3», «^1.2», «~1.2», «>=1.0 <2.0», «*» e alternative separate da «||».

Proprietà pubbliche: `readonly int $maggiore`, `readonly int $minore`, `readonly int $correzione`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa int $maggiore, promossa int $minore, promossa int $correzione)` |  |
| `static da_testo(string $testo): self` | Legge una versione «M.m.c» (anche «M.m» o «M», completate con zeri). |
| `__toString(): string` |  |
| `confronta(self $altra): int` | Confronto: negativo, zero o positivo come strcmp. |
| `soddisfa(string $vincolo): bool` | La versione soddisfa il vincolo. |

<!-- riferimento:fine -->

# Registro delle versioni del manuale

| Versione | Data | Modifiche |
|---|---|---|
| 1.0.0 | 16 novembre 2026 | prima versione, per pfi/contratti 1.0.0 |
