---
titolo: "Manuale di pfi/registro"
sottotitolo: "Moduli universali del Portale di autoformazione IT: registro degli eventi con mascheramento dei segreti, file, tabella e syslog"
versione: "1.0.0"
data: "2026-11-16"
verifica_fonti: "2026-11-16"
prossima_revisione: "a ogni nuova versione del modulo"
rilascio: "pfi/registro 1.0.0"
autori: "Gaetano Giarrusso e Ufficio USIS"
hash: "n.d. (calcolato nel MANIFEST)"
classificazione: "USO INTERNO"
---

# Manuale di pfi/registro

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale. Copyright 2026 Gaetano Giarrusso, licenza GPL v3 o successiva.

Il manuale è scritto per uno sviluppatore che riusa il modulo in un'applicazione PHP diversa dal portale e per un agente di intelligenza artificiale che deve scrivere codice che lo usa. Il riferimento dell'interfaccia (capitolo 12) è estratto dal codice; gli esempi sono eseguiti in un progetto pulito e l'uscita riportata è quella ottenuta. Distribuzione, versioni e regole comuni sono nella guida generale (`src/moduli/MANUALE-GENERALE.md`).

# 1. A che cosa serve

`pfi/registro` registra gli eventi di un'applicazione e li invia a una o più **destinazioni**: un file con una riga JSON per evento, una tabella di database, un server syslog (UDP, TCP, TLS o il syslog del server), la memoria. Ogni destinazione ha un proprio livello minimo. Prima di raggiungere qualsiasi destinazione, messaggio e contesto passano dal **mascheratore**, che sostituisce password, token, chiavi e credenziali con `[MASCHERATO]`.

Il registro implementa l'interfaccia `Pfi\Contratti\Registro` (gli otto livelli di PSR-3 e i metodi `error()`, `info()`, `log()` e simili), quindi si passa a tutti gli altri moduli universali. Un identificativo di **correlazione** permette di seguire un'operazione che attraversa più moduli.

Una destinazione che fallisce non interrompe né le altre né l'applicazione: l'errore si conserva in memoria e lo segnala la prova di salute.

Usarlo per il registro applicativo e di sicurezza di un'applicazione PHP. Non è un sistema di raccolta centralizzata: per quello si invia al syslog dell'organizzazione.

# 2. Installazione

Requisiti: PHP 8.2 o successivo; `pfi/contratti` ^1.0; l'estensione PDO con il driver del database se si usa la destinazione `Tabella`; l'estensione `openssl` per il syslog su TLS.

```bash
composer require pfi/registro:^1.0
```

La cartella dei file di registro deve esistere ed essere scrivibile dall'utente del servizio; il modulo non la crea.

# 3. Concetti

**Livelli.** `emergency`, `alert`, `critical`, `error`, `warning`, `notice`, `info`, `debug` (gravità da 0 a 7, come in RFC 5424). Le costanti sono in `Pfi\Contratti\Registro` (`Registro::ERRORE`, `Registro::AVVISO`, `Registro::INFORMAZIONE`, `Registro::DEBUG` e le altre). Una destinazione aggiunta con livello minimo `warning` riceve `warning` e tutti i livelli più gravi.

**Messaggio e contesto.** Il messaggio può contenere segnaposto `{nome}` che si sostituiscono con i valori del contesto. Il contesto accetta scalari, liste e array annidati fino a tre livelli; un'eccezione diventa «classe: messaggio mascherato», una data il formato RFC 3339, un oggetto il nome della sua classe. Le chiavi speciali `modulo` e `correlazione` del contesto sostituiscono, per un solo evento, quelle del registro.

**Mascheramento.** Tre regole, applicate sia al contesto sia al messaggio finale: (a) il valore di una chiave che sembra sensibile (password, pwd, secret, segreto, token, chiave, key, authorization, cookie, credenziali, firma, signature, sessione, private, bearer) diventa `[MASCHERATO]`; (b) forme riconoscibili nel testo: `password=…`, `token: …`, `Authorization: …`, `Cookie: …`, `Bearer …`, `Basic …`, credenziali in un indirizzo (`https://utente:password@host`); (c) valori registrati con `valore_segreto()` (almeno 6 caratteri), sostituiti dovunque compaiano.

