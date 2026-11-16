# pfi/connettori

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.

Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva.

Client HTTP comune verso i servizi esterni (release 9.0, blocco M2a; D-163; proposta 49 § 3.10). Vi passano la posta via API (Microsoft Graph, Gmail API, Amazon SES, SendGrid, Mailgun, Brevo, Postmark), Telegram, WhatsApp e i fornitori di intelligenza artificiale e di immagini. Richiede `pfi/contratti` ^1.0 e l'estensione cURL di PHP; le prove usano anche `pfi/registro`.

## Interfaccia pubblica

| Elemento | Scopo |
|---|---|
| `Connettore($nome, $trasporto, $politica, $limite, $registro)` | `invia($richiesta, $accettati)`: risposta 2xx (o di uno stato accettato) oppure `ErroreConnettore` |
| `Richiesta` | metodo, indirizzo (solo http e https), intestazioni, corpo, tempo massimo; `Richiesta::json()`, `Richiesta::modulo()`, `con_intestazione()` |
| `Risposta` | stato, intestazioni in minuscolo, corpo, durata, tentativi; `json()`, `intestazione()` |
| `Politica($tentativi, $attesainiziale, $attesamassima, $ritenta500)` | si ritenta su errore di rete, 429, 503 e, per i metodi idempotenti o con `Idempotency-Key`, su 502 e 504 (500 se richiesto) |
| `Limite($richieste, $secondi, $file)` | al massimo `$richieste` ogni `$secondi`; con `$file` il limite vale per tutti i processi del server |
| `Trasporti\Curl` | tempi massimi, proxy dell'ente con eccezioni, autorità di certificazione propria, dimensione massima della risposta |
| `Trasporti\Finto` | fornitore finto: risposte in coda o calcolate, richieste ricevute in `$ricevute` |
| `ErroreConnettore` | `stato` (0 per errore di rete), `risposta` per leggere il corpo dell'errore; il messaggio non contiene il corpo |
| `Salute\Raggiungibili` | prova di salute `connettori.raggiungibili` |

## Uso fuori da Moodle

```php
use Pfi\Connettori\{Connettore, Politica, Limite, Richiesta};
use Pfi\Connettori\Trasporti\Curl;

$c = new Connettore('postmark', new Curl(30, 10, ['indirizzo' => 'http://proxy.ente.example:3128']),
    new Politica(3, 0.5, 30), new Limite(10, 1.0, '/var/tmp/pfi-limite-postmark'), $registro);
$r = $c->invia(Richiesta::json('POST', 'https://api.postmarkapp.com/email', $messaggio,
    ['X-Postmark-Server-Token' => $segreti->valore('postmark')]));
```

## Limiti

- I reindirizzamenti non si seguono: il chiamante riceve lo stato 3xx e decide. Così le credenziali non arrivano mai a un host diverso da quello configurato.
- Le attese fra i tentativi tengono occupato il processo: nelle pagine si usano tentativi brevi, nelle code del cron tentativi lunghi.
- Il limite condiviso su file conta le richieste di un solo server; con più server ciascuno ha il proprio limite.
