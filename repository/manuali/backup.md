---
titolo: "Manuale di pfi/backup"
sottotitolo: "Moduli universali del Portale di autoformazione IT: backup e ripristino granulari con cifratura, impronte, copie remote e conservazione"
versione: "1.0.0"
data: "2026-11-16"
verifica_fonti: "2026-11-16"
prossima_revisione: "a ogni nuova versione del modulo"
rilascio: "pfi/backup 1.0.0"
autori: "Gaetano Giarrusso e Ufficio USIS"
hash: "n.d. (calcolato nel MANIFEST)"
classificazione: "USO INTERNO"
---

# Manuale di pfi/backup

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale. Copyright 2026 Gaetano Giarrusso, licenza GPL v3 o successiva.

Il manuale è scritto per uno sviluppatore che riusa il modulo in un'applicazione PHP diversa dal portale e per un agente di intelligenza artificiale che deve scrivere codice che lo usa. Il riferimento dell'interfaccia (capitolo 12) è estratto dal codice; gli esempi sono eseguiti in un progetto pulito e l'uscita riportata è quella ottenuta. Distribuzione, versioni e regole comuni sono nella guida generale (`src/moduli/MANUALE-GENERALE.md`).

# 1. A che cosa serve

`pfi/backup` salva e ripristina i dati di un'applicazione divisi in **unità**: il database PostgreSQL, le cartelle dei dati, la configurazione con i segreti (cifrata). Ogni esecuzione crea una cartella datata con un file per unità, un manifesto e le impronte SHA-256; copia il backup nelle **destinazioni** remote (cartella di rete o archivio compatibile S3); applica la **conservazione** per numero e per giorni, in locale e in remoto.

Il ripristino è **granulare** (una o più unità) e preceduto dal controllo delle impronte e del rilascio dell'applicazione; la **prova di ripristino** controlla che ogni unità sia ripristinabile senza modificare nulla. Il ripristino di una cartella non cancella i dati attuali: li accantona.

Il modulo non arresta né riavvia l'applicazione: la sequenza completa (manutenzione, cron, cache, verifica finale) spetta a uno script dell'applicazione. Nel portale le operazioni girano come root attraverso `pfi/esecutore`.

# 2. Installazione

Requisiti: PHP 8.2 o successivo con le estensioni `sodium`, `curl` e `simplexml`; `pfi/contratti` ^1.0; i programmi `tar` e `gzip`; per l'unità database `pg_dump`, `pg_restore`, `createdb` e `dropdb` della stessa versione principale del server PostgreSQL.

```bash
composer require pfi/backup:^1.0
```

Chiave della cifratura, una volta sola, conservata fuori dall'applicazione e fuori dal backup:

```php
\Pfi\Backup\Cifratura::genera('/root/chiavi/backup-applicazione.chiave');   // 32 byte casuali, permessi 0600
```

# 3. Concetti

**Struttura di un backup.** Cartella `AAAAMMGG-HHMMSS` (permessi 0700) nella cartella dei backup:

| File | Contenuto |
|---|---|
| `database.dump` | unità `database`: pg_dump nel formato personalizzato |
| `<nome>.tar.gz` | unità di tipo cartella |
| `<nome>.tar.gz.cifrato` | unità di tipo cartella cifrata |
| `MANIFESTO.json` | unità, descrizioni, file, byte, durata, rilascio, data |
| `RILASCIO` | rilascio dell'applicazione in servizio |
| `SHA256SUMS` | impronte di tutti i file |

Se un'esecuzione fallisce, la cartella incompleta si elimina.

**Unità.** Implementano `Pfi\Backup\Unita` (`nome()`, `descrizione()`, `salva()`, `ripristina()`, `prova()`):

