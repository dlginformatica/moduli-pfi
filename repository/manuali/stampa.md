---
titolo: "Manuale di pfi/stampa"
sottotitolo: "Moduli universali del Portale di autoformazione IT: documenti PDF dai modelli con Chromium, senza risorse esterne"
versione: "1.0.1"
data: "2026-11-16"
verifica_fonti: "2026-11-16"
prossima_revisione: "a ogni nuova versione del modulo"
rilascio: "pfi/stampa 1.0.1"
autori: "Gaetano Giarrusso e Ufficio USIS"
hash: "n.d. (calcolato nel MANIFEST)"
classificazione: "USO INTERNO"
---

# Manuale di pfi/stampa

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale. Copyright 2026 Gaetano Giarrusso, licenza GPL v3 o successiva.

Il manuale è scritto per uno sviluppatore che riusa il modulo in un'applicazione PHP diversa dal portale e per un agente di intelligenza artificiale che deve scrivere codice che lo usa. Il riferimento dell'interfaccia (capitolo 12) è estratto dal codice; gli esempi sono eseguiti in un progetto pulito e l'uscita riportata è quella ottenuta. Distribuzione, versioni e regole comuni sono nella guida generale (`src/moduli/MANUALE-GENERALE.md`).

# 1. A che cosa serve

`pfi/stampa` produce documenti PDF (fogli firme, attestati, locandine, schede) a partire da modelli HTML modificabili. Il testo del documento è un modello di `pfi/modelli` del **canale `stampa`**; la pagina (formato, orientamento, margini, intestazione e piè di pagina con numero di pagina e totale) si imposta con `Pagina`; l'impaginazione la fa **Chromium senza interfaccia**, installato sul server.

Il documento dichiara una politica di sicurezza dei contenuti che vieta script e risorse esterne: un modello modificato non può far uscire richieste dal server durante la stampa. Immagini e codici QR si incorporano nel documento.

Il modulo fornisce anche l'impaginatore del formato PDF di `pfi/esportazione`. Non produce PDF/A e non firma i documenti.

# 2. Installazione

Requisiti: PHP 8.2 o successivo; `pfi/contratti`, `pfi/modelli` e `pfi/esportazione` ^1.0; Chromium sul server (`chromium` di Debian, oppure `chrome-headless-shell`); per i codici QR la classe `TCPDF2DBarcode` di TCPDF (presente in Moodle) o il programma `qrencode`.

```bash
composer require pfi/stampa:^1.0
sudo apt install chromium fonts-dejavu-core qrencode
```

I caratteri DejaVu servono a intestazioni e piè di pagina, che il modulo scrive in DejaVu Sans.

# 3. Concetti

**Modello di stampa.** Un modello di `pfi/modelli` con canale `stampa` e queste parti:

| Parte | Uso |
|---|---|
| `html` | corpo del documento (obbligatoria; con escape dei valori) |
| `titolo` | titolo del documento PDF |
| `intestazione`, `piedipagina` | testo in alto e in basso su ogni pagina; `{pagina}` e `{pagine}` (con una sola graffa) danno numero e totale |

Se il modello ha `intestazione` o `piedipagina`, queste prevalgono su quelle della `Pagina` passata.

**Pagina.** `new Pagina($formato, $orizzontale, $margini, $intestazione, $piedipagina)`: formati `A4`, `A3`, `A5`, `letter`, `legal`; margini in millimetri da 0 a 100 nell'ordine alto, destra, basso, sinistra (predefiniti 20, 15, 20, 15); piè di pagina predefinito «Pagina {pagina} di {pagine}». `css()` produce la regola `@page` con le caselle dei margini.

**Documento.** `Documento::html($corpo, $pagina, $titolo, $lingua, $stile)` compone il documento completo: politica dei contenuti `default-src 'none'; img-src data:; font-src data:; style-src 'unsafe-inline'`, regole di pagina, stile aggiunto dal chiamante. Le tabelle con `<thead>` ripetono l'intestazione su ogni pagina; una riga non si spezza fra due pagine; la classe `salto` forza il salto di pagina dopo un elemento.

