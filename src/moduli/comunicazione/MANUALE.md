---
titolo: "Manuale di pfi/comunicazione"
sottotitolo: "Moduli universali del Portale di autoformazione IT: messaggi su posta, API dei fornitori, Telegram e WhatsApp, con coda, tentativi e ricezione"
versione: "1.0.0"
data: "2026-11-16"
verifica_fonti: "2026-11-16"
prossima_revisione: "a ogni nuova versione del modulo; documentazione dei fornitori verificata il 14 novembre 2026"
rilascio: "pfi/comunicazione 1.0.0"
autori: "Gaetano Giarrusso e Ufficio USIS"
hash: "n.d. (calcolato nel MANIFEST)"
classificazione: "USO INTERNO"
---

# Manuale di pfi/comunicazione

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale. Copyright 2026 Gaetano Giarrusso, licenza GPL v3 o successiva.

Il manuale è scritto per uno sviluppatore che riusa il modulo in un'applicazione PHP diversa dal portale e per un agente di intelligenza artificiale che deve scrivere codice che lo usa. Il riferimento dell'interfaccia (capitolo 12) è estratto dal codice; gli esempi sono eseguiti in un progetto pulito e l'uscita riportata è quella ottenuta. Distribuzione, versioni e regole comuni sono nella guida generale (`src/moduli/MANUALE-GENERALE.md`).

# 1. A che cosa serve

