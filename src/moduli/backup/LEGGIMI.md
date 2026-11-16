# pfi/backup

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva.

Backup e ripristino granulari (release 9.0, blocco M7; proposta 49 § 3.8; corregge F-01 e F-03 del rapporto 48). Richiede `pfi/contratti` ^1.0, le estensioni sodium, curl e simplexml e i programmi `tar`, `pg_dump`, `pg_restore`, `createdb`, `dropdb`. Nel portale lo usa il servizio di sistema attraverso `pfi/esecutore`: le pagine (`tool_pfibackup`, pulsante «Esegui il backup ora» e «Ripristina») accodano la richiesta, il servizio la esegue con i privilegi necessari.

## Struttura di un backup

Cartella `AAAAMMGG-HHMMSS` (permessi 0700) nella cartella dei backup:

| File | Contenuto |
|---|---|
| `database.dump` | unità `database`: pg_dump nel formato personalizzato |
| `<nome>.tar.gz` | unità di tipo cartella (per esempio `moodledata`) |
| `<nome>.tar.gz.cifrato` | unità cifrata (per esempio `configurazione`, la cartella `/etc/pfi` con i segreti: F-03) |
| `MANIFESTO.json` | unità, descrizioni, file, byte, durata, rilascio, data |
| `RILASCIO` | rilascio di Moodle in servizio (come per `pfi-ripristino`) |
| `SHA256SUMS` | impronte di tutti i file |

## Interfaccia pubblica

| Elemento | Scopo |
|---|---|
| `Backup($cartella, $rilascio, $numero, $giorni, $registro)` | `unita()`, `destinazione()`, `esegui($scelte)`, `backup()`, `conserva()`, `percorso($id)`, `ripristina($id, $scelte, $prova, $rilasciodiverso)`, `verifica_impronte($cartella)` |
| `Unita` | `nome()`, `descrizione()`, `salva($cartella)`, `ripristina($cartella)`, `prova($cartella)` |
| `Unita\Postgres($database, $proprietario, $come, $ambiente, $provaprofonda)` | dump, ripristino con il database ricreato vuoto (rapporto 48, § 2.3), prova con `pg_restore -l` o con un ripristino completo in un database temporaneo (§ 2.2) |
| `Unita\Cartella($nome, $percorso, $accantona, $cifratura, $proprietario, $escludi)` | archivio tar.gz; ripristino che accantona i dati attuali spostandoli |
| `Cifratura` | `genera($file)` crea una chiave di 32 byte (0600); `da_file()`; `cifra()`, `decifra()` con XChaCha20-Poly1305 a flusso (file alterati o troncati rifiutati) |
| `Destinazioni\Cartella($percorso)` | cartella locale o di rete già montata |
| `Destinazioni\S3($endpoint, $contenitore, $regione, $accesso, $segreto, $prefisso)` | archivio compatibile S3, solo HTTPS |
| `Salute\Recente` | prova di salute `backup.recente`: ultimo backup entro 26 ore, integro, spazio libero |

Il ripristino completo del portale (manutenzione, cron, cache, verifica finale) resta a `pfi-ripristino` del kit, corretto in M0: la libreria fa il ripristino granulare di singole unità e la prova di ripristinabilità.

## Limiti

- La chiave di cifratura va conservata fuori dal portale e fuori dal backup: senza chiave la configurazione cifrata non si recupera.
- La destinazione SFTP prevista dalla proposta 49 non è in questa versione: le destinazioni remote ammesse le indica la conduzione dei sistemi (BRIEF-v9, dipendenze esterne) [DA VERIFICARE].
- S3: un file oltre 5 GiB richiede il caricamento in più parti, non presente; la cartella dei dati di Moodle grande va copiata in una cartella di rete.
- Le unità «singolo corso» (formato di backup di Moodle) e «dati di un componente» richiedono Moodle e sono nell'adattatore `tool_pfibackup`.
