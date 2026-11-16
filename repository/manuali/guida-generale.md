---
titolo: "Moduli universali PFI: guida generale"
sottotitolo: "Distribuzione, versioni, installazione e uso dei moduli pfi/* in altre applicazioni PHP"
versione: "1.0.0"
data: "2026-11-16"
verifica_fonti: "2026-11-16"
prossima_revisione: "a ogni nuova versione di un modulo o degli strumenti di distribuzione"
rilascio: "moduli universali della release 9.0.1 del portale"
autori: "Gaetano Giarrusso e Ufficio USIS"
hash: "n.d. (calcolato nel MANIFEST)"
classificazione: "USO INTERNO"
---

# Moduli universali PFI: guida generale

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale. Copyright 2026 Gaetano Giarrusso, licenza GPL v3 o successiva.

Questa guida spiega come un'applicazione PHP nuova riusa i moduli universali nati con il Portale di autoformazione IT: dove si prendono, come si installano anche senza rete, come si scelgono e si aggiornano le versioni, come si pubblica una versione nuova e come si leggono i manuali. È scritta per uno sviluppatore e per un agente di intelligenza artificiale che lavora su quell'applicazione. Ogni modulo ha poi il proprio manuale, `<nome>/MANUALE.md`.

# 1. I dodici moduli

Sono librerie PHP indipendenti da Moodle, con nome Composer `pfi/<nome>`, spazio dei nomi `Pfi\<Nome>\`, licenza GPL-3.0-or-later, PHP 8.2 o successivo, nessuna libreria esterna.

| Modulo | Versione | A che cosa serve | Richiede |
|---|---|---|---|
| `pfi/contratti` | 1.0.0 | interfacce comuni (registro, segreti, prove di salute), manifesto dei moduli, versioni e vincoli, caricatore senza Composer | — |
| `pfi/segreti` | 1.0.0 | deposito cifrato di password, token e chiavi; impronta; rotazione della chiave | contratti, ext-sodium |
| `pfi/diagnostica` | 1.0.0 | esecuzione delle prove di salute, stato complessivo, rapporti JSON e Markdown | contratti |
| `pfi/registro` | 1.0.0 | registro degli eventi compatibile con PSR-3, mascheramento dei segreti, file, tabella, syslog | contratti |
| `pfi/connettori` | 1.0.0 | chiamate HTTP verso servizi esterni con tentativi, limiti di frequenza, proxy, fornitore finto | contratti, ext-curl |
| `pfi/esecutore` | 1.0.0 | operazioni privilegiate chieste dall'applicazione ed eseguite da un servizio di sistema con catalogo chiuso | contratti, ext-posix |
| `pfi/modelli` | 1.0.0 | modelli di testo Mustache senza logica, schemi dei segnaposti, versioni, anteprima | contratti, ext-mbstring |
| `pfi/comunicazione` | 1.0.0 | posta (SMTP e sette fornitori via API), Telegram, WhatsApp, coda con tentativi, ricezione IMAP e POP3 | contratti, connettori, modelli, ext-openssl, ext-mbstring |
| `pfi/esportazione` | 1.0.0 | tabelle in CSV, TSV, larghezza fissa, XLSX, ODS, DOCX, ODT, RTF, PDF, HTML, JSON, XML, Markdown | contratti, ext-mbstring, ext-zip, ext-iconv |
| `pfi/stampa` | 1.0.1 | documenti PDF dai modelli con Chromium, senza risorse esterne; codici QR | contratti, modelli, esportazione |
| `pfi/backup` | 1.0.0 | backup e ripristino granulari con impronte, cifratura, conservazione, copie in cartella o S3 | contratti, ext-sodium, ext-curl, ext-simplexml |
| `pfi/gestore` | 1.0.0 | installazione e aggiornamento dei moduli sul server con pacchetti firmati e ritorno indietro | contratti, diagnostica, ext-sodium |

Grafo delle dipendenze (una freccia significa «richiede»):

```
contratti  <-  segreti, diagnostica, registro, connettori, esecutore, modelli, esportazione, backup
diagnostica  <-  gestore
connettori, modelli  <-  comunicazione
modelli, esportazione  <-  stampa
```

Composer installa da solo i moduli richiesti: chiedere `pfi/stampa` porta con sé `pfi/contratti`, `pfi/modelli` e `pfi/esportazione`.

# 2. Dove si trovano

| Che cosa | Dove |
|---|---|
| Sorgenti, prove, esempi e manuali | cartella `src/moduli/` del progetto del portale; repository GitHub privato `moduli-pfi` (capitolo 7) |
| Repository Composer pronto | cartella generata con `tools/moduli_distribuzione.py` (capitolo 3); cartella `repository/` del repository `moduli-pfi`; sul PC di sviluppo `D:\Claude.portale.corsi.it\moduli-pfi\repository` |
| Manuali in Markdown e PDF | `<nome>/MANUALE.md` nei sorgenti; `manuali/<nome>.md` e `.pdf` nel repository Composer |
| Pacchetti firmati per il gestore | creati a richiesta con `tools/firma_moduli.php` (capitolo 4) |

# 3. Installare i moduli in un'applicazione con Composer

È il modo raccomandato. Il repository Composer è una cartella statica:

```
moduli-pfi/
  packages.json            indice dei pacchetti e delle versioni (con indirizzi assoluti)
  dist/pfi-<nome>-<versione>.zip
  manuali/<nome>.md, <nome>.pdf
  SHA256SUMS.txt