`pfi/comunicazione` invia messaggi alle persone attraverso un **canale** (posta elettronica con SMTP o con l'API di un fornitore, Telegram, WhatsApp) a partire da un **modello** di `pfi/modelli`. Il codice dell'applicazione dice soltanto *che cosa* inviare e *a chi*: `invia('posta', 'corsi/promemoria', $destinatari, $dati)`. Il modulo rende il modello per ogni destinatario, mette un invio per destinatario in una **coda** su database e lo esegue subito o con il cron; gli errori temporanei si ritentano dopo 1, 5, 15, 60 e 240 minuti.

Dopo l'esito finale il contenuto del messaggio si cancella dalla coda: restano canale, modello e versione, destinatario pseudonimizzato, esito, identificativo del fornitore e correlazione. Le chiamate ai fornitori passano da `pfi/connettori` (certificati verificati, limiti, registro senza segreti).

Il modulo legge anche le caselle di servizio (IMAP e POP3, con OAuth 2.0 per Microsoft 365 e Google Workspace) e riconosce i rapporti di mancata consegna. Non è un sistema di posta massiva o di marketing: non gestisce iscrizioni, liste né statistiche di apertura.

# 2. Installazione

Requisiti: PHP 8.2 o successivo con le estensioni `openssl`, `mbstring` e `curl`; `pfi/contratti`, `pfi/connettori` e `pfi/modelli` ^1.0; PDO con il driver del database per la coda.

```bash
composer require pfi/comunicazione:^1.0
```

Fuori da Moodle la tabella della coda si crea una volta con `(new \Pfi\Comunicazione\CodaInvii($pdo))->crea_tabella()`; il cron chiama `elabora()` ogni minuto e `CodaInvii::conserva($giorni)` una volta al giorno.

# 3. Concetti

**Canali disponibili** (documentazione dei fornitori verificata il 14 novembre 2026).

| Tipo | Classe | Configurazione | Riferimento |
|---|---|---|---|
| `posta` | `Posta\Smtp` | server, porta, sicurezza `starttls` (587) o `tls` (465), account, password | RFC 5321, 3207, 4954, 8314 |
| `posta` | `Posta\Graph` (Microsoft 365) | `Gettone::microsoft()` con tenant, identificativo e segreto dell'applicazione; casella; permesso Mail.Send | learn.microsoft.com/graph/api/user-sendmail |
| `posta` | `Posta\Gmail` (Google Workspace) | `Gettone::google()` con la chiave JSON del conto di servizio e la delega a livello di dominio; ambito gmail.send | developers.google.com/workspace/gmail/api/guides/sending |
| `posta` | `Posta\Ses` (Amazon SES) | regione, chiave d'accesso e segreto (firma Signature Version 4) | docs.aws.amazon.com/ses/latest/APIReference-V2/API_SendEmail.html |
| `posta` | `Posta\SendGrid`, `Posta\Mailgun`, `Posta\Brevo`, `Posta\Postmark` | chiave API (Mailgun anche dominio e sede `eu` o `us`; Postmark gettone del server e flusso) | documentazione API dei fornitori |
| `telegram` | `Canali\Telegram` | token del bot; destinatario: identificativo di chat o `@canale` | core.telegram.org/bots/api |
| `whatsapp` | `Canali\WhatsApp` | gettone, identificativo del numero, versione delle API | developers.facebook.com/docs/whatsapp/cloud-api |

Un oggetto `Comunicazione` ha **un canale per tipo**: la configurazione sceglie il fornitore della posta. Graph, Gmail, SES e Mailgun ricevono il messaggio MIME completo composto da `Mime`, lo stesso inviato con SMTP; SendGrid, Brevo e Postmark ricevono i campi in JSON. Un'applicazione può aggiungere un canale implementando `Pfi\Comunicazione\Canale`.

**Parti dei modelli per canale.** Posta: `oggetto`, `html`, `testo` (ricavato dall'HTML se manca). Telegram: `testo` e, facoltativo, `oggetto` (messo in testa). WhatsApp: `nome` del modello approvato da Meta e `parametri`, uno per riga, nell'ordine dei segnaposto del modello di Meta.

**Invio.** `invia($canale, $chiave, $destinatari, $dati, $opzioni)` restituisce gli identificativi degli invii. Opzioni: `lingua` (`it`), `dati_per` (funzione che riceve l'`Indirizzo` e restituisce i dati personali di quel destinatario, uniti a `$dati`), `allegati` (oggetti `Allegato`), `rispondia`, `intestazioni` (solo `X-…`, `List-Unsubscribe`, `List-Unsubscribe-Post`, `Auto-Submitted`), `correlazione` (altrimenti casuale; finisce nell'intestazione `X-Pfi-Correlazione`), `subito` (esegue senza aspettare il cron), `etichetta` (nome registrato al posto della chiave), `extra` (dati propri del canale).

**Esiti e tentativi.** Stati della coda: `in_coda`, `in_invio`, `inviato`, `fallito`. `ErroreInvio` ha `temporaneo` e `codice`: temporanei gli errori di rete, i 4xx di SMTP, i 408, 425, 429 e 5xx delle API; definitivi gli altri. Un errore temporaneo riprogramma l'invio (1, 5, 15, 60, 240 minuti), dopo il quinto diventa `fallito`; uno definitivo è `fallito` subito.

**Indirizzi e pseudonimi.** `Indirizzo::posta($email, $nome)` controlla l'indirizzo; `new Indirizzo($valore)` serve per gli altri canali (chat di Telegram, numero di WhatsApp). Nella coda il destinatario è `pseudonimo($sale)`: i primi 16 caratteri di SHA-256 di sale e indirizzo; in chiaro solo se la `Comunicazione` è costruita con `$destinatariinchiaro = true`.

**Ricezione.** `Ricezione\Imap` (password o gettone OAuth 2.0 con XOAUTH2) e `Ricezione\Pop3` implementano `Ricezione\Casella`: `ricevi($massimo, $segna)` restituisce oggetti `Ricevuto` (mittente, oggetto, data, Message-ID, In-Reply-To, correlazione letta da `X-Pfi-Correlazione`, testo semplice, rapporto di mancata consegna secondo RFC 3464). IMAP legge i messaggi non letti con `BODY.PEEK[]` e poi li segna letti; POP3 non conserva lo stato di lettura: l'applicazione passa una funzione `$visto` per gli UIDL già letti oppure fa cancellare i messaggi.

**Messaggi in arrivo da Telegram e WhatsApp.** `Telegram::webhook_autentico($intestazione, $segreto)` e `ricevuto($json)`; `WhatsApp::webhook_autentico($corpo, $intestazione, $segretoapp)` verifica la firma HMAC di Meta.

# 4. Uso passo per passo

L'esempio configura due canali, Postmark per la posta e Telegram, con il fornitore finto di `pfi/connettori` al posto della rete. Invia un promemoria a due persone con un dato personale per ciascuna: il primo invio trova il fornitore occupato (503) e resta in coda per il nuovo tentativo, il secondo riesce. Poi mostra che cosa ha ricevuto il fornitore, invia subito un messaggio Telegram, elenca la coda (destinatari pseudonimizzati, contenuto cancellato dopo l'esito finale) e la prova di salute.

<!-- esempio:uso.php:inizio -->
```php
<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/comunicazione fuori da Moodle: modelli per canale, coda su SQLite, invio di posta con un
// fornitore via API (Postmark) e con Telegram. Al posto della rete c'è il fornitore finto di pfi/connettori, che
// riceve le richieste e risponde come da documentazione dei fornitori.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Comunicazione\Canali\Telegram;
use Pfi\Comunicazione\CodaInvii;
use Pfi\Comunicazione\Comunicazione;
use Pfi\Comunicazione\Indirizzo;
use Pfi\Comunicazione\Posta\Postmark;
use Pfi\Comunicazione\Salute\Canali;
use Pfi\Connettori\Connettore;
use Pfi\Connettori\Politica;
use Pfi\Connettori\Risposta;
use Pfi\Connettori\Trasporti\Finto;
use Pfi\Contratti\Eccezione;
use Pfi\Modelli\Archivi\Memoria;
use Pfi\Modelli\Modelli;
use Pfi\Modelli\Modello;
use Pfi\Modelli\Schema;

// 1. Modelli del messaggio: uno per canale, con le parti richieste dal canale.
$schema = (new Schema('corsi/promemoria', 'Promemoria della lezione'))
    ->segnaposto('nome', 'Nome del partecipante', 'Maria')
    ->segnaposto('corso', 'Titolo del corso', 'Excel avanzato')
    ->segnaposto('quando', 'Data e ora già formattate', 'lunedì 23 novembre alle 9:30');
$modelli = (new Modelli(new Memoria()))->dichiara($schema,
    new Modello('corsi/promemoria', 'posta', 'it', [
        'oggetto' => 'Promemoria: {{corso}}',
        'html' => '<p>Gentile {{nome}},</p><p>la lezione di <b>{{corso}}</b> è {{quando}}.</p>',
    ]),
    new Modello('corsi/promemoria', 'telegram', 'it', ['testo' => '{{corso}}: lezione {{quando}}.']));

// 2. Coda su database (fuori da Moodle la tabella si crea con crea_tabella()).
$coda = new CodaInvii(new PDO('sqlite::memory:'));
$coda->crea_tabella();

// 3. Canali. Nel codice reale: new Connettore('postmark', new Curl(...), ...) e gettoni letti da pfi/segreti.
$postmark = new Finto();
$telegram = new Finto();
$comunicazione = (new Comunicazione($modelli, $coda, null, Indirizzo::posta('formazione@example.org', 'Ufficio formazione'),
        'sale-di-prova-non-usare'))
    ->canale(new Postmark(new Connettore('postmark', $postmark, new Politica(1)), 'GETTONE-DI-PROVA'))
    ->canale(new Telegram(new Connettore('telegram', $telegram, new Politica(1)), '123456:GETTONE_DI_PROVA_non_valido_per_Telegram'));

// 4. Posta a due persone: il primo invio trova il fornitore occupato (503), il secondo riesce.
$postmark->accoda(new Risposta(503), Finto::json(200, ['ErrorCode' => 0, 'MessageID' => 'b7bc2f4a-0001']));
$nomi = ['mario.rossi@example.org' => 'Mario', 'anna.bianchi@example.org' => 'Anna'];
$ids = $comunicazione->invia('posta', 'corsi/promemoria',
    [Indirizzo::posta('mario.rossi@example.org'), Indirizzo::posta('anna.bianchi@example.org')],
    ['corso' => 'Excel avanzato', 'quando' => 'lunedì 23 novembre alle 9:30'],
    ['dati_per' => fn(Indirizzo $d) => ['nome' => $nomi[$d->valore]], 'correlazione' => 'promemoria-2026-11-23']);
echo 'Invii accodati: ', count($ids), "; trattati dal cron: ", $comunicazione->elabora(), "\n";

$richiesta = json_decode($postmark->ricevute[1]->corpo, true);
echo "Postmark ha ricevuto: From={$richiesta['From']}, To={$richiesta['To']}, Subject={$richiesta['Subject']}\n";
echo "  HtmlBody={$richiesta['HtmlBody']}\n  TextBody=", str_replace("\n", ' / ', $richiesta['TextBody']), "\n";
echo "  intestazione di correlazione: ", $richiesta['Headers'][0]['Name'], '=', $richiesta['Headers'][0]['Value'], "\n";

// 5. Telegram, inviato subito invece che dal cron.
$telegram->accoda(Finto::json(200, ['ok' => true, 'result' => ['message_id' => 4242]]));
$comunicazione->invia('telegram', 'corsi/promemoria', [new Indirizzo('987654321')],
    ['corso' => 'Excel avanzato', 'quando' => 'lunedì alle 9:30'], ['subito' => true]);
echo "Telegram ha ricevuto: ", json_decode($telegram->ricevute[0]->corpo, true)['text'], "\n";

// 6. Stato della coda: destinatari pseudonimizzati, contenuto cancellato dopo l'esito finale.
echo "\nCoda:\n";
foreach (array_reverse($coda->cerca()) as $r) {
    $contenuto = $coda->riga((int) $r['id'])['contenuto'] ?? null;
    printf("  %-8s %-9s tentativi %d, destinatario %s, fornitore «%s», esito «%s», contenuto %s\n", $r['canale'], $r['stato'],
        $r['tentativi'], $r['destinatario'] === Indirizzo::posta('mario.rossi@example.org')->pseudonimo('sale-di-prova-non-usare')
            ? 'pseudonimo di Mario' : 'pseudonimo', $r['idfornitore'], $r['esito'], $contenuto === null ? 'cancellato' : 'conservato per il nuovo tentativo');
}

// 7. Errori.
try {
    Indirizzo::posta('non-un-indirizzo');
} catch (Eccezione $e) {
    echo "\nErrore: {$e->getMessage()}\n";
}
try {
    $comunicazione->invia('whatsapp', 'corsi/promemoria', [new Indirizzo('393331234567')], []);
} catch (Eccezione $e) {
    echo "Errore: {$e->getMessage()}\n";
}
// 8. Prova di salute: chiede a ogni fornitore di riconoscere le credenziali (qui Postmark le accetta, Telegram no).
$postmark->accoda(Finto::json(200, ['Name' => 'Server di prova']));
$telegram->accoda(Finto::json(401, ['ok' => false, 'description' => 'Unauthorized']));
$salute = (new Canali($comunicazione))->esegui();
echo "Salute: {$salute->stato}, {$salute->messaggio}\n";
foreach ($salute->dettagli as $canale => $d) {
    echo "  {$canale}: {$d}\n";
}
```

Uscita (verificata eseguendo l'esempio in un progetto pulito):

```text
Invii accodati: 2; trattati dal cron: 2
Postmark ha ricevuto: From="Ufficio formazione" <formazione@example.org>, To=anna.bianchi@example.org, Subject=Promemoria: Excel avanzato
  HtmlBody=<p>Gentile Anna,</p><p>la lezione di <b>Excel avanzato</b> è lunedì 23 novembre alle 9:30.</p>
  TextBody=Gentile Anna, / la lezione di Excel avanzato è lunedì 23 novembre alle 9:30.
  intestazione di correlazione: X-Pfi-Correlazione=promemoria-2026-11-23
Telegram ha ricevuto: Excel avanzato: lezione lunedì alle 9:30.

Coda:
  posta    in_coda   tentativi 1, destinatario pseudonimo di Mario, fornitore «», esito «postmark: invio non riuscito (503)», contenuto conservato per il nuovo tentativo
  posta    inviato   tentativi 1, destinatario pseudonimo, fornitore «b7bc2f4a-0001», esito «accettato 200», contenuto cancellato
  telegram inviato   tentativi 1, destinatario pseudonimo, fornitore «4242», esito «accettato 200», contenuto cancellato

Errore: Indirizzo di posta non valido.
Errore: Canale non configurato: «whatsapp»
Salute: guasto, Canali di comunicazione con problemi.
  posta: buono: postmark: credenziali accettate.
  telegram: guasto: Token di Telegram rifiutato.
```
<!-- esempio:uso.php:fine -->

Configurazione reale con SMTP e segreti dal deposito:

```php
use Pfi\Comunicazione\{Comunicazione, CodaInvii, Indirizzo};
use Pfi\Comunicazione\Posta\Smtp;

$comunicazione = (new Comunicazione($modelli, new CodaInvii($pdo), $registro->per_modulo('pfi/comunicazione'),
        Indirizzo::posta('formazione@example.org', 'Ufficio formazione'), $segreti->valore('comunicazione.sale')))
    ->canale(new Smtp('smtp.example.org', 587, 'starttls', 'formazione@example.org', $segreti->valore('smtp.password')));
// nel cron, ogni minuto:
$comunicazione->elabora(50);
```

# 5. Configurazione

| Costruttore | Parametri e predefiniti |
|---|---|
| `Comunicazione` | `$modelli`, `$coda`, `$registro` (nullo), `$mittente` della posta, `$sale` dei pseudonimi (vuoto), `$destinatariinchiaro` (no) |
| `CodaInvii` | `$pdo`, `$tabella` (`'pfi_invii'`) |
| `Posta\Smtp` | `$host`, `$porta` (587), `$sicurezza` (`starttls`, `tls` o `nessuna` senza autenticazione), `$utente`, `$password`, `$attesa` (30 s), `$helo` (nome del server), `$ca` |
| `Posta\Graph` | connettore, gettone, casella mittente |
| `Posta\Gmail` | connettore, gettone, utente (`me`, cioè l'utente delegato) |
| `Posta\Ses` | connettore, regione, chiave d'accesso, segreto |
| `Canali\WhatsApp` | connettore, gettone, identificativo del numero, versione delle API (`v21.0`, `[DA VERIFICARE]`: indicare quella in uso) |
| `Ricezione\Imap` | host, porta (993), sicurezza (`tls`), utente, password o gettone, cartella (`INBOX`), attesa, `$ca` |
| `Ricezione\Pop3` | host, porta (995), sicurezza, utente, password, `$cancella` (no), `$visto` (funzione per gli UIDL letti), attesa, `$ca` |

Le credenziali vanno lette da `pfi/segreti` al momento della costruzione; i parametri che le ricevono sono marcati `#[\SensitiveParameter]`, così non compaiono nelle tracce degli errori di PHP.

# 6. Errori ed eccezioni

`invia()` lancia `Pfi\Contratti\Eccezione` per errori del chiamante: «Canale non configurato: «…»», modello inesistente, «Messaggio senza destinatari.», «Intestazione aggiuntiva non ammessa.», «Indirizzo di posta non valido.», allegato non valido o non leggibile. Gli errori dei fornitori non escono da `elabora()`: diventano esiti nella coda e eventi nel registro.

| Esito nella coda | Causa tipica |
|---|---|
| «<fornitore>: invio non riuscito (503)» con `in_coda` | errore temporaneo; nuovo tentativo programmato |
| «<fornitore>: invio non riuscito (401)» con `fallito` | credenziali o richiesta rifiutate |
| «Server SMTP … non raggiungibile: …» | rete; temporaneo |
| «Il server SMTP non offre STARTTLS.», «Negoziazione TLS … non riuscita …» | configurazione o certificato; definitivo |
| «Identificativo di chat Telegram non valido.», «Messaggio Telegram senza testo.» | dati del messaggio; definitivo |
| «Contenuto dell'invio non valido.» | riga della coda alterata |

La costruzione di un canale con parametri impossibili lancia subito un errore (per esempio «Token di Telegram non valido.», «Autenticazione SMTP senza TLS non ammessa: usare starttls o tls.», «Tenant Microsoft non valido.»).

# 7. Sicurezza

- SMTP con autenticazione richiede TLS; i certificati si verificano sempre. Le chiamate API ereditano le regole di `pfi/connettori` (nessun reindirizzamento, indirizzi senza parametri nel registro).
- Il contenuto dei messaggi resta nella coda solo finché serve per i tentativi; dopo l'esito finale si cancella. Il destinatario è pseudonimizzato: il sale va tenuto nel deposito dei segreti. La pseudonimizzazione protegge da una lettura casuale del registro, non da chi conosce già l'indirizzo e il sale.
- I dati personali di ciascun destinatario vanno passati con `dati_per`, non nei `$dati` comuni, così un messaggio non contiene dati di altri.
- La ricezione delle caselle tratta posta di persone: nel portale la sua attivazione attende il parere del Responsabile della protezione dei dati.
- I messaggi in arrivo da Telegram e WhatsApp vanno accettati solo dopo `webhook_autentico()`.

# 8. Prove di salute

| Codice | Classe | Che cosa controlla |
|---|---|---|
| `comunicazione.canali` | `Pfi\Comunicazione\Salute\Canali` | estensioni `openssl`, `mbstring`, `curl`; con una `Comunicazione`, la prova di ogni canale: SMTP apre la sessione e autentica, le API chiedono al fornitore di riconoscere le credenziali (dove il fornitore offre una chiamata adatta, altrimenti `avviso`), Telegram chiama `getMe`; nessun canale configurato dà `avviso` |

# 9. Uso nel portale

Nel portale l'adattatore `tool_pficomunicazione` configura canali, sale, conservazione (90 giorni predefiniti) e destinatari in chiaro, offre la consultazione della coda e aggiunge il canale «notifica» delle notifiche di Moodle; i componenti del portale inviano attraverso il contratto `invia()`. Informazione utile solo per confronto.

# 10. Limiti noti

- Le chiamate ai fornitori via API sono provate con il fornitore finto sui formati della documentazione ufficiale; la prova con account reali dipende dagli account di prova `[DA VERIFICARE]`.
- Gli ambiti dei gettoni OAuth per IMAP presso Microsoft e Google vanno verificati al momento della configurazione `[DA VERIFICARE]`.
- Un solo fornitore per tipo di canale in ogni oggetto `Comunicazione`; per due fornitori di posta servono due oggetti.
- SMTP senza estensione SMTPUTF8 non accetta indirizzi con caratteri non ASCII: l'invio fallisce in modo definitivo.
- `elabora()` tratta gli invii in un solo processo: per volumi alti servono più esecuzioni del cron o un limite di frequenza sul connettore.

# 11. Indicazioni per gli agenti

- Dai componenti chiamare solo `Comunicazione::invia()` con una chiave di modello; non comporre messaggi con concatenazioni né chiamare direttamente i canali.
- Per ogni nuovo tipo di messaggio dichiarare lo schema e i modelli predefiniti per tutti i canali che il componente usa.
- Passare la correlazione dell'operazione (per esempio quella del registro) così invii, eventi e risposte ricevute si collegano.
- Non leggere il contenuto dalla coda dopo l'esito finale: non c'è più. Usare `cerca()` per stato ed esito.
- Nelle prove usare il fornitore finto di `pfi/connettori` con `Politica(1)` e controllare `$finto->ricevute`.

# 12. Riferimento dell'interfaccia

Generato dal codice della versione 1.0.0; lo spazio dei nomi è `Pfi\Comunicazione\`.

<!-- riferimento:inizio -->
#### `Allegato`

Classe finale. Allegato di un messaggio: nome del file, tipo MIME e contenuto.

Proprietà pubbliche: `readonly string $nome`, `readonly string $tipo`, `readonly string $contenuto`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $nome, promossa string $tipo, promossa string $contenuto)` |  |
| `static da_file(string $percorso, ?string $nome = null, ?string $tipo = null): self` |  |

#### `Canale`

Interfaccia. Canale di comunicazione (proposta 49 § 3.4): posta (SMTP o API di un fornitore), notifica del portale, Telegram, WhatsApp. L'invio restituisce l'esito o lancia ErroreInvio, temporaneo (si ritenta dalla coda) o definitivo.

| Metodo | Descrizione |
|---|---|
| `tipo(): string` | Nome del tipo di canale: «posta», «notifica», «telegram», «whatsapp». |
| `nome(): string` | Nome della configurazione per il registro, per esempio «posta smtp» o «posta postmark». |
| `invia(Pfi\Comunicazione\Messaggio $messaggio): Pfi\Comunicazione\Invio` |  |
| `salute(): Pfi\Contratti\EsitoSalute` | Prova della configurazione senza inviare messaggi (connessione, autenticazione dove possibile). |

#### `Canali\Telegram`

Classe finale, implementa `Pfi\Comunicazione\Canale`. Telegram Bot API: sendMessage con il testo semplice (al massimo 4096 caratteri) verso l'identificativo di chat del destinatario; getMe per la prova. Ricezione con webhook: il segreto indicato in setWebhook torna nell'intestazione X-Telegram-Bot-Api-Secret-Token. Riferimento: core.telegram.org/bots/api. Il token è nel percorso dell'indirizzo: il connettore lo toglie dal registro.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa Pfi\Connettori\Connettore $connettore, promossa string $token)` |  |
| `tipo(): string` | Nome del tipo di canale: «posta», «notifica», «telegram», «whatsapp». |
| `nome(): string` | Nome della configurazione per il registro, per esempio «posta smtp» o «posta postmark». |
| `invia(Pfi\Comunicazione\Messaggio $m): Pfi\Comunicazione\Invio` |  |
| `salute(): Pfi\Contratti\EsitoSalute` | Prova della configurazione senza inviare messaggi (connessione, autenticazione dove possibile). |
| `static webhook_autentico(?string $intestazione, string $segreto): bool` | Controlla il segreto del webhook in tempo costante. |
| `static ricevuto(string $json): ?array` | Messaggio di testo ricevuto da un aggiornamento del webhook, o null per gli altri tipi di aggiornamento. |

#### `Canali\WhatsApp`

Classe finale, implementa `Pfi\Comunicazione\Canale`. WhatsApp Business Platform, Cloud API: POST /{versione}/{identificativo del numero}/messages. Fuori dalla finestra di 24 ore di una conversazione aperta dall'utente si possono inviare solo modelli approvati: $extra['modello'] (nome), $extra['lingua'] (codice, predefinito «it») e $extra['parametri'] (valori dei segnaposto del corpo, in ordine). Con $extra['testo_libero'] vero si invia il testo semplice (solo dentro la finestra). Webhook con verifica della firma X-Hub-Signature-256 (HMAC-SHA256 con il segreto dell'app). Riferimento: developers.facebook.com/docs/whatsapp/cloud-api [DA VERIFICARE: versione delle API in uso al momento della configurazione; quella predefinita qui è indicativa].

| Metodo | Descrizione |
|---|---|
| `__construct(promossa Pfi\Connettori\Connettore $connettore, promossa string $gettone, promossa string $numeroid, promossa string $versione = 'v21.0')` |  |
| `tipo(): string` | Nome del tipo di canale: «posta», «notifica», «telegram», «whatsapp». |
| `nome(): string` | Nome della configurazione per il registro, per esempio «posta smtp» o «posta postmark». |
| `static numero(string $n): string` | Numero nel formato internazionale senza «+», spazi né trattini. |
| `invia(Pfi\Comunicazione\Messaggio $m): Pfi\Comunicazione\Invio` |  |
| `salute(): Pfi\Contratti\EsitoSalute` | Prova della configurazione senza inviare messaggi (connessione, autenticazione dove possibile). |
| `static webhook_autentico(string $corpo, ?string $intestazione, string $segretoapp): bool` | Firma X-Hub-Signature-256 del corpo del webhook, in tempo costante. |

#### `Coda`

Interfaccia. Coda e registro degli invii: CodaInvii la realizza con PDO; un'applicazione la può realizzare con il proprio accesso al database (nel portale tool_pficomunicazione con le API dei dati di Moodle). Le righe hanno i campi id, correlazione, canale, configurazione, modello, versione, lingua, destinatario, inchiaro, contenuto, stato, tentativi, prossimo, idfornitore, esito, creato, aggiornato.

| Metodo | Descrizione |
|---|---|
| `accoda(array $riga): int` |  |
| `prendi(int $massimo, ?int $adesso = null): array` |  |
| `prendi_uno(int $id): ?array` |  |
| `esito(int $id, string $stato, int $tentativi, int $prossimo, string $idfornitore, string $esito, bool $cancellacontenuto): void` |  |
| `riga(int $id): ?array` |  |
| `cerca(array $filtri = [], int $limite = 100, int $da = 0): array` |  |
| `conserva(int $giorni, ?int $adesso = null): int` |  |

#### `CodaInvii`

Classe finale, implementa `Pfi\Comunicazione\Coda`. Coda e registro degli invii (proposta 49 § 3.4) in una tabella via PDO; nel portale la tabella è dichiarata da tool_pficomunicazione.

Costanti: `STATI = ['in_coda', 'in_invio', 'inviato', 'fallito']`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa PDO $pdo, promossa string $tabella = 'pfi_invii')` |  |
| `crea_tabella(): void` |  |
| `accoda(array $riga): int` |  |
| `prendi(int $massimo, ?int $adesso = null): array` | Prende fino a $massimo invii scaduti e li segna «in_invio»; gli invii rimasti «in_invio» da oltre 15 minuti (processo interrotto) tornano in coda. |
| `prendi_uno(int $id): ?array` | Prende un singolo invio in coda (invio immediato), o null se un altro processo l'ha già preso. |
| `esito(int $id, string $stato, int $tentativi, int $prossimo, string $idfornitore, string $esito, bool $cancellacontenuto): void` |  |
| `riga(int $id): ?array` |  |
| `cerca(array $filtri = [], int $limite = 100, int $da = 0): array` | Ricerca per la pagina del registro degli invii. |
| `conserva(int $giorni, ?int $adesso = null): int` | Elimina le righe con esito finale più vecchie di $giorni; restituisce quante. |

#### `Comunicazione`

Classe finale. Contratto per i componenti (proposta 49 § 3.4): invia($canale, $modello, $destinatari, $dati) rende il modello per ogni destinatario, accoda un invio per destinatario e, se richiesto, lo esegue subito; elabora() esegue la coda con tentativi ripetuti (1, 5, 15, 60, 240 minuti) per gli errori temporanei.

Costanti: `ATTESE = [60, 300, 900, 3600, 14400]`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa Pfi\Modelli\Modelli $modelli, promossa Pfi\Comunicazione\Coda $coda, ?Pfi\Contratti\Registro $registro = null, promossa ?Pfi\Comunicazione\Indirizzo $mittente = null, promossa string $sale = '', promossa bool $destinatariinchiaro = false)` |  |
| `canale(Pfi\Comunicazione\Canale $canale): self` |  |
| `canali(): array` |  |
| `invia(string $canale, string $chiave, array $destinatari, array $dati, array $opzioni = []): array` | Accoda un invio per destinatario e restituisce gli identificativi degli invii. |
| `elabora(int $massimo = 50): int` | Esegue gli invii scaduti della coda; restituisce quanti ne ha trattati. |

#### `ErroreInvio`

Classe finale, estende `Pfi\Contratti\Eccezione`; implementa `Throwable`. Invio non riuscito. Temporaneo: il fornitore non è raggiungibile, limita la frequenza o ha un guasto (si ritenta). Definitivo: destinatario rifiutato, autenticazione o configurazione errate, messaggio non valido (non si ritenta).

Proprietà pubbliche: `readonly bool $temporaneo`, `readonly string $codice`.

| Metodo | Descrizione |
|---|---|
| `__construct(string $messaggio, promossa bool $temporaneo, promossa string $codice = '')` |  |
| `static da_stato_http(string $fornitore, int $stato, string $dettaglio = ''): self` | Classificazione degli stati HTTP delle API: 408, 425, 429 e 5xx temporanei, il resto definitivo. |

#### `Indirizzo`

Classe finale. Destinatario o mittente: indirizzo di posta, identificativo di chat di Telegram, numero di WhatsApp o utente del portale, secondo il canale; il nome è facoltativo.

Proprietà pubbliche: `readonly string $valore`, `readonly string $nome`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $valore, promossa string $nome = '')` |  |
| `static posta(string $email, string $nome = ''): self` | Indirizzo di posta controllato (anche con caratteri non ASCII nella parte locale e nel dominio). |
| `pseudonimo(string $sale = ''): string` | Forma per il registro degli invii: impronta breve, non reversibile senza conoscere l'indirizzo. |
| `dominio(): string` |  |

#### `Invio`

Classe finale. Esito di un invio accettato dal fornitore: identificativo restituito e risposta sintetica senza segreti.

Proprietà pubbliche: `readonly string $idfornitore`, `readonly string $risposta`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $idfornitore = '', promossa string $risposta = '')` |  |