| Classe | Salva | Ripristina | Prova |
|---|---|---|---|
| `Unita\Postgres($database, $proprietario, $come, $ambiente, $provaprofonda)` | `pg_dump -Fc` | cancella e ricrea il database vuoto (UTF-8, `template0`, proprietario), poi `pg_restore --no-owner --role=<proprietario> --exit-on-error` | `pg_restore -l`; con `$provaprofonda` ripristino completo in un database temporaneo poi eliminato |
| `Unita\Cartella($nome, $percorso, $accantona, $cifratura, $proprietario, $escludi)` | `tar -czf` della cartella, senza i percorsi in `$escludi`; cifrato se c'è `$cifratura` | sposta i dati attuali in `<accantona>/<nome>-prima-del-ripristino-<data>`, estrae l'archivio, ripristina il proprietario se indicato | lettura completa dell'archivio (`tar -tzf`), decifrato se serve |

`$come` è il prefisso dei comandi del database (per esempio `['runuser', '-u', 'postgres', '--']` quando il servizio gira come root); `$ambiente` le variabili di connessione (`PGHOST`, `PGPORT`, `PGUSER`).

**Cifratura.** `Cifratura` usa XChaCha20-Poly1305 a flusso (sodium secretstream): i file grandi non stanno in memoria, e un file alterato o troncato viene rifiutato. La chiave è di 32 byte; `da_file()` rifiuta un file di chiave leggibile da altri.

**Destinazioni.** `Destinazioni\Cartella($percorso)` per una cartella locale o di rete già montata; `Destinazioni\S3($endpoint, $contenitore, $regione, $accesso, $segreto, $prefisso, $ca)` per archivi compatibili S3 (anche MinIO e Ceph) con indirizzi di tipo percorso, firma Signature Version 4, solo HTTPS, controllo dell'ETag dopo ogni caricamento.

**Conservazione.** Si tengono gli ultimi `$numero` backup e comunque quelli degli ultimi `$giorni`; si applica a ogni `esegui()` e, chiamata a parte, con `conserva()`; vale anche per le destinazioni.

**Ripristino.** `ripristina($id, $scelte, $prova = true, $rilasciodiverso = false)`: controlla le impronte, poi il rilascio (un backup di un altro rilascio si ripristina solo con `$rilasciodiverso`), poi che le unità esistano nel backup e nella configurazione. Il valore predefinito di `$prova` è **vero**: per ripristinare davvero si passa `false`.

# 4. Uso passo per passo

L'esempio, senza root e senza database, configura due unità di tipo cartella (una con un file escluso, una cifrata) e una copia in una seconda cartella; esegue tre backup in giorni diversi, prova e ripristina un'unità, mostra i rifiuti per rilascio diverso, file alterato e chiave sbagliata, la conservazione e la prova di salute.

