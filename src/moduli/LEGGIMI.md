# Moduli universali

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva.

Release 9.0 (D-161, D-162, D-163; proposta 49). Ogni modulo universale è una libreria PHP indipendente da Moodle in una cartella propria, riusabile da qualunque applicazione PHP con un adattatore. Il portale usa i moduli attraverso i suoi componenti Moodle (adattatori).

## Convenzioni

| Elemento | Regola |
|---|---|
| Cartella | `src/moduli/<nome>/`, nome in minuscolo senza spazi |
| Pacchetto | `pfi/<nome>` in `composer.json`, licenza `GPL-3.0-or-later`, caricamento PSR-4 da `src/` con lo spazio dei nomi `Pfi\<Nome>\` |
| Manifesto | `modulo.json`: nome, versione, descrizione, PHP richiesto, moduli richiesti con il vincolo di versione, prove di salute; schema in `contratti/LEGGIMI.md` |
| Versione | semantica `MAGGIORE.MINORE.CORREZIONE`: la maggiore cambia quando cambia in modo incompatibile l'interfaccia pubblica, la minore quando si aggiungono funzioni compatibili, la correzione per le correzioni |
| Storia | `CHANGELOG.md` con una sezione per versione, la più recente in cima; la prima voce coincide con la versione del manifesto |
| Documentazione | `LEGGIMI.md` del modulo: scopo, interfaccia pubblica, esempio d'uso fuori da Moodle, limiti |
| Prove | `tests/` con PHPUnit, eseguibili senza Moodle; `phpunit.xml` nel modulo |
| Dipendenze | solo da altri moduli `pfi/*` e dalle estensioni standard di PHP; nessuna libreria esterna senza decisione registrata |
| Lingua | codice e commenti in italiano come il resto del progetto; messaggi delle eccezioni in italiano |

## Controllo

`python3 tools/moduli.py` controlla per ogni modulo manifesto, coerenza fra manifesto, `composer.json` e `CHANGELOG.md`, dipendenze fra i moduli e prove PHPUnit (programma `phpunit` nel PATH o indicato nella variabile `PFI_PHPUNIT`).

## Installazione sul server

Il kit copia ogni modulo in `/usr/local/lib/pfi/moduli/<nome>/<versione>/` e crea il collegamento `attuale` alla versione in uso; il caricatore di `pfi/contratti` registra le classi dei moduli a partire da quei collegamenti. Il pannello di gestione dei moduli (`pfi/gestore`, blocco M7b) aggiorna un modulo installando la nuova versione accanto alla vecchia e spostando il collegamento.

## Riuso in altre applicazioni

I moduli si distribuiscono in due modi, descritti nella guida generale `MANUALE-GENERALE.md`:

- **repository Composer statico** generato con `python3 tools/moduli_distribuzione.py DESTINAZIONE --url URL`: un'applicazione li installa con `composer require pfi/<nome>:^1.0`, anche senza rete;
- **pacchetti firmati** per il gestore dei moduli sul server (`php tools/firma_moduli.php`, `pfi-moduli installa`).

Ogni modulo ha un manuale d'uso, `<nome>/MANUALE.md`, per sviluppatori e agenti: concetti, uso passo per passo con un esempio eseguito, configurazione, errori, sicurezza, prove di salute, limiti, indicazioni per gli agenti e riferimento dell'interfaccia estratto dal codice. Il riferimento si aggiorna con `php tools/moduli_riferimento.php`; gli esempi (`<nome>/esempi/*.php`, con l'uscita attesa in `*.atteso`) si eseguono in un progetto pulito e si copiano nel manuale con `python3 tools/moduli_esempi.py REPOSITORY`. Entrambi gli strumenti hanno l'opzione `--controlla`, che non scrive e segnala i manuali non allineati al codice.

## Moduli

| Modulo | Versione | Contenuto | Uso nel portale |
|---|---|---|---|
| `contratti` | 1.0.0 | interfacce comuni, manifesto, versioni, caricatore | tutti i moduli e gli adattatori |
| `segreti` | 1.0.0 | deposito cifrato compatibile con la 8.0, impronta, rotazione | `tool_pfimoduli\moduli::segreti()` per gli altri adattatori |
| `diagnostica` | 1.0.0 | prove di salute, stato complessivo, rapporti JSON e Markdown | pannello di `tool_pfimoduli`, `pfi-verifica` |
| `registro` | 1.0.0 | registro compatibile con PSR-3, mascheramento, correlazione, tabella, file, syslog RFC 5424 | `tool_pfiregistro`, `logstore_pfisyslog` |
| `connettori` | 1.0.0 | client HTTP comune: tentativi, limiti, proxy, fornitore finto | attraverso `comunicazione` |
| `esecutore` | 1.0.0 | operazioni privilegiate: coda, catalogo chiuso, servizio `pfi-esecutore` | ruolo `esecutore` del kit, `tool_pfibackup`, `tool_pfimoduli` |
| `modelli` | 1.0.0 | modelli Mustache senza logica, schemi, verifica, anteprima, versioni | `tool_pfimodelli` |
| `comunicazione` | 1.0.0 | posta SMTP e sette fornitori via API, Telegram, WhatsApp, coda, ricezione IMAP e POP3 | `tool_pficomunicazione` |
| `esportazione` | 1.0.0 | tabelle in CSV, TSV, larghezza fissa, XLSX, ODS, DOCX, ODT, RTF, PDF, HTML, JSON, XML, Markdown | `dataformat_pfidocx`, `pfiodt`, `pfirtf`, `pfixml`, `pfimd`, `pfitsv`, `pficsvpv` |
| `stampa` | 1.0.1 | PDF dai modelli con Chromium, pagina, intestazione e piè di pagina, codici QR | `tool_pfistampa` |
| `backup` | 1.0.0 | backup granulari con impronte, cifratura, conservazione, cartella e S3, ripristino | servizio del kit, `tool_pfibackup` |
| `gestore` | 1.0.0 | pacchetti firmati, versioni affiancate, dipendenze, ritorno indietro | `tool_pfimoduli`, `pfi-moduli` |