#### `Messaggio`

Classe finale. Messaggio pronto per un canale: le parti vengono di solito da un modello reso (pfi/modelli). Per la posta servono mittente, oggetto e almeno uno fra html e testo; per Telegram e notifica il testo; per WhatsApp il nome del modello approvato e i suoi parametri in $extra.

Proprietà pubbliche: `readonly array $destinatari`, `readonly string $oggetto`, `readonly string $html`, `readonly string $testo`, `readonly ?Pfi\Comunicazione\Indirizzo $mittente`, `readonly array $cc`, `readonly array $ccn`, `readonly array $allegati`, `readonly ?Pfi\Comunicazione\Indirizzo $rispondia`, `readonly array $intestazioni`, `readonly array $extra`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa array $destinatari, promossa string $oggetto = '', promossa string $html = '', promossa string $testo = '', promossa ?Pfi\Comunicazione\Indirizzo $mittente = null, promossa array $cc = [], promossa array $ccn = [], promossa array $allegati = [], promossa ?Pfi\Comunicazione\Indirizzo $rispondia = null, promossa array $intestazioni = [], promossa array $extra = [])` |  |
| `testo_semplice(): string` | Testo semplice: quello dato o, in mancanza, ricavato dall'HTML. |
| `per(Pfi\Comunicazione\Indirizzo ...$destinatari): self` | Stesso messaggio con altri destinatari (per l'invio uno per destinatario). |
| `a_array(): array` | Forma serializzabile per la coda degli invii (allegati in base64). |
| `static da_array(array $d): self` |  |
| `con_mittente(Pfi\Comunicazione\Indirizzo $mittente): self` |  |

#### `Mime`

Classe finale. Composizione di un messaggio di posta secondo RFC 5322 e MIME (RFC 2045-2047, RFC 2231): intestazioni con parole codificate in UTF-8, corpo testo e HTML in quoted-printable dentro multipart/alternative, allegati in base64 dentro multipart/mixed, righe terminate da CRLF. Il risultato serve a SMTP e alle API che accettano il messaggio intero (Microsoft Graph, Gmail API, Amazon SES, Mailgun).

| Metodo | Descrizione |
|---|---|
| `static componi(Pfi\Comunicazione\Messaggio $m, ?DateTimeInterface $data = null, ?string $confine = null): array` |  |
| `static indirizzo(Pfi\Comunicazione\Indirizzo $i): string` |  |
| `static parola(string $s, int $gia): string` | Parola codificata RFC 2047 in base64 UTF-8 solo se serve, divisa in pezzi entro 75 caratteri. |
| `static qp(string $s): string` | Quoted-printable con righe CRLF (il punto a inizio riga lo protegge il client SMTP). |

#### `Posta\Api`

Classe astratta, implementa `Pfi\Comunicazione\Canale`. Base comune della posta con le API dei fornitori (D-161 d): invio attraverso un connettore (tentativi, limiti, registro senza segreti), errori tradotti in ErroreInvio temporanei o definitivi, prova della configurazione con una chiamata in sola lettura dove il fornitore la offre.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa Pfi\Connettori\Connettore $connettore)` |  |
| `tipo(): string` | Nome del tipo di canale: «posta», «notifica», «telegram», «whatsapp». |
| `nome(): string` | Nome della configurazione per il registro, per esempio «posta smtp» o «posta postmark». |
| `invia(Pfi\Comunicazione\Messaggio $m): Pfi\Comunicazione\Invio` |  |
| `salute(): Pfi\Contratti\EsitoSalute` | Prova della configurazione senza inviare messaggi (connessione, autenticazione dove possibile). |

