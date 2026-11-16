# Storia di pfi/esportazione

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

## 1.0.0 - 14 novembre 2026

Prima versione (release 9.0 del portale, blocco M5, proposta 49 § 3.6): formati `Delimitato` (CSV con separatore, delimitatore, codifica, fine riga e BOM scelti, protezione dalle formule; TSV; separatore libero), `LarghezzaFissa`, `Xlsx` e `Ods` (fogli di calcolo con numeri come numeri), `Docx` e `Odt` (documenti con tabella e intestazione ripetuta), `Rtf`, `Html`, `Json`, `Xml`, `Markdown` e `Pdf` attraverso un motore di stampa (`Impaginatore`); scrittura riga per riga, con i formati in archivio ZIP composti da un file temporaneo; `Esportatore` con i formati per nome, l'esportazione in un flusso o in un file.