<!-- esempio:uso.php:inizio -->
```php
<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/backup fuori da Moodle, senza root: due unità di tipo cartella (una cifrata), una copia in una
// seconda cartella, prova di ripristino, ripristino che accantona i dati attuali, file alterato, conservazione, salute.
// L'unità Postgres (pg_dump, pg_restore) si configura allo stesso modo ma richiede il database.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Backup\Backup;
use Pfi\Backup\Cifratura;
use Pfi\Backup\Destinazioni\Cartella as CartellaRemota;
use Pfi\Backup\Salute\Recente;
use Pfi\Backup\Unita\Cartella;
use Pfi\Contratti\Eccezione;

$b = __DIR__ . '/prova';
foreach (['dati/documenti', 'configurazione', 'backup', 'remoto', 'accantonati', 'chiavi'] as $c) {
    @mkdir("{$b}/{$c}", 0700, true);
}
file_put_contents("{$b}/dati/documenti/verbale.txt", "Verbale della riunione\n");
file_put_contents("{$b}/dati/cache.tmp", 'da non salvare');
file_put_contents("{$b}/configurazione/servizi.json", '{"smtp":"segreto"}');

// La chiave della cifratura si genera una volta e si conserva FUORI dal portale e dal backup.
Cifratura::genera("{$b}/chiavi/backup.chiave");
$cifratura = Cifratura::da_file("{$b}/chiavi/backup.chiave");

$backup = (new Backup("{$b}/backup", 'applicazione-2.4.0', 2, 1))
    ->unita(new Cartella('dati', "{$b}/dati", "{$b}/accantonati", null, null, ['cache.tmp']))
    ->unita(new Cartella('configurazione', "{$b}/configurazione", "{$b}/accantonati", $cifratura))
    ->destinazione(new CartellaRemota("{$b}/remoto"));

// 1. Tre backup in giorni diversi (data fissata solo per l'esempio).
foreach (['2026-11-13 02:00', '2026-11-14 02:00', '2026-11-15 02:00'] as $q) {
    $id = $backup->esegui([], new DateTimeImmutable($q));
}
echo "Ultimo backup: {$id}\n";
$file = array_values(array_diff(scandir($backup->percorso($id)), ['.', '..']));
echo "File: ", implode(', ', $file), "\n";
$m = json_decode(file_get_contents($backup->percorso($id) . '/MANIFESTO.json'), true);
echo "Unità nel manifesto: ", implode(', ', array_keys($m['unita'])), "; rilascio {$m['rilascio']}\n";
echo "La cache esclusa non è nell'archivio: ",
    str_contains(shell_exec('tar -tzf ' . escapeshellarg($backup->percorso($id) . '/dati.tar.gz')), 'cache.tmp') ? 'no' : 'sì', "\n";

// 2. Prova di ripristino (non modifica nulla) e ripristino vero di una sola unità.
echo "Prova di ripristino: ", implode(', ', $backup->ripristina($id)), " ripristinabili\n";
file_put_contents("{$b}/dati/documenti/verbale.txt", "Verbale modificato per errore\n");
$backup->ripristina($id, ['dati'], false);
echo "Dopo il ripristino: ", trim(file_get_contents("{$b}/dati/documenti/verbale.txt")),
    "; dati sostituiti accantonati in ", count(glob("{$b}/accantonati/dati-prima-del-ripristino-*")), " cartella\n";

// 3. Rilascio diverso, file alterato, chiave sbagliata.
$altro = (new Backup("{$b}/backup", 'applicazione-2.5.0'))->unita(new Cartella('dati', "{$b}/dati", "{$b}/accantonati"));
$alterato = $backup->percorso('20261114-020000') . '/dati.tar.gz';
file_put_contents($alterato, 'x', FILE_APPEND);
$chiavesbagliata = new Cifratura(random_bytes(32));
$prove = [
    'rilascio diverso' => fn() => $altro->ripristina($id),
    'file alterato' => fn() => $backup->ripristina('20261114-020000'),
    'chiave sbagliata' => fn() => $chiavesbagliata->decifra($backup->percorso($id) . '/configurazione.tar.gz.cifrato', "{$b}/x.tar.gz"),
];
foreach ($prove as $caso => $f) {
    try {
        $f();
    } catch (Eccezione $e) {
        echo "{$caso}: {$e->getMessage()}\n";
    }
}

// 4. Conservazione (2 backup e comunque quelli dell'ultimo giorno, in locale e in remoto): si applica a ogni esecuzione,
// quindi il backup del 13 è già stato eliminato al terzo backup; chiamata di nuovo, non trova altro da eliminare.
$eliminati = $backup->conserva(new DateTimeImmutable('2026-11-15 12:00'));
echo "Eliminati da una nuova conservazione: ", $eliminati ? implode(', ', $eliminati) : 'nessuno', "\n";
echo "Restano in locale: ", implode(', ', $backup->backup()), "; in remoto: ",
    implode(', ', array_reverse((new CartellaRemota("{$b}/remoto"))->elenco())), "\n";

// 5. Prova di salute dopo un backup di adesso.
$backup->esegui();
$esito = (new Recente($backup, "{$b}/backup"))->esegui();
echo "Salute: {$esito->stato}, {$esito->messaggio}\n";
```