**Impaginatore.** `Chromium($programma, $tempomassimo, $senzasandbox, $cartella)` implementa `Pfi\Esportazione\Impaginatore`: `pdf($html)` scrive l'HTML in un file temporaneo leggibile solo dal processo, avvia Chromium con un profilo temporaneo e senza rete in background, attende al massimo `$tempomassimo` secondi e restituisce i byte del PDF. `Chromium::cerca()` trova il programma fra i percorsi usuali di Debian e Ubuntu.

**Stampa.** `Stampa($modelli, $impaginatore, $registro)` con `anteprima($chiave, $dati, $lingua, $pagina, $stile)` (documento HTML, per l'editor) e `pdf(...)` con gli stessi argomenti (byte del PDF). Ogni stampa è registrata con il nome del modello, la dimensione e la durata, senza i dati.

**Codici QR.** `Qr::svg($testo)` produce un'immagine SVG; `Qr::img($testo, $alternativo)` un elemento `<img>` con l'SVG incorporato come `data:`, pronto da passare al modello come valore senza escape.

# 4. Uso passo per passo

L'esempio dichiara un foglio presenze con intestazione e piè di pagina numerato, produce l'anteprima (si vedono la politica dei contenuti, la regola di pagina e l'escape del titolo), stampa 60 partecipanti su tre pagine, prova un codice QR e la prova di salute. Il modello contiene di proposito un'immagine esterna: la politica dei contenuti la blocca. Nell'ambiente in cui è stato eseguito non c'erano né TCPDF né `qrencode`, quindi il QR dà l'eccezione prevista. Con `pdftotext` si è verificato a parte che il PDF riporta «Foglio firme, pagina 1 di 3», «… 2 di 3» e «… 3 di 3».

<!-- esempio:uso.php:inizio -->
```php
<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/stampa fuori da Moodle: modello del canale «stampa», pagina con intestazione e numeri di pagina,
// anteprima HTML con la politica dei contenuti, PDF con Chromium, prova di salute.
// Richiede Chromium sul server: il percorso si indica con la variabile PFI_CHROMIUM oppure lo trova Chromium::cerca().
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Contratti\Eccezione;
use Pfi\Modelli\Archivi\Memoria;
use Pfi\Modelli\Modelli;
use Pfi\Modelli\Modello;
use Pfi\Modelli\Schema;
use Pfi\Stampa\Chromium;
use Pfi\Stampa\Pagina;
use Pfi\Stampa\Qr;
use Pfi\Stampa\Salute\Motore;
use Pfi\Stampa\Stampa;

// 1. Modello di stampa: parti html (obbligatoria), titolo, intestazione e piedipagina.
$schema = (new Schema('corsi/foglio_presenze', 'Foglio delle presenze'))
    ->segnaposto('corso', 'Titolo del corso', 'Sicurezza informatica')
    ->segnaposto('partecipanti', 'Partecipanti', [['nome' => 'Mario Rossi']], true)
    ->segnaposto('partecipanti.nome', 'Nome e cognome', 'Mario Rossi');
$modelli = (new Modelli(new Memoria()))->dichiara($schema, new Modello('corsi/foglio_presenze', 'stampa', 'it', [
    'titolo' => 'Presenze: {{corso}}',
    'intestazione' => '{{corso}}',
    'piedipagina' => 'Foglio firme, pagina {pagina} di {pagine}',
    'html' => '<h1>{{corso}}</h1><table><thead><tr><th>Partecipante</th><th>Firma</th></tr></thead><tbody>'
        . '{{#partecipanti}}<tr><td>{{nome}}</td><td></td></tr>{{/partecipanti}}</tbody></table>'
        . '<img src="https://example.org/logo.png" alt="logo esterno: la politica dei contenuti lo blocca">',
]));

$dati = ['corso' => 'Sicurezza <informatica>', 'partecipanti' => []];
for ($i = 1; $i <= 60; $i++) {
    $dati['partecipanti'][] = ['nome' => "Partecipante {$i}"];
}
$stile = 'table{width:100%;border-collapse:collapse}td,th{border:1px solid #999;padding:6px;height:8mm}';

// 2. Anteprima: documento HTML completo, utile anche per l'editor.
$programma = getenv('PFI_CHROMIUM') ?: Chromium::cerca();
$chromium = $programma ? new Chromium($programma, 60, function_exists('posix_geteuid') && posix_geteuid() === 0) : null;
$stampa = new Stampa($modelli, $chromium);
$html = $stampa->anteprima('corsi/foglio_presenze', $dati, 'it', new Pagina('A4', false, [25, 15, 20, 15]), $stile);
preg_match('/http-equiv="Content-Security-Policy" content="([^"]+)"/', $html, $csp);
preg_match('/@page\{[^@]*/', $html, $page);
echo "Politica dei contenuti: {$csp[1]}\n";
echo "Regole di pagina: {$page[0]}\n";
echo "Titolo con escape: ", preg_match('#<title>(.*?)</title>#', $html, $t) ? $t[1] : '', "\n";

// 3. PDF.
if ($chromium === null) {
    echo "Chromium non trovato: indicare il programma con PFI_CHROMIUM.\n";
} else {
    $pdf = $stampa->pdf('corsi/foglio_presenze', $dati, 'it', new Pagina('A4', false, [25, 15, 20, 15]), $stile);
    file_put_contents(__DIR__ . '/presenze.pdf', $pdf);
    echo "PDF: inizia con ", substr($pdf, 0, 5), ", pagine: ", preg_match_all('#/Type\s*/Page[^s]#', $pdf), "\n";
}

// 4. Codici QR: TCPDF (presente in Moodle) o il programma qrencode; senza nessuno dei due, un'eccezione chiara.
try {
    $img = Qr::img('https://example.org/attestato/A-17', 'Verifica dell\'attestato');
    echo 'QR: ', substr($img, 0, 37), "…\n";
} catch (Eccezione $e) {
    echo "QR: {$e->getMessage()}\n";
}

// 5. Prova di salute con impaginazione di prova.
$esito = (new Motore($chromium, true))->esegui();
echo "Salute: {$esito->stato}, {$esito->messaggio}\n";
```

Uscita (verificata eseguendo l'esempio in un progetto pulito):

```text
Politica dei contenuti: default-src 'none'; img-src data:; font-src data:; style-src 'unsafe-inline'
Regole di pagina: @page{size:A4;margin:25mm 15mm 20mm 15mm;
Titolo con escape: Presenze: Sicurezza &lt;informatica&gt;
PDF: inizia con %PDF-, pagine: 3
QR: Nessun generatore di codici QR disponibile (TCPDF o qrencode).
Salute: buono, Impaginazione di prova riuscita.
```
<!-- esempio:uso.php:fine -->

Con `pfi/esportazione` il formato PDF delle tabelle si attiva così:

```php
$esportatore = new \Pfi\Esportazione\Esportatore(new \Pfi\Stampa\Chromium(\Pfi\Stampa\Chromium::cerca()));
```

# 5. Configurazione

| Costruttore | Parametri e predefiniti |
|---|---|
| `Chromium` | `$programma` (percorso eseguibile), `$tempomassimo` in secondi (60), `$senzasandbox` (no; serve solo se il processo gira come root), `$cartella` per i file temporanei (quella di PHP) |
| `Pagina` | `$formato` (`A4`), `$orizzontale` (no), `$margini` (`[20, 15, 20, 15]` mm), `$intestazione` (vuota), `$piedipagina` (`Pagina {pagina} di {pagine}`) |
| `Stampa` | `$modelli`, `$impaginatore` (può essere `null`: solo anteprime), `$registro` (nullo) |
| `Salute\Motore` | `$chromium` (nessuno: cerca il programma), `$prova` (no; sì per impaginare davvero una pagina minima) |

Lo `$stile` di `anteprima()` e `pdf()` è CSS aggiunto al documento: tiene separati l'aspetto (deciso dall'applicazione) e il testo (modificabile nel modello).

# 6. Errori ed eccezioni

Tutti `Pfi\Contratti\Eccezione`.

| Situazione | Messaggio |
|---|---|
| programma Chromium assente o non eseguibile | «Programma Chromium non trovato o non eseguibile.» |
| cartella temporanea non creata, Chromium non avviato | «Cartella temporanea della stampa non creata.», «Chromium non avviato.» |
| impaginazione troppo lunga | «Impaginazione oltre il tempo massimo di N secondi.» |
| Chromium termina senza PDF | «Chromium non ha prodotto il PDF (codice N).» |
| `pdf()` senza impaginatore | «Motore di stampa non configurato.» |
| modello senza parte `html` | «Il modello di stampa «…» non ha la parte html.» |
| formato o margini non validi | «Formato o margini della pagina non validi.», «Margine della pagina fuori dall'intervallo 0-100 mm.» |
| QR con testo vuoto o troppo lungo; nessun generatore | «Testo del codice QR vuoto o troppo lungo.», «Nessun generatore di codici QR disponibile (TCPDF o qrencode).» |

Gli errori di `pfi/modelli` (modello o schema inesistente, sintassi) passano invariati.

# 7. Sicurezza

- La politica dei contenuti del documento blocca script, fogli di stile, caratteri e immagini esterni: le prove del modulo lo verificano con un server locale che non deve ricevere richieste.
- Immagini e loghi vanno incorporati come `data:` (per esempio `data:image/png;base64,…`), passati dal codice come valori senza escape o scritti nello stile.
- Chromium gira con un profilo temporaneo che si elimina alla fine; la sandbox di Chromium resta attiva. Con l'utente del server web la sandbox richiede gli spazi dei nomi utente del kernel `[DA VERIFICARE sulla macchina di destinazione]`; disattivarla (`$senzasandbox`) solo se il processo gira come root, come nelle prove.
- I dati personali dei documenti non sono registrati; i PDF prodotti vanno conservati e distribuiti secondo la classificazione dei dati che contengono.

# 8. Prove di salute

| Codice | Classe | Che cosa controlla |
|---|---|---|
| `stampa.motore` | `Pfi\Stampa\Salute\Motore` | senza argomenti: Chromium presente (`buono`) o assente (`avviso`); con un `Chromium` e `$prova = true`: impagina una pagina minima (`guasto` se non riesce) |

# 9. Uso nel portale

Nel portale l'adattatore `tool_pfistampa` offre l'editor dei modelli di stampa e i modelli predefiniti (locandina, schede dell'edizione e della sede, foglio firme, Quaderno); il kit installa il pacchetto `chromium` di Debian. TCPDF resta per le stampe proprie di Moodle e degli attestati di `mod_customcert`. Informazione utile solo per confronto.

# 10. Limiti noti

- PDF/A per l'archiviazione non è disponibile: richiede una conversione (per esempio con Ghostscript) e la validazione con uno strumento dedicato `[DA VERIFICARE prima di promettere la conformità]`.
- Le caselle dei margini di CSS (`@top-center`, `@bottom-center`) richiedono una versione recente di Chromium (dal 2024 circa) `[DA VERIFICARE con la versione installata]`; l'esempio è stato eseguito con Chrome Headless Shell della serie 1243 di Playwright.
- Ogni stampa avvia un processo Chromium: qualche centinaio di millisecondi e un centinaio di MB di memoria per documento; per stampe in serie conviene una coda.
- Il QR dipende da TCPDF o da `qrencode`: senza nessuno dei due lancia un'eccezione.

# 11. Indicazioni per gli agenti

- Scrivere il testo nel modello (canale `stampa`) e l'aspetto nello `$stile`; non costruire HTML nel codice.
- Usare tabelle con `<thead>` per gli elenchi lunghi: l'intestazione si ripete da sola.
- Non mettere indirizzi esterni nei modelli: non funzionerebbero. Incorporare le immagini come `data:`.
- Creare `Chromium` una volta e riusarlo; controllare il programma con la prova di salute prima di offrire la stampa.
- Nelle prove automatiche confrontare l'anteprima HTML; per il PDF controllare l'inizio `%PDF-` e il testo con `pdftotext`, non i byte (cambiano a ogni stampa).

# 12. Riferimento dell'interfaccia

Generato dal codice della versione 1.0.1; lo spazio dei nomi è `Pfi\Stampa\`.

<!-- riferimento:inizio -->
#### `Chromium`

Classe finale, implementa `Pfi\Esportazione\Impaginatore`. Impaginazione HTML → PDF con Chromium senza interfaccia (D-161 c): programma `chrome-headless-shell` o `chromium --headless`, profilo temporaneo, nessuna rete in background, tempo massimo. L'HTML arriva da un file temporaneo leggibile solo dal processo; la politica di sicurezza dei contenuti del Documento blocca le risorse esterne.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $programma, promossa int $tempomassimo = 60, promossa bool $senzasandbox = false, promossa ?string $cartella = null)` |  |
| `static cerca(): ?string` | Primo programma disponibile fra quelli abituali dei sistemi Debian e Ubuntu, o null. |
| `pdf(string $html): string` |  |

#### `Documento`

Classe finale. Documento HTML completo da impaginare: corpo reso dal modello, regole di pagina e una politica di sicurezza dei contenuti che vieta ogni risorsa esterna (sono ammesse solo immagini e caratteri incorporati come data:), così un modello modificato nel portale non fa uscire richieste dal server durante la stampa.

Costanti: `CSP = 'default-src \'none\'; img-src data:; font-src data:; style-src \'unsafe-inline\''`.

| Metodo | Descrizione |
|---|---|
| `static html(string $corpo, Pfi\Stampa\Pagina $pagina, string $titolo = '', string $lingua = 'it', string $stile = ''): string` |  |

#### `Pagina`

Classe finale. Impostazioni di pagina di un modello di stampa (proposta 49 § 3.7): formato, orientamento, margini in millimetri, intestazione e piè di pagina di testo con i segnaposto {pagina} e {pagine}, resi con le caselle dei margini di CSS (@page) che Chromium impagina.

Costanti: `FORMATI = ['A4', 'A3', 'A5', 'letter', 'legal']`.

Proprietà pubbliche: `readonly string $formato`, `readonly bool $orizzontale`, `readonly array $margini`, `readonly string $intestazione`, `readonly string $piedipagina`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $formato = 'A4', promossa bool $orizzontale = false, promossa array $margini = [20, 15, 20, 15], promossa string $intestazione = '', promossa string $piedipagina = 'Pagina {pagina} di {pagine}')` |  |
| `css(): string` |  |

#### `Qr`

Classe finale. Codici QR generati sul server, senza servizi esterni (proposta 49 § 3.7): con la classe TCPDF2DBarcode di TCPDF (presente in Moodle) se caricata, altrimenti con il programma `qrencode`. Il risultato è un'immagine SVG da incorporare nel modello come data: (la politica dei contenuti della stampa vieta le immagini esterne).

| Metodo | Descrizione |
|---|---|
| `static svg(string $testo, int $modulo = 4): string` |  |
| `static img(string $testo, string $alternativo, int $modulo = 4): string` | Immagine pronta per il modello: <img src="data:image/svg+xml;base64,…" alt="…">. |

#### `Salute\Motore`

Classe finale, implementa `Pfi\Contratti\ProvaDiSalute`. Prova di salute: programma Chromium presente; con $prova vera impagina anche una pagina minima.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa ?Pfi\Stampa\Chromium $chromium = null, promossa bool $prova = false)` |  |
| `codice(): string` | Codice stabile della prova, per esempio «registro.scrittura». |
| `descrizione(): string` | Descrizione in italiano di che cosa controlla. |
| `esegui(): Pfi\Contratti\EsitoSalute` |  |

#### `Stampa`

Classe finale. Stampa con i modelli (release 9.0, blocco M6; proposta 49 § 3.7): i modelli di stampa sono modelli di pfi/modelli del canale «stampa» con la parte «html» (corpo), e facoltative «titolo», «intestazione» e «piedipagina»; la pagina e lo stile si passano da chi stampa (configurazione del modello nell'adattatore).

| Metodo | Descrizione |
|---|---|
| `__construct(promossa Pfi\Modelli\Modelli $modelli, promossa ?Pfi\Esportazione\Impaginatore $impaginatore, ?Pfi\Contratti\Registro $registro = null)` |  |
| `anteprima(string $chiave, array $dati, string $lingua = 'it', ?Pfi\Stampa\Pagina $pagina = null, string $stile = ''): string` | Documento HTML completo, per l'anteprima nel portale. |
| `pdf(string $chiave, array $dati, string $lingua = 'it', ?Pfi\Stampa\Pagina $pagina = null, string $stile = ''): string` | PDF del modello reso con i dati. |

<!-- riferimento:fine -->

# Registro delle versioni del manuale

| Versione | Data | Modifiche |
|---|---|---|
| 1.0.1 | 16 novembre 2026 | prima versione, per pfi/stampa 1.0.1 |