#### `Posta\Brevo`

Classe finale, estende `Pfi\Comunicazione\Posta\Api`; implementa `Pfi\Comunicazione\Canale`. Brevo: POST /v3/smtp/email con sender, to, cc, bcc, subject, htmlContent, textContent, replyTo, headers e attachment (contenuto in base64 con il nome); chiave nell'intestazione «api-key»; risposta 201 con messageId. Riferimento: developers.brevo.com/reference/send-transac-email, verificato il 14 novembre 2026.

| Metodo | Descrizione |
|---|---|
| `__construct(Pfi\Connettori\Connettore $c, promossa string $chiave)` |  |

#### `Posta\Gettone`

Classe finale. Gettone di accesso OAuth 2.0 con le credenziali del client (RFC 6749 § 4.4) o con un'asserzione JWT firmata (RFC 7523, conto di servizio Google), conservato nel processo fino a un minuto prima della scadenza.

| Metodo | Descrizione |
|---|---|
| `static microsoft(Pfi\Connettori\Connettore $c, string $tenant, string $client, string $segreto, string $ambito = 'https://graph.microsoft.com/.default'): self` | Microsoft identity platform, credenziali del client per Microsoft Graph. |
| `static google(Pfi\Connettori\Connettore $c, string $chiavejson, string $utente, string $ambito): self` | Conto di servizio Google con delega a livello di dominio: il JWT firmato RS256 impersona $utente. |
| `valore(): string` |  |

