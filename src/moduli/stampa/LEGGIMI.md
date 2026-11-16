# pfi/stampa

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva.

Stampa in PDF con i modelli (release 9.0, blocco M6; D-161 c; proposta 49 § 3.7). Richiede `pfi/contratti`, `pfi/modelli` e `pfi/esportazione` ^1.0 e il programma Chromium senza interfaccia sul server; per i codici QR la classe TCPDF2DBarcode (presente in Moodle) o il programma `qrencode`. Nel portale l'editor e i modelli predefiniti (locandina, schede dell'edizione e della sede, foglio firme, Quaderno) sono nell'adattatore `tool_pfistampa`; TCPDF resta per le stampe di Moodle e di `mod_customcert`.

## Modelli di stampa

Un modello di stampa è un modello di `pfi/modelli` del canale `stampa` con le parti:

| Parte | Uso |
|---|---|
| `html` | corpo del documento (obbligatoria) |
| `titolo` | titolo del documento PDF |
| `intestazione`, `piedipagina` | testo in alto e in basso su ogni pagina; `{pagina}` e `{pagine}` danno il numero e il totale |

Tabelle con `<thead>`: l'intestazione si ripete su ogni pagina; una riga non si spezza fra due pagine; la classe `salto` forza il salto di pagina dopo un elemento.

## Sicurezza

Il documento dichiara una politica dei contenuti `default-src 'none'; img-src data:; font-src data:; style-src 'unsafe-inline'`: nessuno script, nessuna immagine, foglio di stile o carattere esterno. Le immagini e i codici QR si incorporano come `data:`. Le prove lo verificano con un server locale che non deve ricevere richieste. Chromium gira con un profilo temporaneo, senza rete in background e con tempo massimo; la sandbox resta attiva salvo esecuzione come root.

## Interfaccia pubblica

| Elemento | Scopo |
|---|---|
| `Pagina($formato, $orizzontale, $margini, $intestazione, $piedipagina)` | formati A4, A3, A5, letter, legal; margini in mm; `css()` |
| `Documento::html($corpo, $pagina, $titolo, $lingua, $stile)` | documento completo con la politica dei contenuti |
| `Chromium($programma, $tempomassimo, $senzasandbox)` | `pdf($html)`; `Chromium::cerca()` trova il programma installato; è l'`Impaginatore` del formato PDF di pfi/esportazione |
| `Qr::svg($testo)`, `Qr::img($testo, $alternativo)` | codice QR in SVG o come immagine incorporata |
| `Stampa($modelli, $impaginatore, $registro)` | `anteprima()` (HTML) e `pdf()` |
| `Salute\Motore` | prova di salute `stampa.motore` |

## Limiti

- Il formato PDF/A per l'archiviazione, previsto come opzione nella proposta 49, non è in questa versione: richiede una conversione (per esempio con Ghostscript) e la validazione con uno strumento dedicato [DA VERIFICARE prima di promettere la conformità].
- Le prove sono eseguite con Chrome Headless Shell 153; il pacchetto di Debian installato dal kit e il funzionamento della sandbox con l'utente del server web vanno provati sulla VM del kit (blocco M9) [DA VERIFICARE].
- Le caselle dei margini di CSS (`@top-center`, `@bottom-center`) richiedono una versione recente di Chromium (sono nelle versioni dal 2024) [DA VERIFICARE con la versione di Debian].
