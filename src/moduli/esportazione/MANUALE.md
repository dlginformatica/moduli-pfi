---
titolo: "Manuale di pfi/esportazione"
sottotitolo: "Moduli universali del Portale di autoformazione IT: esportazione di tabelle in CSV, fogli di calcolo, documenti, PDF e formati di dati, una riga alla volta"
versione: "1.0.0"
data: "2026-11-16"
verifica_fonti: "2026-11-16"
prossima_revisione: "a ogni nuova versione del modulo"
rilascio: "pfi/esportazione 1.0.0"
autori: "Gaetano Giarrusso e Ufficio USIS"
hash: "n.d. (calcolato nel MANIFEST)"
classificazione: "USO INTERNO"
---

# Manuale di pfi/esportazione

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale. Copyright 2026 Gaetano Giarrusso, licenza GPL v3 o successiva.

Il manuale è scritto per uno sviluppatore che riusa il modulo in un'applicazione PHP diversa dal portale e per un agente di intelligenza artificiale che deve scrivere codice che lo usa. Il riferimento dell'interfaccia (capitolo 12) è estratto dal codice; gli esempi sono eseguiti in un progetto pulito e l'uscita riportata è quella ottenuta. Distribuzione, versioni e regole comuni sono nella guida generale (`src/moduli/MANUALE-GENERALE.md`).

# 1. A che cosa serve

`pfi/esportazione` trasforma una tabella (intestazioni e righe) in un file da scaricare o da consegnare a un altro sistema: CSV, TSV, testo a larghezza fissa, XLSX, ODS, DOCX, ODT, RTF, PDF, HTML, JSON, XML, Markdown. Le righe arrivano da qualsiasi iterabile, anche un generatore che legge dal database, e si scrivono una alla volta in un flusso o in un file: un'esportazione grande non sta tutta in memoria.

I formati in archivio (XLSX, ODS, DOCX, ODT) sono scritti dal modulo stesso, senza librerie esterne. Il CSV protegge dalle formule (CSV injection); l'HTML produce una tabella accessibile.

Usarlo per i pulsanti «Scarica» dei report e per gli scambi di file con altri sistemi. Non è una libreria per fogli di calcolo: non scrive formule, stili, grafici né più fogli.

# 2. Installazione

Requisiti: PHP 8.2 o successivo con le estensioni `mbstring`, `zip` e `iconv`; `pfi/contratti` ^1.0. Il formato PDF richiede un impaginatore, cioè un oggetto che implementa `Pfi\Esportazione\Impaginatore` (lo fornisce `pfi/stampa`).

```bash
composer require pfi/esportazione:^1.0
```

# 3. Concetti

**Formati per nome.** `Esportatore` conosce i formati predefiniti e quelli aggiunti con `formato_con_nome()`; `nomi()` li elenca.

| Nome | Classe | Comportamento |
|---|---|---|
| `csv`, `csv_pv` | `Formati\Delimitato::csv()`, `::csv(';')` | RFC 4180; separatore virgola o punto e virgola; UTF-8 con BOM; fine riga CRLF; protezione dalle formule |
| `tsv` | `Formati\Delimitato::tsv()` | tabulazione senza delimitatore: tabulazioni e a capo nei valori diventano spazi |
| a scelta | `new Formati\Delimitato(...)` | separatore fino a 4 caratteri, delimitatore, codifica fra quelle di mbstring, BOM, fine riga, protezione |
| a scelta | `new Formati\LarghezzaFissa(...)` | colonne a larghezza fissa riempite con spazi; valori più lunghi troncati; allineamento `s` o `d` per colonna, altrimenti numeri a destra e testo a sinistra |
| `xlsx`, `ods` | `Formati\Xlsx`, `Formati\Ods` | foglio con intestazione in grassetto e bloccata; numeri come numeri |
| `docx`, `odt` | `Formati\Docx`, `Formati\Odt` | documento con titolo e tabella; intestazione ripetuta su ogni pagina |
| `rtf` | `Formati\Rtf` | tabella RTF con caratteri Unicode |
| `pdf` | `Formati\Pdf` | tabella HTML impaginata dall'impaginatore; disponibile solo se l'`Esportatore` lo riceve |
| `html` | `Formati\Html` | documento con tabella accessibile (didascalia, intestazioni con `scope`) e stile di stampa |
| `json` | `Formati\Json` | elenco di oggetti con le intestazioni come chiavi e i tipi JSON conservati |
| `xml` | `Formati\Xml` | un elemento per riga, un elemento per colonna |
| `md` | `Formati\Markdown` | titolo e tabella in stile GitHub |