#### `Posta\Gmail`

Classe finale, estende `Pfi\Comunicazione\Posta\Api`; implementa `Pfi\Comunicazione\Canale`. Google Workspace con la Gmail API: POST gmail/v1/users/{utente}/messages/send con il messaggio MIME in base64url nel campo «raw»; conto di servizio con delega a livello di dominio e ambito gmail.send. Riferimento: developers.google.com/workspace/gmail/api/guides/sending, verificato il 14 novembre 2026.

Costanti: `AMBITO = 'https://www.googleapis.com/auth/gmail.send'`.

| Metodo | Descrizione |
|---|---|
| `__construct(Pfi\Connettori\Connettore $c, promossa Pfi\Comunicazione\Posta\Gettone $gettone, promossa string $utente = 'me')` |  |

#### `Posta\Graph`

Classe finale, estende `Pfi\Comunicazione\Posta\Api`; implementa `Pfi\Comunicazione\Canale`. Microsoft 365 con Microsoft Graph: POST /v1.0/users/{casella}/sendMail con il messaggio MIME in base64 (Content-Type text/plain), risposta 202; permesso dell'applicazione Mail.Send, gettone con le credenziali del client. Riferimento: learn.microsoft.com/graph/api/user-sendmail, verificato il 14 novembre 2026. Si consiglia di limitare l'applicazione alla sola casella del portale con i criteri di accesso di Exchange.