Uscita (verificata eseguendo l'esempio in un progetto pulito):

```text
Ultimo backup: 20261115-020000
File: MANIFESTO.json, RILASCIO, SHA256SUMS, configurazione.tar.gz.cifrato, dati.tar.gz
Unità nel manifesto: dati, configurazione; rilascio applicazione-2.4.0
La cache esclusa non è nell'archivio: sì
Prova di ripristino: dati, configurazione ripristinabili
Dopo il ripristino: Verbale della riunione; dati sostituiti accantonati in 1 cartella
rilascio diverso: Il backup viene dal rilascio «applicazione-2.4.0», in servizio c'è «applicazione-2.5.0»: confermare il rilascio diverso.
file alterato: Impronte non corrispondenti: dati.tar.gz. Il backup non si ripristina.
chiave sbagliata: Decifratura non riuscita: chiave errata o file alterato.
Eliminati da una nuova conservazione: nessuno
Restano in locale: 20261115-020000, 20261114-020000; in remoto: 20261115-020000, 20261114-020000
Salute: buono, Backup recente e integro.
```
<!-- esempio:uso.php:fine -->

Configurazione tipica sul server (servizio che gira come root):

```php
use Pfi\Backup\{Backup, Cifratura};
use Pfi\Backup\Unita\{Postgres, Cartella};
use Pfi\Backup\Destinazioni\S3;

$backup = (new Backup('/var/backups/applicazione', trim(file_get_contents('/opt/applicazione/RILASCIO')), 7, 30, $registro))
    ->unita(new Postgres('applicazione', 'applicazione', ['runuser', '-u', 'postgres', '--']))
    ->unita(new Cartella('dati', '/var/lib/applicazione', '/var/backups/accantonati', null, 'www-data:www-data', ['cache', 'temp']))
    ->unita(new Cartella('configurazione', '/etc/applicazione', '/var/backups/accantonati',
        Cifratura::da_file('/root/chiavi/backup-applicazione.chiave')))
    ->destinazione(new S3('https://s3.example.org', 'backup-applicazione', 'eu-south-1', $accesso, $segreto));
$id = $backup->esegui();
```

# 5. Configurazione

| Costruttore | Parametri e predefiniti |
|---|---|
| `Backup` | `$cartella`, `$rilascio` (`'sconosciuto'`), `$numero` da conservare (7), `$giorni` (30), `$registro` (nullo) |
| `Unita\Postgres` | `$database`, `$proprietario` (nomi PostgreSQL semplici), `$come` (`[]`), `$ambiente` (`[]`), `$provaprofonda` (no) |
| `Unita\Cartella` | `$nome` (minuscole, cifre, `_`, `-`), `$percorso` e `$accantona` assoluti, `$cifratura` (nessuna), `$proprietario` (`utente:gruppo` per `chown -R`), `$escludi` (percorsi relativi alla cartella) |
| `Destinazioni\S3` | `$endpoint` (https), `$contenitore`, `$regione`, `$accesso`, `$segreto`, `$prefisso` (`'pfi-backup'`), `$ca`, `$consentihttp` (no; solo per prove locali) |
| `Salute\Recente` | `$backup`, `$cartella` per lo spazio libero, `$ore` (26) |

`esegui($scelte, $quando)`: unità scelte (tutte se vuoto) e data del backup (ora corrente; da fissare solo nelle prove).

# 6. Errori ed eccezioni

Tutti `Pfi\Contratti\Eccezione`; un comando esterno non riuscito riporta il programma e il codice di uscita.

| Situazione | Messaggio |
|---|---|
| unità inesistente in `esegui()` | «Unità di backup sconosciuta: «…»» |
| cartella dei backup non creabile; backup con lo stesso identificativo | «Cartella dei backup non creata.», «Backup … già presente o cartella non scrivibile.» |
| identificativo inesistente | «Backup sconosciuto: «…»» |
| impronte mancanti, non valide o diverse | «Impronte del backup assenti.», «File delle impronte non valido.», «Impronte non corrispondenti: <file>. Il backup non si ripristina.» |
| rilascio diverso senza conferma | «Il backup viene dal rilascio «…», in servizio c'è «…»: confermare il rilascio diverso.» |
| unità assente dal backup o dalla configurazione | «Il backup … non contiene l'unità «…».», «Unità «…» non configurata in questo portale.» |
| chiave corta, illeggibile o con permessi larghi | «Chiave di cifratura del backup troppo corta.», «… non leggibile.», «… deve essere leggibile solo dal proprietario (0600).» |
| chiave sbagliata o file alterato; file troncato | «Decifratura non riuscita: chiave errata o file alterato.», «File cifrato troncato.» |
| destinazione non montata; copia incompleta | «Cartella di destinazione assente o non scrivibile (montaggio mancante?).», «Copia del backup non completata.» |
| parametri non validi | «Nome o percorsi dell'unità non validi.», «Nome di database o di ruolo non valido.», «Indirizzo S3 (solo https) o nome del contenitore non validi.», «La conservazione del backup è di almeno un backup e un giorno.» |

# 7. Sicurezza

- La chiave di cifratura sta fuori dall'applicazione e fuori dal backup; senza chiave la configurazione cifrata non si recupera. Va conservata anche in un luogo sicuro separato (per esempio la cassaforte delle credenziali dell'ufficio).
- Le cartelle dei backup sono create con permessi 0700; nel portale le operazioni girano come root e le pagine possono solo accodare richieste.
- Il ripristino è sempre preceduto dal controllo delle impronte: un backup alterato non si ripristina.
- Le credenziali S3 vanno lette da `pfi/segreti`; la destinazione accetta solo HTTPS con verifica del certificato.
- I dati accantonati dal ripristino restano sul disco: vanno eliminati quando non servono più.