**Valori.** Nei formati testuali le date diventano ISO 8601, i booleani `1` e `0`, `null` testo vuoto, i numeri sono scritti senza notazione scientifica e con il punto decimale. JSON conserva numeri, booleani e `null`.

**Protezione dalle formule.** Con la protezione attiva (predefinita nei CSV), un valore di testo che inizia con `=`, `+`, `-`, `@`, tabulazione o ritorno a capo è preceduto da un apostrofo, perché un foglio di calcolo non lo esegua come formula; i numeri negativi non sono toccati.

**Flusso e file.** `esporta($righe, $colonne, $formato, $uscita, $titolo)` scrive in un flusso aperto (per esempio `php://output`) e restituisce il numero di righe. `in_file()` scrive in un file temporaneo nella stessa cartella e lo sposta al nome finale solo alla fine: un'esportazione interrotta non lascia un file a metà. `formato($nome)->tipo()` ed `estensione()` danno il tipo MIME e l'estensione per le intestazioni HTTP.

# 4. Uso passo per passo

L'esempio esporta tre righe con testo, numeri, booleani, date e un valore che sembra una formula: in CSV (con la protezione), Markdown e JSON; poi in un CSV configurato per Windows-1252, in testo a larghezza fissa, nei quattro formati in archivio e in PDF con un impaginatore finto che mostra l'HTML ricevuto; infine due errori.

<!-- esempio:uso.php:inizio -->
```php
<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/esportazione fuori da Moodle: righe da un generatore, formati testuali, formato configurato,
// formati in archivio scritti su file, PDF con un impaginatore, errori.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Contratti\Eccezione;
use Pfi\Esportazione\Esportatore;
use Pfi\Esportazione\Formati\Delimitato;
use Pfi\Esportazione\Formati\LarghezzaFissa;
use Pfi\Esportazione\Impaginatore;

$colonne = ['Dipendente', 'Corso', 'Ore', 'Completato', 'Data'];
// Un generatore, come una lettura dal database: le righe si scrivono una alla volta.
$righe = function(): Generator {  // la terza riga mostra la protezione dalle formule nel CSV
    yield ['Rossi, Mario', 'Sicurezza "base"', 4.5, true, new DateTimeImmutable('2026-11-10')];
    yield ['Bianchi Anna', 'Excel avanzato', 12, false, null];
    yield ['=HYPERLINK("http://example.org")', 'Prova di formula', -3, false, new DateTimeImmutable('2026-11-12')];
};

$e = new Esportatore();
echo 'Formati disponibili: ', implode(', ', $e->nomi()), "\n";

// 1. Formati testuali scritti in un flusso (qui in memoria; in una pagina si usa php://output).
foreach (['csv', 'md', 'json'] as $nome) {
    $flusso = fopen('php://memory', 'w+');
    $n = $e->esporta($righe(), $colonne, $nome, $flusso, 'Presenze ai corsi');
    rewind($flusso);
    $testo = stream_get_contents($flusso);
    echo "\n--- {$nome}: {$n} righe, tipo ", $e->formato($nome)->tipo(), "\n",
        str_starts_with($testo, "\xEF\xBB\xBF") ? "(inizia con il BOM UTF-8)\n" : '', ltrim($testo, "\xEF\xBB\xBF"), "\n";
}

// 2. Formati configurati con un nome: CSV per un gestionale che legge Windows-1252 e testo a larghezza fissa.
$e->formato_con_nome('csv_gestionale', fn() => new Delimitato(';', '"', 'Windows-1252', false, "\r\n"));
$e->formato_con_nome('fisso', fn() => new LarghezzaFissa([17, 19, 5, 4, 26], ['s', 's', 'd', 'd', 's']));
$e->in_file($righe(), $colonne, 'csv_gestionale', __DIR__ . '/gestionale.csv');
echo "--- csv_gestionale: «Città» in Windows-1252 occupa ",
    strlen(mb_convert_encoding('Città', 'Windows-1252', 'UTF-8')), " byte; file di ", filesize(__DIR__ . '/gestionale.csv'), " byte\n";
$flusso = fopen('php://memory', 'w+');
$e->esporta($righe(), $colonne, 'fisso', $flusso);
rewind($flusso);
echo "--- fisso:\n", stream_get_contents($flusso), "\n";

// 3. Formati in archivio scritti su file (scrittura in un temporaneo e spostamento finale).
foreach (['xlsx', 'ods', 'docx', 'odt'] as $nome) {
    $file = __DIR__ . "/presenze.{$nome}";
    $e->in_file($righe(), $colonne, $nome, $file, 'Presenze ai corsi');
    $zip = new ZipArchive();
    $zip->open($file);
    $parti = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $parti[] = $zip->getNameIndex($i);
    }
    echo "{$nome}: archivio con ", count($parti), " parti, la prima è «{$parti[0]}»\n";
}

// 4. PDF: l'esportazione prepara l'HTML e lo passa a un impaginatore (pfi/stampa ne fornisce uno reale).
$finto = new class implements Impaginatore {
    public string $html = '';
    public function pdf(string $html): string { $this->html = $html; return "%PDF-1.7 finto\n"; }
};
$conpdf = new Esportatore($finto);
$flusso = fopen('php://memory', 'w+');
$conpdf->esporta($righe(), $colonne, 'pdf', $flusso, 'Presenze ai corsi');
echo "\npdf: l'impaginatore ha ricevuto una tabella HTML con ", substr_count($finto->html, '<tr'), " righe <tr>\n";

// 5. Errori.
foreach ([fn() => $e->formato('xls'), fn() => $e->esporta([['solo un valore']], $colonne, 'csv', fopen('php://memory', 'w'))] as $f) {
    try {
        $f();
    } catch (Eccezione $x) {
        echo "Errore: {$x->getMessage()}\n";
    }
}
```