| Metodo | Descrizione |
|---|---|
| `__construct(Pfi\Connettori\Connettore $c, promossa Pfi\Comunicazione\Posta\Gettone $gettone, promossa string $casella)` |  |

#### `Posta\Mailgun`

Classe finale, estende `Pfi\Comunicazione\Posta\Api`; implementa `Pfi\Comunicazione\Canale`. Mailgun: POST /v3/{dominio}/messages.mime in multipart/form-data con i destinatari in «to» e il messaggio MIME in «message»; autenticazione Basic con utente «api» e la chiave; sede degli Stati Uniti (api.mailgun.net) o dell'Unione europea (api.eu.mailgun.net). Riferimento: documentation.mailgun.com/docs/mailgun/api-reference/send, verificato il 14 novembre 2026 [DA VERIFICARE: nome del campo «message» con un account di prova].

| Metodo | Descrizione |
|---|---|
| `__construct(Pfi\Connettori\Connettore $c, promossa string $dominio, promossa string $chiave, promossa string $sede = 'eu')` |  |

#### `Posta\Postmark`

Classe finale, estende `Pfi\Comunicazione\Posta\Api`; implementa `Pfi\Comunicazione\Canale`. Postmark: POST /email con From, To, Cc, Bcc, Subject, HtmlBody, TextBody, ReplyTo, Headers, Attachments e MessageStream; gettone del server nell'intestazione X-Postmark-Server-Token; risposta con MessageID ed ErrorCode 0. Riferimento: postmarkapp.com/developer/api/email-api, verificato il 14 novembre 2026.