```

Si genera, o si aggiorna, dalla radice del progetto:

```bash
python3 tools/moduli_distribuzione.py /percorso/moduli-pfi --url file:///percorso/moduli-pfi/
# oppure, se la cartella è pubblicata da un server web interno:
python3 tools/moduli_distribuzione.py /srv/www/moduli-pfi --url https://server.interno/moduli-pfi/
```

Gli archivi sono riproducibili (stesso contenuto, stessi byte) e contengono `composer.json` con la versione, `modulo.json`, `LEGGIMI.md`, `CHANGELOG.md`, `avvio.php` (solo `contratti`), `src/` e `bin/`; non contengono le prove né il manuale. Se l'indirizzo cambia basta rigenerare `packages.json` con il nuovo `--url`: gli archivi non cambiano.

Nel `composer.json` dell'applicazione si sceglie uno dei due tipi di repository:

```json
{
    "repositories": [
        {"type": "composer", "url": "file:///percorso/moduli-pfi/"},
        {"packagist.org": false}
    ],
    "require": {
        "pfi/registro": "^1.0",
        "pfi/comunicazione": "^1.0"
    }
}
```

```json
{
    "repositories": [
        {"type": "artifact", "url": "/percorso/moduli-pfi/dist/"},
        {"packagist.org": false}
    ],
    "require": {"pfi/stampa": "^1.0"}
}
```

Il tipo `composer` legge `packages.json` (da disco o da un server web); il tipo `artifact` legge direttamente gli archivi della cartella `dist/` e non ha bisogno di `packages.json`. `{"packagist.org": false}` è facoltativo: serve quando l'applicazione non deve usare Internet, e in quel caso si installa con

```bash
COMPOSER_DISABLE_NETWORK=1 composer install
```

Entrambe le forme sono state provate senza rete in un progetto vuoto: con il tipo `artifact` la richiesta di `pfi/stampa` e `pfi/gestore` ha installato i sei moduli necessari. Il file `composer.lock` dell'applicazione fissa le versioni e le impronte: si conserva nel repository dell'applicazione.

Dopo l'installazione le classi si caricano con l'autoload di Composer (`require 'vendor/autoload.php';`). Un'applicazione con Composer non usa il caricatore di `pfi/contratti`.

# 4. Installare i moduli su un server senza Composer

Per server dove i moduli sono condivisi da più applicazioni, o aggiornati senza ricostruire l'applicazione, i moduli stanno in una cartella comune con le versioni affiancate:

```
/usr/local/lib/pfi/moduli/<nome>/<versione>/   e   /usr/local/lib/pfi/moduli/<nome>/attuale -> <versione>
```

Il caricamento nell'applicazione:

```php
require '/usr/local/lib/pfi/moduli/contratti/attuale/avvio.php';
\Pfi\Contratti\Caricatore::registra('/usr/local/lib/pfi/moduli');
```

Due strade per riempire la cartella:

- **dai sorgenti**: `python3 tools/moduli.py --installa /usr/local/lib/pfi/moduli` copia ogni modulo in `<nome>/<versione>` e sposta `attuale` (è quello che fa il kit del portale alla prima installazione);
- **con pacchetti firmati e il gestore** (manuale di `pfi/gestore`): chi pubblica firma il pacchetto con la propria chiave privata, `php tools/firma_moduli.php pacchetto src/moduli/<nome> <destinazione> <chiave privata>`; sul server `pfi-moduli installa <archivio> <autore>` controlla firma e dipendenze, installa, esegue le prove di salute e, se falliscono, torna alla versione precedente.

# 5. Versioni

**Numerazione semantica** `MAGGIORE.MINORE.CORREZIONE`: la maggiore cambia quando l'interfaccia pubblica cambia in modo incompatibile, la minore quando si aggiungono funzioni compatibili, la correzione per le correzioni. I vincoli si scrivono come in Composer: `^1.0` accetta tutte le 1.x dalla 1.0.0, `~1.3` le 1.3.x e successive 1.x, `>=1.0 <1.4` un intervallo, `^1.4 || ^2.0` due serie.

**Regole di progetto.**

- La versione è nel `modulo.json`; il `composer.json` del sorgente non ha il campo `version`, che `moduli_distribuzione.py` scrive nell'archivio.
- `CHANGELOG.md` ha una sezione per versione, la più recente in cima, e la prima voce coincide con il manifesto (lo controlla `tools/moduli.py`).
- **Una versione pubblicata non cambia più.** Se si modifica il codice di un modulo senza aumentare la versione, `moduli_distribuzione.py` si ferma con «esiste già con contenuto diverso». Il manuale invece si può migliorare senza cambiare versione: non è nell'archivio.
- Nel repository Git ogni versione pubblicata ha un'etichetta `pfi-<nome>-<versione>` (per esempio `pfi-stampa-1.0.1`) sul commit da cui è stato generato l'archivio.
- Un'applicazione richiede i moduli con `^MAGGIORE.MINORE` e aggiorna con `composer update pfi/<nome>`; il portale fissa invece la **combinazione collaudata** di ogni sua release e la controlla con `Gestore::scostamenti()`.

# 6. Pubblicare una versione nuova di un modulo

1. Modificare il codice in `src/moduli/<nome>/src/` e aggiungere o aggiornare le prove in `tests/`.
2. Aumentare la versione in `modulo.json` secondo il capitolo 5 e scrivere la sezione in `CHANGELOG.md`.
3. Se cambia la versione maggiore, aggiornare il vincolo nei `modulo.json` e `composer.json` dei moduli che lo richiedono.
4. Controllare: `python3 tools/moduli.py <nome>` (manifesto, coerenza, dipendenze, prove PHPUnit).
5. Aggiornare il manuale: `php tools/moduli_riferimento.php <nome>` per il riferimento dell'interfaccia; correggere a mano i capitoli che descrivono il comportamento cambiato; aggiornare l'esempio se serve e cancellarne il file `.atteso` per registrarne la nuova uscita.
6. Pubblicare nel repository Composer: `python3 tools/moduli_distribuzione.py <cartella> --url <indirizzo> <nome>`.
7. Eseguire gli esempi contro il repository appena pubblicato: `python3 tools/moduli_esempi.py <cartella> <nome>`; poi rileggere l'uscita registrata.
8. Controllo finale senza scrivere: `php tools/moduli_riferimento.php --controlla` e `python3 tools/moduli_esempi.py <cartella> --controlla` devono uscire con codice 0.
9. Commit, etichetta `pfi-<nome>-<versione>`, invio al repository GitHub; se serve, pacchetto firmato per il gestore.

# 7. Repository Git

I sorgenti dei moduli sono versionati nel repository del portale (ramo di lavoro della release) e, per il riuso, nel repository GitHub privato `moduli-pfi`, che contiene:

| Cartella | Contenuto |
|---|---|
| `src/moduli/` | i dodici moduli con sorgenti, prove, esempi, LEGGIMI, CHANGELOG, MANUALE (con il PDF); questa guida |
| `tools/` | `moduli.py`, `moduli_distribuzione.py`, `moduli_riferimento.php`, `moduli_esempi.py`, `firma_moduli.php` |
| `repository/` | il repository Composer generato (archivi, `packages.json`, manuali, `SHA256SUMS.txt`) |

La disposizione è la stessa del progetto del portale, così gli strumenti funzionano allo stesso modo nei due repository. Il repository del portale resta l'origine: le versioni nuove si sviluppano lì e si riportano in `moduli-pfi` con il proprio commit e le etichette.

Con il repository clonato in `/percorso/moduli-pfi`, un'applicazione usa il tipo `artifact` con `"url": "/percorso/moduli-pfi/repository/dist/"`. La chiave privata di firma dei pacchetti non è mai nel repository.

# 8. Che cosa contiene ogni manuale

Tutti i manuali dei moduli hanno gli stessi capitoli, così si trova subito l'informazione:

| Capitolo | Contenuto |
|---|---|
| 1. A che cosa serve | scopo, quando usarlo e quando no |
| 2. Installazione | requisiti, comando Composer, preparazione del server |
| 3. Concetti | il modello mentale del modulo, con le regole che ne decidono il comportamento |
| 4. Uso passo per passo | un esempio completo **eseguito** in un progetto pulito, con l'uscita ottenuta, e un frammento d'uso tipico |
| 5. Configurazione | parametri dei costruttori con i valori predefiniti |
| 6. Errori ed eccezioni | messaggi, cause, che cosa diventa eccezione e che cosa no |
| 7. Sicurezza | segreti, dati personali, permessi, che cosa il modulo protegge e che cosa no |
| 8. Prove di salute | codice, classe, che cosa controlla |
| 9. Uso nel portale | come lo usa il portale, solo per confronto |
| 10. Limiti noti | ciò che il modulo non fa, con i punti `[DA VERIFICARE]` |
| 11. Indicazioni per gli agenti | regole pratiche per chi scrive codice che usa il modulo |
| 12. Riferimento dell'interfaccia | classi, interfacce, costanti, proprietà e metodi pubblici con le firme, estratti dal codice |

Il capitolo 12 e gli esempi del capitolo 4 sono generati e controllati da strumenti: non vanno modificati a mano fra i segnaposto `<!-- riferimento:… -->` e `<!-- esempio:… -->`.

# 9. Indicazioni per gli agenti

- Per usare un modulo leggere prima il suo manuale, capitoli 3, 4, 7 e 11; il capitolo 12 dà le firme esatte. Non dedurre l'interfaccia dal nome delle classi.
- Installare i moduli con Composer dal repository dei moduli; non copiare file di `src/` dentro l'applicazione.
- Passare fra i moduli le **interfacce** di `pfi/contratti` (`Registro`, `Segreti`, `ProvaDiSalute`), non le classi concrete, quando il costruttore le accetta.
- Leggere le credenziali da `pfi/segreti` al momento dell'uso; non scriverle in file di configurazione, nel codice o nei registri.
- Passare il registro dell'applicazione ai moduli con `per_modulo('pfi/<nome>')` e una correlazione per operazione.
- Riunire le prove di salute dei moduli usati in `pfi/diagnostica` e offrirle all'indirizzo di salute dell'applicazione.
- Nelle prove automatiche usare le parti pensate per le prove: `Trasporti\Finto` di `pfi/connettori`, `Destinazioni\Memoria` di `pfi/registro`, `Archivi\Memoria` di `pfi/modelli`, orologi e date fissati dove i costruttori li accettano.
- Non modificare un modulo dentro `vendor/`: una correzione si fa nei sorgenti e si pubblica come versione nuova (capitolo 6).

# 10. Strumenti

| Strumento | Uso |
|---|---|
| `tools/moduli.py` | controllo di manifesti, coerenza, dipendenze e prove; `--installa CARTELLA` per la cartella comune del server |
| `tools/moduli_distribuzione.py` | repository Composer statico |
| `tools/moduli_riferimento.php` | capitolo 12 dei manuali dal codice; `--controlla` |
| `tools/moduli_esempi.py` | esecuzione degli esempi in un progetto pulito e copia nei manuali; `--controlla` |
| `tools/firma_moduli.php` | chiavi e pacchetti firmati per `pfi/gestore` |

`moduli_esempi.py` richiede Composer e PHP con le estensioni dei moduli; l'esempio di `pfi/stampa` richiede Chromium (variabile `PFI_CHROMIUM` o percorsi usuali di Debian e Ubuntu).

# Registro delle versioni della guida

| Versione | Data | Modifiche |
|---|---|---|
| 1.0.0 | 16 novembre 2026 | prima versione |