**Modulo e correlazione.** `per_modulo('pfi/x')` restituisce una copia del registro con le stesse destinazioni e un altro nome di modulo predefinito: si passa ai moduli. `con_correlazione()` restituisce una copia con un nuovo identificativo (16 caratteri esadecimali casuali) per una nuova operazione.

**Voce.** L'evento già pronto (`Pfi\Registro\Voce`): data, livello, messaggio interpolato e mascherato, modulo, correlazione, contesto mascherato. Le destinazioni ricevono solo voci.

**Destinazioni.**

| Classe | Uso | Note |
|---|---|---|
| `Destinazioni\File` | una riga JSON per evento | file creato con permessi 0640; rotazione quando supera la dimensione massima (predefinita 10 MiB), conservazione per numero di file (10) e per giorni (90); `ruota()` e `conserva()` si possono chiamare dal cron |
| `Destinazioni\Tabella` | tabella via PDO (PostgreSQL, MySQL o MariaDB, SQLite) | colonne `id`, `quando` (secondi Unix), `livello`, `gravita`, `modulo`, `correlazione`, `messaggio`, `contesto` (JSON); `crea_tabella()`, `cerca($filtri, $limite, $da)`, `conserva($giorni)` |
| `Destinazioni\Syslog` | `udp`, `tcp`, `tls` o `locale` | RFC 5424; su TCP e TLS ogni evento è preceduto dalla sua lunghezza (RFC 6587); con TLS il certificato del server si verifica sempre |
| `Destinazioni\Memoria` | prove e riepilogo di un'operazione | conserva al massimo 1000 voci (configurabile) nella proprietà `$voci` |