| Metodo | Descrizione |
|---|---|
| `__construct(Pfi\Connettori\Connettore $c, promossa string $gettone, promossa string $flusso = 'outbound')` |  |

#### `Posta\SendGrid`

Classe finale, estende `Pfi\Comunicazione\Posta\Api`; implementa `Pfi\Comunicazione\Canale`. SendGrid (Twilio): POST /v3/mail/send con personalizations, from, subject, content e attachments; chiave in Authorization Bearer; risposta 202 con l'intestazione X-Message-Id. Riferimento: twilio.com/docs/sendgrid/ api-reference/mail-send, verificato il 14 novembre 2026.

| Metodo | Descrizione |
|---|---|
| `__construct(Pfi\Connettori\Connettore $c, promossa string $chiave, promossa string $base = 'https://api.sendgrid.com')` |  |

#### `Posta\Ses`

Classe finale, estende `Pfi\Comunicazione\Posta\Api`; implementa `Pfi\Comunicazione\Canale`. Amazon SES con l'API v2: POST /v2/email/outbound-emails con Content.Raw.Data (messaggio MIME in base64), firma AWS Signature Version 4 per il servizio «ses». Riferimento: docs.aws.amazon.com/ses/latest/APIReference-V2/ API_SendEmail.html, verificato il 14 novembre 2026.

