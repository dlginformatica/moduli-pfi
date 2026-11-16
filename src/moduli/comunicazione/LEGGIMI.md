# pfi/comunicazione

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva.

Comunicazione multicanale dei moduli universali (release 9.0, blocco M4; D-161 d; proposta 49 § 3.4). Richiede `pfi/contratti`, `pfi/connettori` e `pfi/modelli` ^1.0 e le estensioni openssl e mbstring di PHP (curl per i connettori). Nel portale la configura `tool_pficomunicazione`, che aggiunge il canale «notifica» del portale; i componenti esistenti vi passano nel blocco M8.

## Contratto per i componenti

```php
$ids = $comunicazione->invia('posta', 'tool_pfiaula/promemoria', $destinatari, $dati, [
    'lingua' => 'it', 'dati_per' => fn(Indirizzo $d) => ['nome' => $nomi[$d->valore]], 'subito' => false]);
```

Per ogni destinatario il modello (pfi/modelli) si rende con i dati, il messaggio si accoda e il cron lo invia con `elabora()`. Errori temporanei (rete, 4xx di SMTP, 429 e 5xx delle API): nuovo tentativo dopo 1, 5, 15, 60 e 240 minuti, poi «fallito». Errori definitivi: «fallito» subito. Dopo l'esito finale il contenuto del messaggio si cancella dalla coda; restano canale, modello e versione, destinatario pseudonimizzato (in chiaro solo se la configurazione lo chiede), esito, identificativo del fornitore e correlazione.

Parti dei modelli per canale: posta `oggetto`, `html`, `testo` (ricavato dall'HTML se manca); Telegram `oggetto` e `testo`; WhatsApp `nome` del modello approvato e `parametri` (uno per riga, nell'ordine dei segnaposto del modello di Meta).

## Canali

| Canale | Classe | Configurazione | Riferimento verificato il 14 novembre 2026 |
|---|---|---|---|
| Posta SMTP | `Posta\Smtp` | server, porta, sicurezza `starttls` (587) o `tls` (465), account, password | RFC 5321, 3207, 4954, 8314 |
| Microsoft 365 | `Posta\Graph` | tenant, identificativo e segreto dell'applicazione (`Gettone::microsoft`), casella; permesso Mail.Send | learn.microsoft.com/graph/api/user-sendmail |
| Google Workspace | `Posta\Gmail` | file JSON del conto di servizio con delega a livello di dominio (`Gettone::google`), casella; ambito gmail.send | developers.google.com/workspace/gmail/api/guides/sending |
| Amazon SES | `Posta\Ses` | regione, chiave d'accesso e segreto (firma Signature Version 4) | docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendEmail.html |
| SendGrid | `Posta\SendGrid` | chiave API | twilio.com/docs/sendgrid/api-reference/mail-send |
| Mailgun | `Posta\Mailgun` | dominio, chiave API, sede `eu` o `us` | documentation.mailgun.com/docs/mailgun/api-reference/send |
| Brevo | `Posta\Brevo` | chiave API | developers.brevo.com/reference/send-transac-email |
| Postmark | `Posta\Postmark` | gettone del server, flusso dei messaggi | postmarkapp.com/developer/api/email-api |
| Telegram | `Canali\Telegram` | token del bot; destinatario: identificativo di chat | core.telegram.org/bots/api |
| WhatsApp | `Canali\WhatsApp` | gettone, identificativo del numero, versione delle API | developers.facebook.com/docs/whatsapp/cloud-api |

Le API inviano attraverso `pfi/connettori` (tentativi, limiti di frequenza, registro senza segreti). Graph, Gmail, SES e Mailgun ricevono il messaggio MIME completo composto da `Mime`, uguale a quello inviato con SMTP; SendGrid, Brevo e Postmark ricevono i campi in JSON.

## Interfaccia pubblica

| Elemento | Scopo |
|---|---|
| `Canale` | `tipo()`, `nome()`, `invia(Messaggio): Invio`, `salute()` |
| `Messaggio` | destinatari, oggetto, html, testo, mittente, cc, ccn, allegati, rispondi a, intestazioni `X-…`, dati del canale; `testo_semplice()`, `per()`, `a_array()`, `da_array()` |
| `Indirizzo` | `Indirizzo::posta()` controlla l'indirizzo; `pseudonimo($sale)` |
| `ErroreInvio` | `temporaneo`, `codice`; `da_stato_http()` |
| `Mime::componi()` | messaggio RFC 5322 e Message-ID |
| `Coda`, `CodaInvii` | interfaccia della coda e realizzazione con una tabella PDO: `crea_tabella()`, `accoda()`, `prendi()`, `prendi_uno()`, `esito()`, `cerca()`, `conserva()` |
| `Comunicazione` | `canale()`, `invia()`, `elabora()` |
| `Canali\Telegram::webhook_autentico()`, `ricevuto()`; `Canali\WhatsApp::webhook_autentico()` | verifica dei messaggi in arrivo |
| `Ricezione\Imap`, `Ricezione\Pop3` | caselle di servizio in ricezione: `ricevi($massimo, $segna)`, `salute()`; IMAP con password o con gettone OAuth 2.0 (XOAUTH2) per Microsoft 365 e Google Workspace |
| `Ricezione\Ricevuto` | messaggio ricevuto: mittente, oggetto, data, Message-ID, In-Reply-To, correlazione (`X-Pfi-Correlazione`), testo semplice, rapporto di mancata consegna (RFC 3464) |
| `Salute\Canali` | prova di salute `comunicazione.canali` |

## Limiti

- Le chiamate ai fornitori via API sono provate con il fornitore finto di `pfi/connettori` sui formati della documentazione ufficiale; la prova con account reali dipende dagli account di prova (BRIEF-v9, dipendenze esterne) [DA VERIFICARE].
- La versione predefinita delle API di WhatsApp è indicativa: va indicata quella in uso al momento della configurazione [DA VERIFICARE].
- La ricezione della posta è nella libreria (IMAP e POP3, con XOAUTH2 per Microsoft 365 e Google Workspace); la sua attivazione nel portale attende il parere del Responsabile della protezione dei dati (BRIEF-v9). Gli ambiti dei gettoni OAuth per IMAP presso i due fornitori vanno verificati al momento della configurazione [DA VERIFICARE].
- POP3 non conserva lo stato di lettura: l'applicazione conserva gli UIDL già letti (`$letti`) oppure fa cancellare i messaggi dal server.
- La pseudonimizzazione con il sale protegge il registro da una lettura casuale, non da chi conosce già l'indirizzo e il sale.
