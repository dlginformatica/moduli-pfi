# Storia di pfi/comunicazione

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

## 1.0.0 - 14 novembre 2026

Prima versione (release 9.0 del portale, blocco M4, D-161 d, proposta 49 § 3.4): `Messaggio`, `Indirizzo`, `Allegato`; composizione `Mime` (RFC 5322, parole codificate, quoted-printable, allegati con nomi UTF-8); canale `Posta\Smtp` con STARTTLS o TLS, autenticazione PLAIN o LOGIN, errori 4xx temporanei e 5xx definitivi; posta con le API di Microsoft Graph, Gmail API (conto di servizio con JWT firmato), Amazon SES v2 (firma Signature Version 4), SendGrid, Mailgun, Brevo e Postmark; canali `Telegram` e `WhatsApp` (modelli approvati) con la verifica dei webhook; `Coda` (interfaccia) e `CodaInvii` (PDO) con stato, tentativi, destinatario pseudonimizzato e cancellazione del contenuto dopo l'esito; `Comunicazione` con il contratto `invia($canale, $modello, $destinatari, $dati)` (con l'etichetta registrata al posto della chiave per i messaggi composti dall'applicazione) e la coda con tentativi a 1, 5, 15, 60 e 240 minuti; ricezione dalle caselle di servizio con `Ricezione\Imap` (password o XOAUTH2) e `Ricezione\Pop3`, analisi dei messaggi ricevuti e dei rapporti di mancata consegna; prova di salute `comunicazione.canali`.