| Metodo | Descrizione |
|---|---|
| `__construct(Pfi\Connettori\Connettore $c, promossa string $regione, promossa string $accesso, promossa string $segreto, promossa ?Closure $orologio = null)` |  |
| `firmata(string $metodo, string $percorso, string $corpo): Pfi\Connettori\Richiesta` | Richiesta firmata con AWS Signature Version 4 (intestazioni host, x-amz-date, x-amz-content-sha256). |
| `static chiave_firma(string $segreto, string $giorno, string $regione, string $servizio): string` | Chiave di firma di Signature Version 4: HMAC a catena di data, regione, servizio e «aws4_request». |

#### `Posta\Smtp`

Classe finale, implementa `Pfi\Comunicazione\Canale`. Posta in uscita con SMTP (RFC 5321) con server, porta, sicurezza, account e password (proposta 49 § 3.4).

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $host, promossa int $porta = 587, promossa string $sicurezza = 'starttls', promossa string $utente = '', promossa string $password = '', promossa float $attesa = 30.0, promossa ?string $helo = null, promossa ?string $ca = null)` |  |
| `tipo(): string` | Nome del tipo di canale: «posta», «notifica», «telegram», «whatsapp». |
| `nome(): string` | Nome della configurazione per il registro, per esempio «posta smtp» o «posta postmark». |
| `invia(Pfi\Comunicazione\Messaggio $m): Pfi\Comunicazione\Invio` |  |
| `salute(): Pfi\Contratti\EsitoSalute` | Prova della configurazione senza inviare messaggi (connessione, autenticazione dove possibile). |

#### `Ricezione\Casella`

Interfaccia. Casella di posta di servizio in ricezione (proposta 49 § 3.4): risposte alle comunicazioni, rimbalzi, richieste.

| Metodo | Descrizione |
|---|---|
| `ricevi(int $massimo = 50, bool $segna = true): array` | Messaggi non ancora trattati, al massimo $massimo; con $segna vero li segna come trattati (letti in IMAP, eliminati in POP3 solo se la configurazione lo prevede). |
| `salute(): Pfi\Contratti\EsitoSalute` |  |

#### `Ricezione\Collegamento`

Tratto. Connessione TCP con TLS dall'inizio o dopo il comando di passaggio a TLS, con verifica del certificato.

#### `Ricezione\Imap`

Classe finale, implementa `Pfi\Comunicazione\Ricezione\Casella`. Casella IMAP4rev1 (RFC 3501): TLS dall'inizio (porta 993) o STARTTLS (143); accesso con account e password (LOGIN) oppure con gettone OAuth 2.0 (AUTHENTICATE XOAUTH2), che serve per Microsoft 365 e Google Workspace, dove l'accesso con password è disattivato [DA VERIFICARE: ambiti dei gettoni per IMAP presso i due fornitori]. Legge i messaggi non letti della cartella con UID SEARCH UNSEEN e BODY.PEEK[], poi li segna letti.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $host, promossa int $porta = 993, promossa string $sicurezza = 'tls', promossa string $utente = '', promossa string $password = '', promossa ?Pfi\Comunicazione\Posta\Gettone $gettone = null, promossa string $cartella = 'INBOX', promossa float $attesa = 30.0, promossa ?string $ca = null)` |  |
| `ricevi(int $massimo = 50, bool $segna = true): array` | Messaggi non ancora trattati, al massimo $massimo; con $segna vero li segna come trattati (letti in IMAP, eliminati in POP3 solo se la configurazione lo prevede). |
| `salute(): Pfi\Contratti\EsitoSalute` |  |

#### `Ricezione\Pop3`

Classe finale, implementa `Pfi\Comunicazione\Ricezione\Casella`. Casella POP3 (RFC 1939): TLS dall'inizio (porta 995) o STLS (110, RFC 2595); accesso con USER e PASS. POP3 non conserva lo stato di lettura: i messaggi già trattati si riconoscono dal loro UIDL con $visti (insieme degli UIDL già letti, conservato dall'applicazione) oppure si eliminano dal server con $cancella.

Proprietà pubbliche: `array $letti`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $host, promossa int $porta = 995, promossa string $sicurezza = 'tls', promossa string $utente = '', promossa string $password = '', promossa bool $cancella = false, promossa ?Closure $visto = null, promossa float $attesa = 30.0, promossa ?string $ca = null)` |  |
| `ricevi(int $massimo = 50, bool $segna = true): array` | Messaggi non ancora trattati, al massimo $massimo; con $segna vero li segna come trattati (letti in IMAP, eliminati in POP3 solo se la configurazione lo prevede). |
| `salute(): Pfi\Contratti\EsitoSalute` |  |

#### `Ricezione\Ricevuto`

Classe finale. Messaggio ricevuto in una casella di servizio: intestazioni principali decodificate, testo semplice, eventuale rapporto di mancata consegna (RFC 3464) con destinatario e stato.

Proprietà pubbliche: `readonly string $id`, `readonly string $mittente`, `readonly string $oggetto`, `readonly string $data`, `readonly string $messageid`, `readonly string $inrisposta`, `readonly string $testo`, `readonly ?array $rimbalzo`, `readonly int $dimensione`, `readonly string $correlazione`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $id, promossa string $mittente, promossa string $oggetto, promossa string $data, promossa string $messageid, promossa string $inrisposta, promossa string $testo, promossa ?array $rimbalzo, promossa int $dimensione, promossa string $correlazione = '')` |  |
| `static da_mime(string $id, string $grezzo): self` | Analizza un messaggio RFC 5322 completo. |

#### `Salute\Canali`

Classe finale, implementa `Pfi\Contratti\ProvaDiSalute`. Prova di salute dei canali configurati (connessione e credenziali, senza inviare). Senza configurazione (costruzione dal manifesto) controlla solo le estensioni di PHP richieste.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa ?Pfi\Comunicazione\Comunicazione $comunicazione = null)` |  |
| `codice(): string` | Codice stabile della prova, per esempio «registro.scrittura». |
| `descrizione(): string` | Descrizione in italiano di che cosa controlla. |
| `esegui(): Pfi\Contratti\EsitoSalute` |  |

<!-- riferimento:fine -->

# Registro delle versioni del manuale

| Versione | Data | Modifiche |
|---|---|---|
| 1.0.0 | 16 novembre 2026 | prima versione, per pfi/comunicazione 1.0.0 |