Uscita (verificata eseguendo l'esempio in un progetto pulito):

```text
Formati disponibili: csv, csv_pv, tsv, xlsx, ods, docx, odt, rtf, html, json, xml, md

--- csv: 3 righe, tipo text/csv
(inizia con il BOM UTF-8)
Dipendente,Corso,Ore,Completato,Data
"Rossi, Mario","Sicurezza ""base""",4.5,1,2026-11-10T00:00:00+00:00
Bianchi Anna,Excel avanzato,12,0,
"'=HYPERLINK(""http://example.org"")",Prova di formula,-3,0,2026-11-12T00:00:00+00:00


--- md: 3 righe, tipo text/markdown
# Presenze ai corsi

| Dipendente | Corso | Ore | Completato | Data |
|---|---|---|---|---|
| Rossi, Mario | Sicurezza "base" | 4.5 | 1 | 2026-11-10T00:00:00+00:00 |
| Bianchi Anna | Excel avanzato | 12 | 0 |  |
| =HYPERLINK("http://example.org") | Prova di formula | -3 | 0 | 2026-11-12T00:00:00+00:00 |


--- json: 3 righe, tipo application/json
[
{"Dipendente":"Rossi, Mario","Corso":"Sicurezza \"base\"","Ore":4.5,"Completato":true,"Data":"2026-11-10T00:00:00+00:00"},
{"Dipendente":"Bianchi Anna","Corso":"Excel avanzato","Ore":12,"Completato":false,"Data":null},
{"Dipendente":"=HYPERLINK(\"http://example.org\")","Corso":"Prova di formula","Ore":-3,"Completato":false,"Data":"2026-11-12T00:00:00+00:00"}
]

--- csv_gestionale: «Città» in Windows-1252 occupa 5 byte; file di 227 byte
--- fisso:
Dipendente       Corso                OreCompData                      
Rossi, Mario     Sicurezza "base"     4.5   12026-11-10T00:00:00+00:00 
Bianchi Anna     Excel avanzato        12   0                          
=HYPERLINK("http:Prova di formula      -3   02026-11-12T00:00:00+00:00 

xlsx: archivio con 6 parti, la prima è «[Content_Types].xml»
ods: archivio con 3 parti, la prima è «mimetype»
docx: archivio con 3 parti, la prima è «[Content_Types].xml»
odt: archivio con 3 parti, la prima è «mimetype»

pdf: l'impaginatore ha ricevuto una tabella HTML con 4 righe <tr>
Errore: Formato di esportazione sconosciuto: «xls»
Errore: Riga 1: 1 valori per 5 colonne.
```
<!-- esempio:uso.php:fine -->

In una pagina web:

```php
$e = new \Pfi\Esportazione\Esportatore();
$f = $e->formato('xlsx');
header('Content-Type: ' . $f->tipo());
header('Content-Disposition: attachment; filename="presenze.' . $f->estensione() . '"');
$e->esporta($righe, $colonne, 'xlsx', fopen('php://output', 'w'), 'Presenze');
```

# 5. Configurazione

| Costruttore | Parametri e predefiniti |
|---|---|
| `Esportatore` | `$impaginatore` (nessuno: il formato `pdf` non c'è) |
| `Formati\Delimitato` | `$separatore` (`,`, fino a 4 caratteri), `$delimitatore` (`"`, al massimo un carattere, anche vuoto), `$codifica` (`UTF-8`), `$bom` (sì, solo in UTF-8), `$fineriga` (`"\r\n"` o `"\n"`), `$protezione` (sì), `$nome` (`csv`) |
| `Formati\LarghezzaFissa` | `$larghezze` (interi da 1 a 10 000), `$allineamenti` (`s` o `d` per colonna), `$intestazioni` (sì), `$fineriga` (`"\r\n"`) |
| `Formati\Pdf` | `$impaginatore` |

`formato_con_nome($nome, $formato)` accetta un oggetto o una funzione che lo crea; con la funzione ogni esportazione riceve un oggetto nuovo, che è la forma corretta perché i formati hanno stato.

# 6. Errori ed eccezioni

Tutti `Pfi\Contratti\Eccezione`.

| Situazione | Messaggio |
|---|---|
| nome di formato inesistente | «Formato di esportazione sconosciuto: «…»» |
| nome non valido in `formato_con_nome()` | «Nome di formato non valido.» |
| riga con un numero di valori diverso dalle colonne | «Riga N: X valori per Y colonne.» |
| uscita non valida, scrittura non riuscita | «Uscita dell'esportazione non valida.», «Scrittura dell'esportazione non riuscita (spazio o flusso chiuso).» |
| file di destinazione non scrivibile o non spostato | «File dell'esportazione non scrivibile.», «File dell'esportazione non spostato.» |
| parametri del CSV o della larghezza fissa non validi | «Separatore, delimitatore o fine riga non validi.», «Codifica sconosciuta: «…»», «Larghezza di colonna non valida.» |

Se un'eccezione interrompe `in_file()`, il file temporaneo si elimina e il file finale non viene creato.

# 7. Sicurezza

- La protezione dalle formule è attiva nei CSV; disattivarla solo per file destinati a programmi, non a persone con un foglio di calcolo.
- L'esportazione scrive i dati che riceve: il controllo di che cosa l'utente può vedere spetta al codice che prepara le righe.
- I formati in archivio usano un file temporaneo nella cartella temporanea di PHP, grande quanto i dati; se i dati sono riservati la cartella deve essere privata del servizio.
- Nell'HTML e nell'XML i valori hanno l'escape; nel Markdown il carattere `|` dei valori è protetto.

# 8. Prove di salute

Il modulo non dichiara prove di salute: non ha risorse esterne. Le estensioni PHP richieste si controllano con la prova di salute dell'applicazione o con `composer check-platform-reqs`.

# 9. Uso nel portale

Nel portale ogni formato è anche un formato di dati di Moodle (`dataformat_pfidocx`, `pfiodt`, `pfirtf`, `pfixml`, `pfimd`, `pfitsv`, `pficsvpv`), così compare nei menu di scaricamento dei report senza modificarli; il PDF usa l'impaginatore di `pfi/stampa`. Informazione utile solo per confronto.

# 10. Limiti noti

- Date e numeri in forma neutra (ISO 8601, punto decimale): la formattazione locale spetta al programma che apre il file.
- XLSX e ODS non dichiarano larghezze delle colonne né formati numerici; un solo foglio.
- Il testo a larghezza fissa non mette separatori: se i valori riempiono la colonna, le colonne si toccano (come si vede nell'esempio); le larghezze vanno scelte sui dati.
- Il PDF passa per l'HTML e per l'impaginatore: per tabelle molto lunghe tempo e memoria dipendono dal motore di stampa.

# 11. Indicazioni per gli agenti

- Passare un generatore, non un array già caricato, per le esportazioni dal database.
- Scegliere il formato per nome con `Esportatore::formato()`; non creare i formati a mano fuori da `formato_con_nome()`.
- Prendere tipo MIME ed estensione dal formato per le intestazioni HTTP.
- Per scambi con altri sistemi definire un formato con nome (codifica, separatore, fine riga) e documentarlo; non modificare `csv`.
- Nelle prove scrivere su `php://memory` e confrontare il testo; per i formati in archivio aprire il file con `ZipArchive`.

# 12. Riferimento dell'interfaccia

Generato dal codice della versione 1.0.0; lo spazio dei nomi è `Pfi\Esportazione\`.

<!-- riferimento:inizio -->
#### `ArchivioZip`

Classe astratta, estende `Pfi\Esportazione\Base`; implementa `Pfi\Esportazione\Formato`. Base dei formati in archivio ZIP (Office Open XML e OpenDocument): le righe vanno in un file temporaneo come frammento XML, l'archivio si compone alla chiusura e si copia nell'uscita. Richiede l'estensione zip di PHP.

#### `Base`

Classe astratta, implementa `Pfi\Esportazione\Formato`. Base comune: controllo del numero dei valori e conversione dei valori in testo.

| Metodo | Descrizione |
|---|---|
| `inizia($uscita, array $colonne, string $titolo = ''): void` |  |
| `riga(array $valori): void` |  |
| `chiudi(): void` |  |
| `static testo(mixed $v): string` | Valore in testo: date in ISO 8601, booleani 1 e 0, null vuoto, numeri senza notazione scientifica. |

#### `Esportatore`

Classe finale. Punto d'accesso dell'esportazione (release 9.0, blocco M5; proposta 49 § 3.6): formati per nome ed esportazione di righe da qualsiasi sorgente iterabile, in un flusso o in un file.

| Metodo | Descrizione |
|---|---|
| `__construct(?Pfi\Esportazione\Impaginatore $impaginatore = null)` |  |
| `formato_con_nome(string $nome, Pfi\Esportazione\Formato\|Closure $formato): self` | Aggiunge o sostituisce un formato configurato (per esempio un CSV con altra codifica o un testo a larghezza fissa). |
| `nomi(): array` |  |
| `formato(string $nome): Pfi\Esportazione\Formato` |  |
| `esporta(iterable $righe, array $colonne, string $formato, $uscita, string $titolo = ''): int` |  |
| `in_file(iterable $righe, array $colonne, string $formato, string $percorso, string $titolo = ''): int` | Esporta in un file (scrittura in un file temporaneo nella stessa cartella e spostamento finale). |

#### `Formati\Delimitato`

Classe finale, estende `Pfi\Esportazione\Base`; implementa `Pfi\Esportazione\Formato`. Testo delimitato: CSV (RFC 4180), TSV e separatore libero, con delimitatore di testo, codifica, fine riga e segno BOM scelti. Con $protezione (predefinita) un valore che inizia con «=», «+», «-», «@», tabulazione o ritorno a capo è preceduto da un apostrofo, perché un foglio di calcolo non lo esegua come formula (CSV injection); i numeri negativi veri restano tali.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $separatore = ',', promossa string $delimitatore = '"', promossa string $codifica = 'UTF-8', promossa bool $bom = true, promossa string $fineriga = "\r\n", promossa bool $protezione = true, promossa string $nome = 'csv')` |  |
| `static csv(string $separatore = ',', string $codifica = 'UTF-8'): self` |  |
| `static tsv(): self` |  |
| `nome(): string` | Nome breve: csv, tsv, delimitato, fisso, xlsx, ods, docx, odt, rtf, pdf, html, json, xml, md. |
| `estensione(): string` |  |
| `tipo(): string` |  |

#### `Formati\Docx`

Classe finale, estende `Pfi\Esportazione\ArchivioZip`; implementa `Pfi\Esportazione\Formato`. Documento Word (Office Open XML, DOCX) con il titolo e una tabella a pagina A4 orizzontale; la riga d'intestazione si ripete su ogni pagina.

| Metodo | Descrizione |
|---|---|
| `nome(): string` | Nome breve: csv, tsv, delimitato, fisso, xlsx, ods, docx, odt, rtf, pdf, html, json, xml, md. |
| `estensione(): string` |  |
| `tipo(): string` |  |

#### `Formati\Html`

Classe finale, estende `Pfi\Esportazione\Base`; implementa `Pfi\Esportazione\Formato`. Documento HTML con una tabella accessibile (didascalia, intestazioni con scope) e uno stile essenziale per la stampa. Lo usa anche il formato PDF, che lo passa al motore di stampa.

| Metodo | Descrizione |
|---|---|
| `nome(): string` | Nome breve: csv, tsv, delimitato, fisso, xlsx, ods, docx, odt, rtf, pdf, html, json, xml, md. |
| `estensione(): string` |  |
| `tipo(): string` |  |

#### `Formati\Json`

Classe finale, estende `Pfi\Esportazione\Base`; implementa `Pfi\Esportazione\Formato`. JSON: elenco di oggetti con le colonne come chiavi; i numeri e i booleani restano tali.

| Metodo | Descrizione |
|---|---|
| `nome(): string` | Nome breve: csv, tsv, delimitato, fisso, xlsx, ods, docx, odt, rtf, pdf, html, json, xml, md. |
| `estensione(): string` |  |
| `tipo(): string` |  |

#### `Formati\LarghezzaFissa`

Classe finale, estende `Pfi\Esportazione\Base`; implementa `Pfi\Esportazione\Formato`. Testo a larghezza fissa: ogni colonna occupa il numero di caratteri indicato, allineata a sinistra o a destra (i numeri a destra se non indicato), riempita con spazi; un valore più lungo si tronca. Intestazioni facoltative.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa array $larghezze, promossa array $allineamenti = [], promossa bool $intestazioni = true, promossa string $fineriga = "\r\n")` |  |
| `nome(): string` | Nome breve: csv, tsv, delimitato, fisso, xlsx, ods, docx, odt, rtf, pdf, html, json, xml, md. |
| `estensione(): string` |  |
| `tipo(): string` |  |

#### `Formati\Markdown`

Classe finale, estende `Pfi\Esportazione\Base`; implementa `Pfi\Esportazione\Formato`. Tabella Markdown (stile GitHub): «|» protetto con «\», a capo sostituiti da <br>, numeri allineati a destra.

| Metodo | Descrizione |
|---|---|
| `nome(): string` | Nome breve: csv, tsv, delimitato, fisso, xlsx, ods, docx, odt, rtf, pdf, html, json, xml, md. |
| `estensione(): string` |  |
| `tipo(): string` |  |

#### `Formati\Ods`

Classe finale, estende `Pfi\Esportazione\ArchivioZip`; implementa `Pfi\Esportazione\Formato`. Foglio di calcolo OpenDocument (ODS, ISO/IEC 26300): una tabella, numeri come valori numerici.

| Metodo | Descrizione |
|---|---|
| `nome(): string` | Nome breve: csv, tsv, delimitato, fisso, xlsx, ods, docx, odt, rtf, pdf, html, json, xml, md. |
| `estensione(): string` |  |
| `tipo(): string` |  |

#### `Formati\Odt`

Classe finale, estende `Pfi\Esportazione\ArchivioZip`; implementa `Pfi\Esportazione\Formato`. Documento di testo OpenDocument (ODT) con il titolo e una tabella con bordi; la riga d'intestazione si ripete.

| Metodo | Descrizione |
|---|---|
| `nome(): string` | Nome breve: csv, tsv, delimitato, fisso, xlsx, ods, docx, odt, rtf, pdf, html, json, xml, md. |
| `estensione(): string` |  |
| `tipo(): string` |  |

#### `Formati\Pdf`

Classe finale, estende `Pfi\Esportazione\Base`; implementa `Pfi\Esportazione\Formato`. PDF: la tabella del formato HTML impaginata dal motore di stampa (pfi/stampa); senza motore il formato non c'è.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa Pfi\Esportazione\Impaginatore $impaginatore)` |  |
| `nome(): string` | Nome breve: csv, tsv, delimitato, fisso, xlsx, ods, docx, odt, rtf, pdf, html, json, xml, md. |
| `estensione(): string` |  |
| `tipo(): string` |  |

#### `Formati\Rtf`

Classe finale, estende `Pfi\Esportazione\Base`; implementa `Pfi\Esportazione\Formato`. RTF 1.9.1 con una tabella: i caratteri non ASCII come \uN? (Unicode con sostituto «?»), le intestazioni in grassetto, larghezze uguali su una pagina A4 orizzontale con margini di 2 cm.

| Metodo | Descrizione |
|---|---|
| `nome(): string` | Nome breve: csv, tsv, delimitato, fisso, xlsx, ods, docx, odt, rtf, pdf, html, json, xml, md. |
| `estensione(): string` |  |
| `tipo(): string` |  |
| `static rtf(string $s): string` |  |

#### `Formati\Xlsx`

Classe finale, estende `Pfi\Esportazione\ArchivioZip`; implementa `Pfi\Esportazione\Formato`. Foglio di calcolo Office Open XML (ISO/IEC 29500): un foglio, intestazioni in grassetto e bloccate, testi in linea (inlineStr, senza tabella delle stringhe condivise, così le righe si scrivono una alla volta), numeri come numeri.

| Metodo | Descrizione |
|---|---|
| `nome(): string` | Nome breve: csv, tsv, delimitato, fisso, xlsx, ods, docx, odt, rtf, pdf, html, json, xml, md. |
| `estensione(): string` |  |
| `tipo(): string` |  |
| `static lettera(int $i): string` |  |

#### `Formati\Xml`

Classe finale, estende `Pfi\Esportazione\Base`; implementa `Pfi\Esportazione\Formato`. XML: <radice><riga><colonna>valore</colonna>…</riga></radice>; i nomi degli elementi derivano dalle colonne (lettere, cifre, «_», «-», «.»; il resto diventa «_»; un nome che non inizia con una lettera riceve «c_» davanti).

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $radice = 'dati', promossa string $elemento = 'riga')` |  |
| `nome(): string` | Nome breve: csv, tsv, delimitato, fisso, xlsx, ods, docx, odt, rtf, pdf, html, json, xml, md. |
| `estensione(): string` |  |
| `tipo(): string` |  |
| `static nome_elemento(string $c): string` |  |

#### `Formato`

Interfaccia. Formato di esportazione di una tabella (proposta 49 § 3.6): intestazioni e righe scritte una alla volta in un flusso, così che le esportazioni grandi non stiano in memoria. I formati in archivio ZIP (XLSX, ODS, DOCX, ODT) scrivono le righe in un file temporaneo e compongono l'archivio alla chiusura.

| Metodo | Descrizione |
|---|---|
| `nome(): string` | Nome breve: csv, tsv, delimitato, fisso, xlsx, ods, docx, odt, rtf, pdf, html, json, xml, md. |
| `estensione(): string` |  |
| `tipo(): string` |  |
| `inizia($uscita, array $colonne, string $titolo = ''): void` |  |
| `riga(array $valori): void` |  |
| `chiudi(): void` |  |

#### `Impaginatore`

Interfaccia. Motore che trasforma un documento HTML in PDF; lo fornisce pfi/stampa (Chromium senza interfaccia, D-161 c).

| Metodo | Descrizione |
|---|---|
| `pdf(string $html): string` |  |

<!-- riferimento:fine -->

# Registro delle versioni del manuale

| Versione | Data | Modifiche |
|---|---|---|
| 1.0.0 | 16 novembre 2026 | prima versione, per pfi/esportazione 1.0.0 |