**RFC 5424.** `Rfc5424` costruisce la riga syslog: priorità da struttura (`user`, `daemon`, `auth`, `authpriv`, `local0`…`local7`) e livello; dati strutturati `[pfi@32473 modulo correlazione livello]` e `[pfictx@32473 …]` con il contesto. La riga contiene un BOM UTF-8 prima del messaggio, come prevede la norma (invisibile nell'esempio).

# 4. Uso passo per passo

L'esempio configura tre destinazioni con livelli diversi, registra quattro eventi, mostra il mascheramento delle tre regole, la ricerca nella tabella, la forma RFC 5424 di un evento e la prova di salute. Orologio e correlazione sono fissati solo per rendere l'uscita ripetibile.

<!-- esempio:uso.php:inizio -->
```php
<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/registro fuori da Moodle: destinazioni con livello minimo, mascheramento, correlazione, RFC 5424.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Registro\Destinazioni\File;
use Pfi\Registro\Destinazioni\Memoria;
use Pfi\Registro\Destinazioni\Tabella;
use Pfi\Registro\Mascheratore;
use Pfi\Registro\Registro;
use Pfi\Registro\Rfc5424;
use Pfi\Registro\Salute\Destinazioni;

$cartella = __DIR__ . '/registri';
@mkdir($cartella, 0750);

// Orologio e correlazione fissi solo per rendere ripetibile l'uscita dell'esempio.
$orologio = fn() => new DateTimeImmutable('2026-11-16T09:30:00+01:00');
$mascheratore = new Mascheratore();
$mascheratore->valore_segreto('Xq7-segreto-letto-dal-deposito');

$memoria = new Memoria();
$tabella = new Tabella(new PDO('sqlite:' . $cartella . '/registro.sqlite'));
$tabella->crea_tabella();
$registro = (new Registro($mascheratore, 'fatture', 'a1b2c3d4e5f60718', $orologio))
    ->aggiungi($memoria)                                                   // tutto, da debug in su
    ->aggiungi(new File($cartella . '/registro.log'), Registro::INFORMAZIONE)
    ->aggiungi($tabella, Registro::AVVISO);                                // solo avvisi e più gravi

// 1. Segnaposto {nome} sostituiti dal contesto; chiavi sensibili, forme nel testo e valori noti mascherati.
$registro->info('Fattura {numero} inviata a {destinatario}', ['numero' => 'F-2026-118', 'destinatario' => 'ufficio@example.org']);
$registro->error('Invio non riuscito: {errore}', [
    'errore' => 'risposta 401 per https://utente:pw1234@api.example.org/invio',
    'password' => 'non-deve-comparire',
    'token_api' => 'Xq7-segreto-letto-dal-deposito',
    'nota' => 'chiave usata: Xq7-segreto-letto-dal-deposito',
]);
$registro->debug('Dettaglio tecnico visibile solo in memoria');

// 2. Un altro modulo con le stesse destinazioni e la stessa correlazione.
$registro->per_modulo('pfi/comunicazione')->warning('Coda di invio lenta', ['in_coda' => 12]);

echo "Eventi in memoria: " . count($memoria->voci) . "\n";
foreach ($memoria->voci as $v) {
    echo $v->json(), "\n";
}
echo "\nRighe nel file (livello info e superiori): " . count(file($cartella . '/registro.log')) . "\n";
echo "Righe nella tabella (warning e superiori): " . count($tabella->cerca()) . "\n";
echo "Ricerca nella tabella per modulo pfi/comunicazione: "
    . $tabella->cerca(['modulo' => 'pfi/comunicazione'])[0]['messaggio'] . "\n";

// 3. Lo stesso evento nella forma RFC 5424 inviata ai syslog (host e pid fissi per l'esempio).
echo "\n", (new Rfc5424('local0', 'server-app', 'fatture', 4242))->formatta($memoria->voci[1]), "\n";

// 4. Prova di salute del registro configurato.
$esito = (new Destinazioni($registro))->esegui();
echo "\nSalute: {$esito->stato}, {$esito->messaggio}\n";
```

Uscita (verificata eseguendo l'esempio in un progetto pulito):

```text
Eventi in memoria: 4
{"quando":"2026-11-16T09:30:00.000+01:00","livello":"info","modulo":"fatture","correlazione":"a1b2c3d4e5f60718","messaggio":"Fattura F-2026-118 inviata a ufficio@example.org","contesto":{"numero":"F-2026-118","destinatario":"ufficio@example.org"}}
{"quando":"2026-11-16T09:30:00.000+01:00","livello":"error","modulo":"fatture","correlazione":"a1b2c3d4e5f60718","messaggio":"Invio non riuscito: risposta 401 per https://utente:[MASCHERATO]@api.example.org/invio","contesto":{"errore":"risposta 401 per https://utente:[MASCHERATO]@api.example.org/invio","password":"[MASCHERATO]","token_api":"[MASCHERATO]","nota":"chiave usata: [MASCHERATO]"}}
{"quando":"2026-11-16T09:30:00.000+01:00","livello":"debug","modulo":"fatture","correlazione":"a1b2c3d4e5f60718","messaggio":"Dettaglio tecnico visibile solo in memoria","contesto":[]}
{"quando":"2026-11-16T09:30:00.000+01:00","livello":"warning","modulo":"pfi/comunicazione","correlazione":"a1b2c3d4e5f60718","messaggio":"Coda di invio lenta","contesto":{"in_coda":12}}

Righe nel file (livello info e superiori): 3
Righe nella tabella (warning e superiori): 2
Ricerca nella tabella per modulo pfi/comunicazione: Coda di invio lenta

<131>1 2026-11-16T08:30:00.000000Z server-app fatture 4242 fatture [pfi@32473 modulo="fatture" correlazione="a1b2c3d4e5f60718" livello="error"][pfictx@32473 errore="risposta 401 per https://utente:[MASCHERATO\]@api.example.org/invio" password="[MASCHERATO\]" token_api="[MASCHERATO\]" nota="chiave usata: [MASCHERATO\]"] ﻿Invio non riuscito: risposta 401 per https://utente:[MASCHERATO]@api.example.org/invio

Salute: buono, Destinazioni del registro in ordine.
```
<!-- esempio:uso.php:fine -->

Configurazione tipica in produzione (file locale da `info` e syslog centrale su TLS da `warning`):

```php
use Pfi\Registro\{Registro, Mascheratore, Rfc5424};
use Pfi\Registro\Destinazioni\{File, Syslog};

$registro = (new Registro(new Mascheratore(), 'mia-applicazione'))
    ->aggiungi(new File('/var/log/mia-applicazione/registro.log'), Registro::INFORMAZIONE)
    ->aggiungi(new Syslog('tls', 'syslog.example.org', 6514, new Rfc5424('local0', null, 'mia-applicazione'), 2.0,
        ['ca' => '/etc/ssl/certs/ca-certificates.crt']), Registro::AVVISO);
```

# 5. Configurazione

| Costruttore | Parametri e predefiniti |
|---|---|
| `Registro` | `$mascheratore` (nuovo `Mascheratore`), `$modulo` (`'applicazione'`), `$correlazione` (casuale), `$orologio` (closure che restituisce l'ora; ora corrente) |
| `File` | `$percorso`, `$dimensionemassima` (10 MiB, minimo 1024 byte), `$filemassimi` (10), `$giorni` (90) |
| `Tabella` | `$pdo`, `$tabella` (`'pfi_registro'`; solo lettere, cifre e trattino basso) |
| `Syslog` | `$trasporto`, `$host` (`127.0.0.1`), `$porta` (514), `$formato` (`Rfc5424` con `local0`), `$attesa` in secondi (2.0), `$tls` (`ca`, `certificato`, `chiave`, `nome` atteso nel certificato) |
| `Rfc5424` | `$struttura` (`local0`), `$host` (nome del server), `$app` (`'pfi'`), `$pid` (processo corrente), `$pen` (32473) |
| `Memoria` | `$massimo` (1000) |

Porte usuali: 514 per UDP e TCP, 6514 per TLS. Il livello minimo di una destinazione è il secondo argomento di `aggiungi()` (predefinito `debug`).

# 6. Errori ed eccezioni

Le eccezioni (`Pfi\Contratti\Eccezione`) riguardano solo la configurazione; gli errori di scrittura non escono dal registro.

| Situazione | Messaggio |
|---|---|
| livello inesistente in `aggiungi()` o `registra()` | «Livello sconosciuto: «…»» |
| parametri di rotazione del file non validi | «Rotazione del registro su file non valida: …» |
| nome di tabella con caratteri non ammessi | «Nome di tabella del registro non valido.» |
| conservazione della tabella inferiore a un giorno | «La conservazione del registro è di almeno un giorno.» |
| livello inesistente nei filtri di `cerca()` | «Livello sconosciuto nel filtro.» |
| trasporto syslog sconosciuto o porta fuori intervallo | «Trasporto syslog sconosciuto: «…»», «Porta syslog non valida.» |
| struttura syslog sconosciuta | «Struttura syslog sconosciuta: «…»» |

Gli errori di scrittura delle destinazioni (file non scrivibile, syslog irraggiungibile, database non disponibile) si conservano, mascherati, in `guasti()` (gli ultimi 20) e portano la prova di salute ad `avviso`.

# 7. Sicurezza

- Il mascheramento è una protezione in più, non un permesso di registrare segreti: chi scrive il codice non mette password nei messaggi. Le forme riconosciute sono quelle comuni; un segreto in forma imprevista si maschera solo se passato con una chiave sensibile o registrato con `valore_segreto()`.
- Registrare con `valore_segreto()` i segreti letti dal deposito (`pfi/segreti`) all'avvio dell'applicazione: così sono mascherati anche se finiscono in un messaggio d'errore di terze parti.
- I file sono creati con permessi 0640: la cartella deve appartenere all'utente del servizio e al gruppo di chi legge i registri.
- I dati personali (indirizzi di posta, nomi) non sono mascherati: si registrano solo se servono e con i tempi di conservazione decisi dall'organizzazione (`conserva()`).
- Su UDP gli eventi si perdono senza avviso se il server non li riceve; per eventi di sicurezza usare TCP o TLS.

# 8. Prove di salute

| Codice | Classe | Che cosa controlla |
|---|---|---|
| `registro.destinazioni` | `Pfi\Registro\Salute\Destinazioni` | ogni destinazione: file scrivibile, tabella leggibile, syslog TCP/TLS raggiungibile (UDP sempre `avviso`, perché non ha conferma); errori di scrittura recenti; nessuna destinazione configurata dà `avviso` |

Costruita senza registro (dal manifesto) dà `avviso`; l'applicazione la passa alla diagnostica come sostituzione con il proprio registro.

# 9. Uso nel portale

Nel portale il registro è configurato dall'adattatore `tool_pfiregistro` (destinazioni, livelli e conservazione nelle impostazioni di Moodle, consultazione degli eventi in una pagina dell'amministrazione) e il plugin `logstore_pfisyslog` invia al syslog anche gli eventi standard di Moodle. Informazione utile solo per confronto.

# 10. Limiti noti

- Il numero di impresa 32473 dei dati strutturati è riservato agli esempi (RFC 5612): per inviare a sistemi esterni all'organizzazione serve un numero assegnato dalla IANA `[DA VERIFICARE]`.
- La ricerca per testo nella tabella usa `LIKE` senza indice: sui registri grandi va limitata con il periodo (`dal`, `al`).
- Con TCP e TLS una connessione caduta si riapre una sola volta al successivo evento; se non riapre l'evento si perde e l'errore va in `guasti()`.
- La destinazione `locale` usa la funzione `syslog()` di PHP: intestazione e formato li decide il demone del server.

# 11. Indicazioni per gli agenti

- Passare ai moduli `$registro->per_modulo('pfi/<nome>')`, non un registro nuovo.
- Usare segnaposto e contesto (`'Invio a {destinatario}'`, `['destinatario' => …]`) invece di concatenare stringhe: il contesto resta strutturato nel file, nella tabella e nel syslog.
- Non mettere valori sensibili nel messaggio; se proprio servono nel contesto, usare chiavi che il mascheratore riconosce.
- Per eventi che appartengono alla stessa operazione usare la stessa correlazione; per una nuova operazione `con_correlazione()`.
- Nelle prove automatiche usare `Destinazioni\Memoria` e un orologio fisso.

# 12. Riferimento dell'interfaccia

Generato dal codice della versione 1.0.0; lo spazio dei nomi è `Pfi\Registro\`.

<!-- riferimento:inizio -->
#### `Destinazione`

Interfaccia. Destinazione degli eventi (tabella, file, syslog, memoria). Una destinazione non deve lanciare eccezioni per un evento non scritto se può evitarlo; se le lancia, il registro le intercetta e prosegue con le altre destinazioni.

| Metodo | Descrizione |
|---|---|
| `nome(): string` | Nome breve per la configurazione e i messaggi, per esempio «file» o «syslog tcp». |
| `scrivi(Pfi\Registro\Voce $voce): void` |  |
| `salute(): Pfi\Contratti\EsitoSalute` | Stato della destinazione per la diagnostica, senza scrivere eventi. |

#### `Destinazioni\File`

Classe finale, implementa `Pfi\Registro\Destinazione`. File di testo con una riga JSON per evento, rotazione per dimensione e conservazione dei file ruotati per numero e per giorni. Il file è creato con permessi 0640; la rotazione rinomina «registro.log» in «registro.log.1» eccetera.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $percorso, promossa int $dimensionemassima = 10485760, promossa int $filemassimi = 10, promossa int $giorni = 90)` |  |
| `nome(): string` | Nome breve per la configurazione e i messaggi, per esempio «file» o «syslog tcp». |
| `scrivi(Pfi\Registro\Voce $voce): void` |  |
| `ruota(): void` | Ruota i file e applica la conservazione; si può chiamare anche dal cron. |
| `conserva(?int $adesso = null): int` | Elimina i file ruotati più vecchi dei giorni di conservazione; restituisce quanti ne ha eliminati. |
| `salute(): Pfi\Contratti\EsitoSalute` | Stato della destinazione per la diagnostica, senza scrivere eventi. |

#### `Destinazioni\Memoria`

Classe finale, implementa `Pfi\Registro\Destinazione`. Destinazione in memoria, per le prove e per mostrare gli eventi di una singola operazione.

Proprietà pubbliche: `array $voci`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa int $massimo = 1000)` |  |
| `nome(): string` | Nome breve per la configurazione e i messaggi, per esempio «file» o «syslog tcp». |
| `scrivi(Pfi\Registro\Voce $voce): void` |  |
| `salute(): Pfi\Contratti\EsitoSalute` | Stato della destinazione per la diagnostica, senza scrivere eventi. |

#### `Destinazioni\Syslog`

Classe finale, implementa `Pfi\Registro\Destinazione`. Syslog secondo RFC 5424 (proposta 49 § 3.3).

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $trasporto, promossa string $host = '127.0.0.1', promossa int $porta = 514, promossa Pfi\Registro\Rfc5424 $formato = new Rfc5424(), promossa float $attesa = 2.0, promossa array $tls = [])` |  |
| `nome(): string` | Nome breve per la configurazione e i messaggi, per esempio «file» o «syslog tcp». |
| `scrivi(Pfi\Registro\Voce $voce): void` |  |
| `chiudi(): void` |  |
| `__destruct()` |  |
| `salute(): Pfi\Contratti\EsitoSalute` | Stato della destinazione per la diagnostica, senza scrivere eventi. |

#### `Destinazioni\Tabella`

Classe finale, implementa `Pfi\Registro\Destinazione`. Tabella di un database raggiunto con PDO (PostgreSQL, MySQL o MariaDB, SQLite). Fuori da Moodle si crea la tabella con crea_tabella(); nel portale la tabella è dichiarata da tool_pfiregistro e la scrittura passa dall'adattatore.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa PDO $pdo, promossa string $tabella = 'pfi_registro')` |  |
| `nome(): string` | Nome breve per la configurazione e i messaggi, per esempio «file» o «syslog tcp». |
| `crea_tabella(): void` |  |
| `scrivi(Pfi\Registro\Voce $voce): void` |  |
| `conserva(int $giorni, ?int $adesso = null): int` | Elimina gli eventi più vecchi di $giorni; restituisce quanti. |
| `cerca(array $filtri = [], int $limite = 100, int $da = 0): array` | Ricerca per la pagina di consultazione. |
| `static condizioni(array $filtri): array` |  |
| `salute(): Pfi\Contratti\EsitoSalute` | Stato della destinazione per la diagnostica, senza scrivere eventi. |

#### `Mascheratore`

Classe finale. Mascheramento dei segreti prima della scrittura (proposta 49 § 3.3).

Costanti: `MASCHERA = '[MASCHERATO]'`.

| Metodo | Descrizione |
|---|---|
| `valore_segreto(string $valore): void` | Registra un valore da non scrivere mai (lunghezza minima 6, per non mascherare parole comuni). |
| `chiave_sensibile(string $chiave): bool` |  |
| `testo(string $testo): string` |  |
| `contesto(array $contesto, int $profondita = 0): array` | Contesto pronto per la scrittura: valori scalari o liste di scalari, chiavi sensibili mascherate, eccezioni ridotte a classe e messaggio mascherato, oggetti ridotti alla classe. |

#### `Registro`

Classe finale, implementa `Pfi\Contratti\Registro`. Registro dei moduli universali (release 9.0, blocco M2; proposta 49 § 3.3).

| Metodo | Descrizione |
|---|---|
| `__construct(promossa Pfi\Registro\Mascheratore $mascheratore = new Mascheratore(), promossa string $modulo = 'applicazione', ?string $correlazione = null, ?Closure $orologio = null)` |  |
| `static nuova_correlazione(): string` | Identificativo casuale di 16 caratteri esadecimali. |
| `aggiungi(Pfi\Registro\Destinazione $destinazione, string $livellominimo = self::DEBUG): self` |  |
| `per_modulo(string $modulo): self` | Stesso registro e stesse destinazioni, con un altro modulo predefinito (per passarlo a un modulo). |
| `con_correlazione(?string $correlazione = null): self` | Stesso registro con un altro identificativo di correlazione (una nuova operazione). |
| `correlazione(): string` |  |
| `mascheratore(): Pfi\Registro\Mascheratore` |  |
| `destinazioni(): array` |  |
| `guasti(): array` | Messaggi degli errori delle destinazioni dall'avvio (senza segreti), per la diagnostica. |
| `registra(string $livello, string $messaggio, array $contesto = []): void` | Registra un evento. Il contesto non deve contenere segreti: il modulo pfi/registro li maschera comunque. |
| `static interpola(string $messaggio, array $contesto): string` |  |
| `emergency(Stringable\|string $m, array $c = []): void` |  |
| `alert(Stringable\|string $m, array $c = []): void` |  |
| `critical(Stringable\|string $m, array $c = []): void` |  |
| `error(Stringable\|string $m, array $c = []): void` |  |
| `warning(Stringable\|string $m, array $c = []): void` |  |
| `notice(Stringable\|string $m, array $c = []): void` |  |
| `info(Stringable\|string $m, array $c = []): void` |  |
| `debug(Stringable\|string $m, array $c = []): void` |  |
| `log(mixed $livello, Stringable\|string $m, array $c = []): void` | Forma di PSR-3: log($livello, $messaggio, $contesto). |

#### `Rfc5424`

Classe finale. Forma di un evento secondo RFC 5424 (The Syslog Protocol), § 6: PRI VERSION SP TIMESTAMP SP HOSTNAME SP APP-NAME SP PROCID SP MSGID SP STRUCTURED-DATA [SP BOM MSG].

Costanti: `PEN = 32473`, `STRUTTURE = [12 voci]`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $struttura = 'local0', ?string $host = null, string $app = 'pfi', promossa ?int $pid = null, promossa int $pen = self::PEN)` |  |
| `static campo(string $v, int $max): string` | Campo di intestazione: solo ASCII stampabile senza spazi (PRINTUSASCII), «-» se vuoto. |
| `static valore_sd(string $v): string` | Valore di un parametro di dati strutturati: «"», «\» e «]» preceduti da «\» (§ 6.3.3). |
| `static nome_sd(string $v): string` | Nome di un parametro (SD-NAME): fino a 32 caratteri ASCII stampabili esclusi «=», spazio, «]» e «"». |
| `app(): string` |  |
| `struttura(): string` |  |
| `priorita(Pfi\Registro\Voce $voce): int` |  |
| `formatta(Pfi\Registro\Voce $voce): string` |  |

#### `Salute\Destinazioni`

Classe finale, implementa `Pfi\Contratti\ProvaDiSalute`. Prova di salute del registro: ogni destinazione raggiungibile o scrivibile, nessun errore di scrittura recente. Senza registro (costruzione dal manifesto) dà un avviso: l'applicazione la sostituisce con un'istanza configurata.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa ?Pfi\Registro\Registro $registro = null)` |  |
| `codice(): string` | Codice stabile della prova, per esempio «registro.scrittura». |
| `descrizione(): string` | Descrizione in italiano di che cosa controlla. |
| `esegui(): Pfi\Contratti\EsitoSalute` |  |

#### `Voce`

Classe finale. Evento già pronto per le destinazioni: messaggio con i segnaposto sostituiti e contesto senza segreti.

Proprietà pubbliche: `readonly DateTimeImmutable $quando`, `readonly string $livello`, `readonly string $messaggio`, `readonly string $modulo`, `readonly string $correlazione`, `readonly array $contesto`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa DateTimeImmutable $quando, promossa string $livello, promossa string $messaggio, promossa string $modulo, promossa string $correlazione, promossa array $contesto = [])` |  |
| `gravita(): int` | Codice di gravità di RFC 5424 (0 emergenza … 7 debug). |
| `json(): string` | Forma JSON di una riga, usata dai file e dall'esportazione. |

<!-- riferimento:fine -->

# Registro delle versioni del manuale

| Versione | Data | Modifiche |
|---|---|---|
| 1.0.0 | 16 novembre 2026 | prima versione, per pfi/registro 1.0.0 |