# 8. Prove di salute

| Codice | Classe | Che cosa controlla |
|---|---|---|
| `backup.recente` | `Pfi\Backup\Salute\Recente` | estensione `sodium`; con un `Backup`: esiste un backup, l'ultimo ha meno di 26 ore, le sue impronte corrispondono; spazio libero almeno doppio dell'ultimo backup, altrimenti `avviso` |

# 9. Uso nel portale

Nel portale il servizio di sistema esegue backup e prove attraverso `pfi/esecutore`; le pagine di `tool_pfibackup` («Esegui il backup ora», «Ripristina») accodano la richiesta, e l'adattatore aggiunge le unità «singolo corso» e «dati di un componente», che richiedono Moodle. Il ripristino completo del portale resta allo script `pfi-ripristino` del kit. Informazione utile solo per confronto.

# 10. Limiti noti

- Nessuna destinazione SFTP in questa versione: le destinazioni remote ammesse le indica la conduzione dei sistemi `[DA VERIFICARE]`.
- S3: un singolo file oltre 5 GiB richiede il caricamento in più parti, non presente; per cartelle di dati molto grandi usare una cartella di rete.
- Backup completi a ogni esecuzione, non incrementali.
- Il ripristino del database cancella e ricrea il database: l'applicazione deve essere ferma o in manutenzione.

# 11. Indicazioni per gli agenti

- Chiamare `ripristina()` senza `false` solo per provare; per il ripristino vero passare `$prova = false` in modo esplicito e dopo conferma dell'utente.
- Non aggiungere la cartella della chiave a un'unità di backup.
- Escludere cache e file temporanei con `$escludi`: riducono durata e spazio.
- Passare il rilascio reale dell'applicazione a `Backup`: è la protezione contro il ripristino di dati incompatibili.
- Nelle prove usare unità di tipo cartella in una cartella temporanea e date fissate con `esegui([], $quando)`, come nell'esempio.

# 12. Riferimento dell'interfaccia

Generato dal codice della versione 1.0.0; lo spazio dei nomi è `Pfi\Backup\`.

<!-- riferimento:inizio -->
#### `Backup`

Classe finale. Backup granulare con pulsante unico (release 9.0, blocco M7; proposta 49 § 3.8).

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $cartella, promossa string $rilascio = 'sconosciuto', promossa int $numero = 7, promossa int $giorni = 30, ?Pfi\Contratti\Registro $registro = null)` |  |
| `unita(Pfi\Backup\Unita $u): self` |  |
| `destinazione(Pfi\Backup\Destinazione $d): self` |  |
| `elenco_unita(): array` |  |
| `static valido(string $id): bool` |  |
| `esegui(array $scelte = [], ?DateTimeImmutable $quando = null): string` | Esegue il backup delle unità indicate (tutte se vuoto) e restituisce l'identificativo. |
| `static file_di(string $dir): array` |  |
| `backup(): array` |  |
| `conserva(?DateTimeImmutable $adesso = null): array` | Conserva gli ultimi $numero backup e comunque quelli degli ultimi $giorni; restituisce gli eliminati. |
| `percorso(string $id): string` |  |
| `static verifica_impronte(string $dir): array` | Controlla le impronte; Eccezione con i file alterati o mancanti. |
| `ripristina(string $id, array $scelte = [], bool $prova = true, bool $rilasciodiverso = false): array` | Ripristino granulare delle unità indicate (tutte quelle del backup se vuoto) dopo il controllo delle impronte e del rilascio. Con $prova vera non modifica nulla e controlla solo la ripristinabilità. Il ripristino completo del portale (manutenzione, cron, cache) resta a pfi-ripristino, che il servizio esegue attraverso pfi/esecutore. |

