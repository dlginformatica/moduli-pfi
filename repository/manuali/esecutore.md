---
titolo: "Manuale di pfi/esecutore"
sottotitolo: "Moduli universali del Portale di autoformazione IT: operazioni privilegiate richieste dall'applicazione ed eseguite da un servizio di sistema"
versione: "1.0.0"
data: "2026-11-16"
verifica_fonti: "2026-11-16"
prossima_revisione: "a ogni nuova versione del modulo"
rilascio: "pfi/esecutore 1.0.0"
autori: "Gaetano Giarrusso e Ufficio USIS"
hash: "n.d. (calcolato nel MANIFEST)"
classificazione: "USO INTERNO"
---

# Manuale di pfi/esecutore

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale. Copyright 2026 Gaetano Giarrusso, licenza GPL v3 o successiva.

Il manuale è scritto per uno sviluppatore che riusa il modulo in un'applicazione PHP diversa dal portale e per un agente di intelligenza artificiale che deve scrivere codice che lo usa. Il riferimento dell'interfaccia (capitolo 12) è estratto dal codice; gli esempi sono eseguiti in un progetto pulito e l'uscita riportata è quella ottenuta. Distribuzione, versioni e regole comuni sono nella guida generale (`src/moduli/MANUALE-GENERALE.md`).

# 1. A che cosa serve

Un'applicazione web gira con un utente senza privilegi (per esempio `www-data`), ma alcune funzioni richiedono root: avviare un backup, provare un ripristino, aggiornare un modulo, riavviare un servizio. `pfi/esecutore` permette di farlo **senza dare privilegi all'applicazione**: la pagina scrive una richiesta in una cartella di coda; un servizio di sistema (`bin/pfi-esecutore`, avviato da systemd come root) la prende, la controlla contro un **catalogo chiuso** di operazioni ammesse ed esegue il comando dichiarato, con parametri controllati, senza shell, con ambiente ridotto e tempo massimo; poi scrive l'esito, che la pagina legge.

L'applicazione può chiedere solo le operazioni scritte nel catalogo, che appartiene a root; un parametro che non rispetta il proprio tipo fa rifiutare la richiesta.

Usarlo quando un'applicazione web deve avviare poche operazioni di amministrazione ben definite. Non è un sistema di code generico né un modo per eseguire comandi arbitrari.

# 2. Installazione

Requisiti: PHP 8.2 o successivo con le estensioni `posix` (per il servizio) e `json`; `pfi/contratti` ^1.0; facoltativo `pfi/registro`, che il servizio usa se presente; systemd per l'avvio automatico.

```bash
composer require pfi/esecutore:^1.0
```

Preparazione del server (gruppo del server web `www-data` come esempio):

```bash
sudo install -d -o root -g www-data -m 0750 /var/spool/pfi-esecutore
sudo install -d -o root -g www-data -m 2770 /var/spool/pfi-esecutore/richieste
sudo install -d -o root -g www-data -m 0750 /var/spool/pfi-esecutore/in-corso
sudo install -d -o root -g www-data -m 2750 /var/spool/pfi-esecutore/esiti
sudo install -o root -g root -m 0644 esecutore.json /etc/pfi/esecutore.json
```

Unità systemd (il servizio parte quando nella cartella `richieste` compare un file):

```ini
# /etc/systemd/system/pfi-esecutore.path
[Path]
DirectoryNotEmpty=/var/spool/pfi-esecutore/richieste
Unit=pfi-esecutore.service
[Install]
WantedBy=multi-user.target

# /etc/systemd/system/pfi-esecutore.service
[Service]
Type=oneshot
ExecStart=/usr/bin/php /percorso/vendor/pfi/esecutore/bin/pfi-esecutore --coda /var/spool/pfi-esecutore --catalogo /etc/pfi/esecutore.json --moduli /percorso/dei/moduli
```

Poi `systemctl daemon-reload && systemctl enable --now pfi-esecutore.path`. Il programma si può chiamare anche dal cron con `--pulisci GIORNI` per eliminare gli esiti vecchi.

# 3. Concetti

**Coda.** Una cartella con tre sottocartelle: `richieste` (scritta dall'applicazione), `in-corso` ed `esiti` (scritte dal servizio, leggibili dal gruppo dell'applicazione). Una richiesta è il file `<id>.json`, scritto in modo atomico, con operazione, parametri, richiedente, correlazione e data di creazione; l'identificativo è di 32 caratteri esadecimali casuali; dimensione massima 64 KiB.

**Stati.** `Coda::esito($id)` restituisce un array con la chiave `stato`:

| Stato | Significato | Altre chiavi |
|---|---|---|
| `in coda` | la richiesta aspetta il servizio | |
| `in corso` | il servizio la sta eseguendo | `operazione`, `iniziata` |
| `eseguita` | il comando è terminato con codice 0 | `codice`, `uscita`, `durata`, `terminata` |
| `non riuscita` | il comando è terminato con codice diverso da 0 | come sopra |
| `rifiutata` | operazione non nel catalogo, parametri non validi, richiesta scaduta o malformata | `motivo` |
| `interrotta` | tempo massimo superato (codice 124) o servizio fermato durante l'esecuzione | `motivo` |
| `sconosciuta` | identificativo non valido o esito già eliminato | |

**Catalogo e operazioni.** Un file JSON `{"operazioni": {"<nome>": {...}}}` dove ogni operazione ha `descrizione`, `comando` (elenco di argomenti; il primo è un percorso assoluto), `parametri`, `tempomassimo` in secondi (600) e `uscitamassima` in byte (65 536). Negli argomenti, `{nome}` è sostituito dal valore del parametro come argomento intero, `{?nome:testo}` diventa `testo` se il booleano `nome` è vero e scompare se è falso; ogni altro argomento resta letterale. Un valore non si incolla mai dentro un altro argomento.

**Tipi dei parametri.**

| Tipo | Chiavi | Regola |
|---|---|---|
| `testo` | `modello` (obbligatorio) | espressione regolare che il valore intero deve soddisfare; al massimo 1024 byte |
| `intero` | `min`, `max` | intero JSON nell'intervallo |
| `scelta` | `valori` (obbligatorio) | uno dei valori elencati |
| `booleano` | | vero o falso; se manca vale falso |
| `percorso` | `radice` (obbligatorio) | il percorso reale, risolti i collegamenti, deve stare sotto la radice |

Ogni parametro è obbligatorio salvo `"obbligatorio": false`; un parametro non dichiarato fa rifiutare la richiesta.

