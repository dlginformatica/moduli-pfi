# Storia di pfi/backup

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

## 1.0.0 - 14 novembre 2026

Prima versione (release 9.0 del portale, blocco M7, proposta 49 § 3.8, F-01 e F-03 del rapporto 48): `Backup` con unità scelte, cartella AAAAMMGG-HHMMSS 0700, MANIFESTO.json, RILASCIO e SHA256SUMS compatibili con pfi-ripristino, copie nelle destinazioni remote, conservazione per numero e per giorni, ripristino granulare con controllo delle impronte e del rilascio e prova a vuoto; unità `Postgres` (ripristino con il database ricreato vuoto, prova con ripristino in un database temporaneo) e `Cartella` (tar.gz, esclusioni, cifratura facoltativa, dati attuali accantonati); `Cifratura` a flusso con libsodium; destinazioni `Cartella` (locale o di rete montata) e `S3` (firma Signature Version 4, caricamento in streaming); prova di salute `backup.recente`.