#### `Cifratura`

Classe finale. Cifratura dei file del backup con libsodium (XChaCha20-Poly1305 a flusso, crypto_secretstream): intestazione «PFIC1», 24 byte di intestazione del flusso, poi blocchi di 64 KiB autenticati; l'ultimo porta l'etichetta finale, così un file troncato non si decifra. La chiave è un file di 32 byte casuali (o di testo da cui si ricava con BLAKE2b) conservato fuori dal portale e fuori dal backup.

| Metodo | Descrizione |
|---|---|
| `__construct(string $chiave)` |  |
| `static da_file(string $file): self` |  |
| `static genera(string $file): void` | Crea un file di chiave nuovo (0600). |
| `cifra(string $da, string $a): void` |  |
| `decifra(string $da, string $a): void` |  |

#### `Comando`

Classe finale. Esecuzione di comandi senza shell con ambiente ridotto, ingresso e uscita da file e tempo massimo.

| Metodo | Descrizione |
|---|---|
| `static esegui(array $argomenti, ?string $ingresso = null, ?string $uscita = null, array $ambiente = [], int $tempo = 3600): array` |  |
| `static riuscito(array $argomenti, ?string $ingresso = null, ?string $uscita = null, array $ambiente = [], int $tempo = 3600): void` | Come esegui(), con Eccezione se il codice non è 0. |

#### `Destinazione`

Interfaccia. Destinazione remota di una copia del backup (cartella di rete montata, SFTP, archivio compatibile S3).

| Metodo | Descrizione |
|---|---|
| `nome(): string` |  |
| `invia(string $cartella, string $id): void` | Copia la cartella del backup (file regolari) sotto il nome $id. |
| `elenco(): array` |  |
| `elimina(string $id): void` |  |
| `scarica(string $id, string $cartella): void` | Riporta il backup $id in una cartella locale nuova. |

#### `Destinazioni\Cartella`

Classe finale, implementa `Pfi\Backup\Destinazione`. Cartella locale o di rete già montata (NFS, SMB): ogni backup in una sottocartella con il suo identificativo, copiata prima con un nome provvisorio e rinominata alla fine, così una copia interrotta non sembra completa.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $percorso)` |  |
| `nome(): string` |  |
| `invia(string $cartella, string $id): void` | Copia la cartella del backup (file regolari) sotto il nome $id. |
| `elenco(): array` |  |
| `elimina(string $id): void` |  |
| `scarica(string $id, string $cartella): void` | Riporta il backup $id in una cartella locale nuova. |

#### `Destinazioni\S3`

Classe finale, implementa `Pfi\Backup\Destinazione`. Archivio di oggetti compatibile con Amazon S3 (anche MinIO, Ceph e servizi analoghi) con indirizzi di tipo percorso (https://endpoint/contenitore/chiave) e firma AWS Signature Version 4. I file si caricano in streaming con PUT e x-amz-content-sha256 UNSIGNED-PAYLOAD (ammesso solo su HTTPS); dopo ogni caricamento si confronta l'ETag con l'MD5 del file. Un singolo oggetto è limitato a 5 GiB (PUT senza caricamento in più parti) [DA VERIFICARE: limiti del servizio scelto dalla conduzione dei sistemi].

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $endpoint, promossa string $contenitore, promossa string $regione, promossa string $accesso, promossa string $segreto, promossa string $prefisso = 'pfi-backup', promossa ?string $ca = null, promossa bool $consentihttp = false)` |  |
| `nome(): string` |  |
| `invia(string $cartella, string $id): void` | Copia la cartella del backup (file regolari) sotto il nome $id. |
| `elenco(): array` |  |
| `elimina(string $id): void` |  |
| `scarica(string $id, string $cartella): void` | Riporta il backup $id in una cartella locale nuova. |

