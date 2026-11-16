# pfi/esportazione

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva.

Esportazione di tabelle dei moduli universali (release 9.0, blocco M5; proposta 49 § 3.6). Richiede `pfi/contratti` ^1.0 e le estensioni mbstring, zip e iconv di PHP; il formato PDF richiede un motore di stampa (`pfi/stampa`). Nel portale ogni formato diventa anche un formato di dati di Moodle (`dataformat_pfi*`), così compare nei menu di scaricamento dei report senza modificarli.

## Formati

| Nome | Classe | Note |
|---|---|---|
| `csv`, `csv_pv` | `Formati\Delimitato::csv()` | RFC 4180; separatore virgola o punto e virgola; UTF-8 con BOM; protezione dalle formule |
| `tsv` | `Formati\Delimitato::tsv()` | tabulazione, senza delimitatore: tabulazioni e a capo nei valori diventano spazi |
| a scelta | `new Formati\Delimitato($sep, $delim, $codifica, $bom, $fineriga, $protezione)` | separatore libero fino a 4 caratteri, codifica fra quelle di mbstring (per esempio Windows-1252) |
| a scelta | `new Formati\LarghezzaFissa($larghezze, $allineamenti, $intestazioni)` | colonne a larghezza fissa, numeri a destra |
| `xlsx` | `Formati\Xlsx` | Office Open XML, intestazione in grassetto e bloccata, numeri come numeri |
| `ods` | `Formati\Ods` | OpenDocument |
| `docx`, `odt` | `Formati\Docx`, `Formati\Odt` | documento con titolo e tabella; intestazione ripetuta su ogni pagina |
| `rtf` | `Formati\Rtf` | tabella RTF con caratteri Unicode |
| `pdf` | `Formati\Pdf` | tabella HTML impaginata da `Impaginatore` (pfi/stampa) |
| `html`, `json`, `xml`, `md` | `Formati\Html`, `Json`, `Xml`, `Markdown` | tabella accessibile; elenco di oggetti; elementi dalle colonne; tabella GitHub |

I formati in archivio (XLSX, ODS, DOCX, ODT) sono scritti dalla libreria stessa, senza librerie esterne: per tabelle di dati bastano poche parti dell'archivio, e così non serve scegliere e mantenere librerie con licenza compatibile (proposta 49 § 3.6). I file prodotti sono stati letti con openpyxl, python-docx, odfpy e LibreOffice.

## Uso

```php
$e = new \Pfi\Esportazione\Esportatore($impaginatore);   // $impaginatore facoltativo, per il PDF
$e->esporta($righe, ['Nome', 'Ore'], 'xlsx', $flusso, 'Presenze');
$e->in_file($righe, ['Nome', 'Ore'], 'csv', '/var/tmp/presenze.csv');
$e->formato_con_nome('csv_ente', fn() => new \Pfi\Esportazione\Formati\Delimitato(';', '"', 'Windows-1252', false));
```

`$righe` è qualsiasi iterabile, anche un generatore che legge dal database: le righe si scrivono una alla volta (50 000 righe in XLSX con meno di 16 MB di memoria nelle prove).

## Limiti

- I formati in archivio usano un file temporaneo grande quanto i dati, nella cartella temporanea di PHP.
- Date e numeri si esportano in forma neutra (ISO 8601, punto decimale): la formattazione locale spetta al programma che apre il file.
- XLSX e ODS non dichiarano larghezze delle colonne né formati numerici.