**Esecuzione.** Il servizio prende le richieste dalla più vecchia, una alla volta; esegue il comando con `proc_open` senza shell, con cartella di lavoro `/`, ingresso vuoto, ambiente ridotto a `PATH`, `LANG=C.UTF-8`, `LC_ALL=C.UTF-8` e `PFI_CORRELAZIONE`; raccoglie uscita ed errori fino a `uscitamassima`; allo scadere del tempo invia SIGTERM e, dopo mezzo secondo, SIGKILL. Le richieste più vecchie della scadenza (un'ora) si rifiutano.

# 4. Uso passo per passo

L'esempio esegue nello stesso processo, senza root, sia il lato dell'applicazione sia quello del servizio: accoda sei richieste (una valida, un tentativo di iniezione nel testo, un percorso valido e uno fuori dalla radice, un'operazione inesistente, un comando che supera il tempo massimo) e ne legge gli esiti.

<!-- esempio:uso.php:inizio -->
```php
<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/esecutore fuori da Moodle. Per mostrarlo in un solo processo, la pagina (che accoda) e il servizio
// (che esegue) girano qui insieme e senza root; in esercizio il servizio è bin/pfi-esecutore avviato da systemd come root
// e il catalogo è un file di root (Catalogo::da_file).
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Contratti\Eccezione;
use Pfi\Esecutore\Catalogo;
use Pfi\Esecutore\Coda;
use Pfi\Esecutore\Esecutore;
use Pfi\Esecutore\Salute\Coda as SaluteCoda;

$base = __DIR__ . '/coda';
$archivi = __DIR__ . '/archivi';
@mkdir($archivi . '/2026-11', 0750, true);
touch($archivi . '/2026-11/portale-2026-11-15.tar.zst');

// Catalogo: elenco chiuso di operazioni, ciascuna con un comando come elenco di argomenti (mai una riga di shell).
$catalogo = Catalogo::da_json(json_encode(['operazioni' => [
    'saluta' => [
        'descrizione' => 'Stampa un saluto',
        'comando' => ['/bin/echo', 'Ciao', '{nome}', '{?formale:(cordiali saluti)}'],
        'parametri' => [
            'nome' => ['tipo' => 'testo', 'modello' => '^[A-Za-z ]{1,40}$'],
            'formale' => ['tipo' => 'booleano'],
        ],
    ],
    'elenca-archivio' => [
        'descrizione' => 'Elenca una cartella degli archivi',
        'comando' => ['/bin/ls', '-1', '{cartella}'],
        'parametri' => ['cartella' => ['tipo' => 'percorso', 'radice' => $archivi]],
    ],
    'attesa-lunga' => [
        'descrizione' => 'Comando che supera il tempo massimo',
        'comando' => ['/bin/sleep', '5'],
        'tempomassimo' => 1,
    ],
]]));

$coda = new Coda($base);
$coda->prepara();

// Lato pagina: accoda le richieste (l'autorizzazione dell'utente si controlla prima di questo punto).
$richieste = [
    'saluto normale' => $coda->richiedi('saluta', ['nome' => 'Mario Rossi', 'formale' => true], 'mrossi'),
    'tentativo di iniezione' => $coda->richiedi('saluta', ['nome' => 'x; rm -rf /'], 'mrossi'),
    'percorso ammesso' => $coda->richiedi('elenca-archivio', ['cartella' => $archivi . '/2026-11'], 'mrossi'),
    'percorso fuori radice' => $coda->richiedi('elenca-archivio', ['cartella' => $archivi . '/../coda'], 'mrossi'),
    'operazione sconosciuta' => $coda->richiedi('cancella-tutto', [], 'mrossi'),
    'tempo massimo superato' => $coda->richiedi('attesa-lunga', [], 'mrossi'),
];
echo "Stato appena accodata: ", $coda->esito($richieste['saluto normale'])['stato'], "\n";

// Lato servizio: prende ed esegue le richieste, una alla volta, dalla più vecchia.
$trattate = (new Esecutore($coda, $catalogo))->elabora();
echo "Richieste trattate dal servizio: {$trattate}\n\n";

// Lato pagina: legge gli esiti.
foreach ($richieste as $caso => $id) {
    $e = $coda->attendi($id, 2.0);
    $riga = "{$caso}: {$e['stato']}";
    if (isset($e['codice'])) {
        $riga .= ", codice {$e['codice']}";
    }
    if (!empty($e['uscita'])) {
        $riga .= ', uscita «' . trim($e['uscita']) . '»';
    }
    if (isset($e['motivo'])) {
        $riga .= ", motivo: {$e['motivo']}";
    }
    echo $riga, "\n";
}

// Il controllo dei parametri si può usare anche nella pagina, prima di accodare.
try {
    $catalogo->operazione('saluta')->controlla(['nome' => 'Mario', 'colore' => 'rosso']);
} catch (Eccezione $e) {
    echo "\nControllo nella pagina: {$e->getMessage()}\n";
}
$esito = (new SaluteCoda($coda))->esegui();
echo "Salute della coda: {$esito->stato}, {$esito->messaggio}\n";
```

Uscita (verificata eseguendo l'esempio in un progetto pulito):

```text
Stato appena accodata: in coda
Richieste trattate dal servizio: 6

saluto normale: eseguita, codice 0, uscita «Ciao Mario Rossi (cordiali saluti)»
tentativo di iniezione: rifiutata, motivo: Parametro «nome»: formato non ammesso.
percorso ammesso: eseguita, codice 0, uscita «portale-2026-11-15.tar.zst»
percorso fuori radice: rifiutata, motivo: Parametro «cartella»: percorso inesistente o fuori dalla cartella ammessa.
operazione sconosciuta: rifiutata, motivo: Operazione non ammessa: «cancella-tutto».
tempo massimo superato: interrotta, codice 124, motivo: Tempo massimo di 1 secondi superato.

Controllo nella pagina: Operazione saluta: parametro non ammesso «colore».
Salute della coda: buono, Coda delle operazioni privilegiate in ordine.
```
<!-- esempio:uso.php:fine -->

Nell'applicazione il lato della pagina è soltanto:

```php
$coda = new \Pfi\Esecutore\Coda('/var/spool/pfi-esecutore');
// prima: controllo che l'utente sia autorizzato a questa operazione
$id = $coda->richiedi('ripristino-prova', ['cartella' => $cartella], $utente->username, $registro->correlazione());
$esito = $coda->attendi($id, 10.0);   // oppure salvare $id e interrogare esito() più tardi
```

# 5. Configurazione

| Elemento | Parametri e predefiniti |
|---|---|
| `Coda` | `$base` (cartella della coda) |
| `Esecutore` | `$coda`, `$catalogo`, `$registro` (nullo), `$scadenza` delle richieste in secondi (3600), `$percorso` per la variabile `PATH` dei comandi |
| `Catalogo::da_file` | `$file`, `$controllaproprietario` (vero: il file deve essere di root e non scrivibile da gruppo e altri) |
| `bin/pfi-esecutore` | `--coda` (`/var/spool/pfi-esecutore`), `--catalogo` (`/etc/pfi/esecutore.json`), `--moduli` (`/usr/local/lib/pfi/moduli`, per il caricatore senza Composer), `--registro` (`/var/log/pfi/esecutore.log`), `--pulisci GIORNI` |
| `Salute\Coda` | `$coda` (quella predefinita), `$ritardo` in secondi oltre il quale una richiesta in attesa indica il servizio fermo (300) |

Il programma richiede root, usa un file di blocco `/run/pfi-esecutore.lock` (una sola istanza) e ripete `elabora()` finché trova richieste.

# 6. Errori ed eccezioni

Lato applicazione `Coda::richiedi()` lancia `Pfi\Contratti\Eccezione` con «Richiesta troppo grande.» o «Richiesta non accodata: cartella della coda non scrivibile.»; `prepara()` con «Cartella della coda non creata.». Gli errori di contenuto non sono eccezioni per l'applicazione: diventano esiti `rifiutata` con il motivo. Motivi possibili:

| Motivo | Causa |
|---|---|
| «Operazione non ammessa: «…».» | il nome non è nel catalogo |
| «Operazione …: parametro non ammesso «…».», «Operazione …: manca il parametro «…».» | parametro in più o mancante |
| «Parametro «…»: formato non ammesso.» e simili | valore che non rispetta il tipo |
| «Parametro «…»: percorso inesistente o fuori dalla cartella ammessa.» | percorso fuori dalla radice o inesistente |
| «Richiesta scaduta.», «Richiesta non valida (…).» | richiesta vecchia, malformata, troppo grande o collegamento simbolico |

I motivi non riportano i valori ricevuti. Il catalogo lancia `Eccezione` se un'operazione è dichiarata male (nome non valido, comando non assoluto, parametro senza le chiavi obbligatorie, segnaposto non dichiarato) o se il file non appartiene a root.

# 7. Sicurezza

- Chi può scrivere nella cartella `richieste` può chiedere qualunque operazione del catalogo: l'autorizzazione dell'utente si controlla nell'applicazione **prima** di accodare.
- Il catalogo è la vera difesa: operazioni poche e strette, comandi con percorso assoluto, parametri di tipo `scelta`, `intero` o `percorso` quando possibile, modelli di `testo` ancorati con `^` e `$` e senza caratteri superflui.
- Non c'è shell: `;`, `|`, `$( )` in un valore non hanno effetto, ma un comando del catalogo che a sua volta passa i parametri a una shell annulla questa protezione; i programmi del catalogo devono trattare gli argomenti come dati.
- L'uscita del comando torna all'applicazione e compare negli esiti: i comandi non devono stampare segreti.
- Gli esiti sono leggibili dal gruppo del server web; si eliminano con `--pulisci`.

# 8. Prove di salute

| Codice | Classe | Che cosa controlla |
|---|---|---|
| `esecutore.coda` | `Pfi\Esecutore\Salute\Coda` | cartelle della coda presenti (senza coda installata: `avviso`); richieste in attesa da più di 5 minuti danno `guasto`, perché indicano il servizio fermo |

# 9. Uso nel portale

Nel portale il ruolo Ansible `esecutore` crea coda, catalogo (`/etc/pfi/esecutore.json`) e unità systemd; le pagine di `tool_pfibackup` (backup e prova di ripristino) e di `tool_pfimoduli` (installazione e aggiornamento dei moduli) accodano le richieste dopo aver controllato le capacità di Moodle. Informazione utile solo per confronto.

# 10. Limiti noti

- Una richiesta alla volta: un'operazione lunga ritarda le successive.
- Il controllo dell'età e l'ordine delle richieste usano l'orologio e le date dei file del server.
- Non c'è annullamento di una richiesta già in corso.
- L'utente che esegue i comandi è sempre root; per abbassare i privilegi il comando del catalogo deve farlo da sé (per esempio con `setpriv` o `runuser`).

# 11. Indicazioni per gli agenti

- Non aggiungere al catalogo operazioni generiche («esegui comando», «copia file») né parametri `testo` con modelli larghi come `.*`.
- Controllare l'autorizzazione dell'utente prima di `richiedi()`; il servizio non conosce gli utenti dell'applicazione.
- Passare come correlazione quella del registro dell'applicazione, così gli eventi del servizio si ritrovano.
- Gestire tutti gli stati della tabella del capitolo 3, compreso `sconosciuta`.
- Per provare il codice senza root usare `Catalogo::da_json()` ed `Esecutore::elabora()` nello stesso processo, come nell'esempio.

# 12. Riferimento dell'interfaccia

Generato dal codice della versione 1.0.0; lo spazio dei nomi è `Pfi\Esecutore\`.

<!-- riferimento:inizio -->
#### `Catalogo`

Classe finale. Elenco chiuso delle operazioni ammesse. In esercizio si legge da un file JSON di proprietà di root e non scrivibile da altri (per esempio /etc/pfi/esecutore.json): {"operazioni": {"nome": {"descrizione", "comando", "parametri", "tempomassimo"}}}. Un file con proprietario o permessi diversi si rifiuta.

| Metodo | Descrizione |
|---|---|
| `aggiungi(Pfi\Esecutore\Operazione $o): self` |  |
| `operazione(string $nome): Pfi\Esecutore\Operazione` |  |
| `operazioni(): array` |  |
| `static da_file(string $file, bool $controllaproprietario = true): self` |  |
| `static da_json(string $json): self` |  |

#### `Coda`

Classe finale. Coda delle richieste fra le pagine del portale (utente del server web) e il servizio pfi-esecutore (root).

Costanti: `MASSIMO = 65536`.

Proprietà pubbliche: `readonly string $base`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $base)` |  |
| `cartella(string $nome): string` |  |
| `prepara(): void` | Crea le cartelle (per le prove e per il kit, che ne imposta poi proprietari e permessi). |
| `static valido(string $id): bool` |  |
| `richiedi(string $operazione, array $parametri, string $richiedente, string $correlazione = ''): string` | Accoda una richiesta e ne restituisce l'identificativo. |
| `esito(string $id): array` | Stato di una richiesta: ['stato' => 'in coda'\|'in corso'\|'sconosciuta'] oppure l'esito scritto dal servizio (stato «eseguita», «non riuscita», «rifiutata», «interrotta»). |
| `attendi(string $id, float $secondi, float $intervallo = 0.5): array` | Attende l'esito fino a $secondi; restituisce l'ultimo stato letto. |

#### `Esecutore`

Classe finale. Lato del servizio di sistema (release 9.0, blocco M2b; proposta 49 § 3.10): prende le richieste dalla coda una alla volta, le controlla con il catalogo, esegue il comando senza shell con ambiente ridotto e tempo massimo, scrive l'esito e registra ogni operazione con la correlazione.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa Pfi\Esecutore\Coda $coda, promossa Pfi\Esecutore\Catalogo $catalogo, ?Pfi\Contratti\Registro $registro = null, promossa int $scadenza = 3600, promossa string $percorso = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin')` |  |
| `elabora(int $massimo = 100): int` | Esegue le richieste in coda, dalla più vecchia; restituisce quante ne ha trattate. |
| `pulisci(int $giorni = 30, ?int $adesso = null): int` | Elimina gli esiti più vecchi di $giorni; restituisce quanti. |

#### `Operazione`

Classe finale. Operazione privilegiata ammessa (proposta 49 § 3.10): comando con argomenti fissi e parametri controllati.

Proprietà pubbliche: `readonly string $nome`, `readonly string $descrizione`, `readonly array $comando`, `readonly array $parametri`, `readonly int $tempomassimo`, `readonly int $uscitamassima`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $nome, promossa string $descrizione, promossa array $comando, promossa array $parametri = [], promossa int $tempomassimo = 600, promossa int $uscitamassima = 65536)` |  |
| `static da_array(string $nome, array $d): self` |  |
| `controlla(array $ricevuti): array` | Controlla e normalizza i parametri ricevuti; Eccezione con un messaggio senza i valori. |
| `argomenti(array $valori): array` | Argomenti del comando con i parametri già controllati. |

#### `Salute\Coda`

Classe finale, implementa `Pfi\Contratti\ProvaDiSalute`. Prova di salute della coda: cartelle presenti e nessuna richiesta in attesa da più di $ritardo secondi (che indica il servizio pfi-esecutore fermo). Senza coda (costruzione dal manifesto) usa la cartella predefinita del kit.

Costanti: `PREDEFINITA = '/var/spool/pfi-esecutore'`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa ?Pfi\Esecutore\Coda $coda = null, promossa int $ritardo = 300)` |  |
| `codice(): string` | Codice stabile della prova, per esempio «registro.scrittura». |
| `descrizione(): string` | Descrizione in italiano di che cosa controlla. |
| `esegui(): Pfi\Contratti\EsitoSalute` |  |

<!-- riferimento:fine -->

# Registro delle versioni del manuale

| Versione | Data | Modifiche |
|---|---|---|
| 1.0.0 | 16 novembre 2026 | prima versione, per pfi/esecutore 1.0.0 |