#### `Salute\Recente`

Classe finale, implementa `Pfi\Contratti\ProvaDiSalute`. Prova di salute dei backup: l'ultimo backup è più recente di $ore, le sue impronte corrispondono, lo spazio libero nella cartella è almeno il doppio dell'ultimo backup. Senza backup configurato (costruzione dal manifesto) controlla solo l'estensione sodium.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa ?Pfi\Backup\Backup $backup = null, promossa string $cartella = '', promossa int $ore = 26)` |  |
| `codice(): string` | Codice stabile della prova, per esempio «registro.scrittura». |
| `descrizione(): string` | Descrizione in italiano di che cosa controlla. |
| `esegui(): Pfi\Contratti\EsitoSalute` |  |

#### `Unita`

Interfaccia. Unità di backup (proposta 49 § 3.8): database, cartella dei dati, configurazione del server, singolo corso, dati di un componente. Ogni unità scrive i propri file nella cartella del backup e sa ripristinarli.

| Metodo | Descrizione |
|---|---|
| `nome(): string` | Nome stabile, usato nel manifesto e nella scelta del ripristino granulare (per esempio «database»). |
| `descrizione(): string` |  |
| `salva(string $cartella): array` |  |
| `ripristina(string $cartella): void` | Ripristina dai file dell'unità in $cartella (già verificati con le impronte). |
| `prova(string $cartella): void` | Controllo senza effetti che i file dell'unità siano ripristinabili (per la prova periodica). |

#### `Unita\Cartella`

Classe finale, implementa `Pfi\Backup\Unita`. Cartella in un archivio tar.gz (cartella dei dati di Moodle, configurazione del server), facoltativamente cifrato (la configurazione /etc/pfi contiene segreti: F-03). Ripristino: il contenuto attuale si accantona in $accantona (spostandolo, la cartella resta al suo posto anche se è un punto di montaggio), poi si estrae l'archivio e si assegna il proprietario, se indicato.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $nome, promossa string $percorso, promossa string $accantona, promossa ?Pfi\Backup\Cifratura $cifratura = null, promossa ?string $proprietario = null, promossa array $escludi = [])` |  |
| `nome(): string` | Nome stabile, usato nel manifesto e nella scelta del ripristino granulare (per esempio «database»). |
| `descrizione(): string` |  |
| `salva(string $cartella): array` |  |
| `ripristina(string $cartella): void` | Ripristina dai file dell'unità in $cartella (già verificati con le impronte). |
| `prova(string $cartella): void` | Controllo senza effetti che i file dell'unità siano ripristinabili (per la prova periodica). |

#### `Unita\Postgres`

Classe finale, implementa `Pfi\Backup\Unita`. Database PostgreSQL: pg_dump nel formato personalizzato; ripristino come nel rapporto 48, § 2.3 (database ricreato vuoto con il proprietario e la codifica UTF-8, pg_restore senza proprietari con il ruolo del portale); prova con pg_restore -l e, se richiesta, ripristino completo in un database temporaneo (rapporto 48, § 2.2).

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $database, promossa string $proprietario, promossa array $come = [], promossa array $ambiente = [], promossa bool $provaprofonda = false)` |  |
| `nome(): string` | Nome stabile, usato nel manifesto e nella scelta del ripristino granulare (per esempio «database»). |
| `descrizione(): string` |  |
| `salva(string $cartella): array` |  |
| `ripristina(string $cartella): void` | Ripristina dai file dell'unità in $cartella (già verificati con le impronte). |
| `prova(string $cartella): void` | Controllo senza effetti che i file dell'unità siano ripristinabili (per la prova periodica). |

<!-- riferimento:fine -->

# Registro delle versioni del manuale

| Versione | Data | Modifiche |
|---|---|---|
| 1.0.0 | 16 novembre 2026 | prima versione, per pfi/backup 1.0.0 |
