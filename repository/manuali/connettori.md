---
titolo: "Manuale di pfi/connettori"
sottotitolo: "Moduli universali del Portale di autoformazione IT: chiamate HTTP verso servizi esterni con tentativi, limiti e registro"
versione: "1.0.0"
data: "2026-11-16"
verifica_fonti: "2026-11-16"
prossima_revisione: "a ogni nuova versione del modulo"
rilascio: "pfi/connettori 1.0.0"
autori: "Gaetano Giarrusso e Ufficio USIS"
hash: "n.d. (calcolato nel MANIFEST)"
classificazione: "USO INTERNO"
---

# Manuale di pfi/connettori

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale. Copyright 2026 Gaetano Giarrusso, licenza GPL v3 o successiva.

Il manuale è scritto per uno sviluppatore che riusa il modulo in un'applicazione PHP diversa dal portale e per un agente di intelligenza artificiale che deve scrivere codice che lo usa. Il riferimento dell'interfaccia (capitolo 12) è estratto dal codice; gli esempi sono eseguiti in un progetto pulito e l'uscita riportata è quella ottenuta. Distribuzione, versioni e regole comuni sono nella guida generale (`src/moduli/MANUALE-GENERALE.md`).

# 1. A che cosa serve

`pfi/connettori` è il modo comune per chiamare un servizio esterno via HTTP o HTTPS (un fornitore di posta, un servizio di messaggistica, un'API dell'ente). Un **connettore** riunisce un nome, un **trasporto** (cURL reale o fornitore finto per le prove), una **politica** dei tentativi e, se serve, un **limite** di frequenza. Ogni chiamata è registrata con metodo, indirizzo senza parametri, stato, durata e numero di tentativi; mai intestazioni, corpi o credenziali.

Il connettore ritenta solo quando è sicuro farlo, rispetta `Retry-After`, non segue i reindirizzamenti (così le credenziali non raggiungono un altro host), verifica sempre il certificato del server e limita la dimensione delle risposte.

Usarlo per ogni integrazione HTTP dell'applicazione. Non è un client HTTP generico per scaricare pagine né un sistema di code: le attese fra i tentativi tengono occupato il processo che chiama.

# 2. Installazione

Requisiti: PHP 8.2 o successivo con l'estensione `curl` (per il trasporto reale); `pfi/contratti` ^1.0.

```bash
composer require pfi/connettori:^1.0
```

Se il server esce su Internet attraverso un proxy, il suo indirizzo si passa al trasporto (capitolo 5); il modulo non legge le variabili d'ambiente `HTTPS_PROXY`.

# 3. Concetti

**Richiesta.** `new Richiesta($metodo, $url, $intestazioni, $corpo, $attesa)`: metodi `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`; solo indirizzi `http` e `https` con host. `Richiesta::json()` codifica un array in JSON e aggiunge `Content-Type` e `Accept`; `Richiesta::modulo()` codifica i campi come modulo web. `con_intestazione()` restituisce una copia con un'intestazione in più (per esempio l'autorizzazione letta dal deposito). `$attesa` è il tempo massimo di quella richiesta in secondi.

**Risposta.** Stato, intestazioni con il nome in minuscolo, corpo, durata e numero di tentativi. `riuscita()` è vero per gli stati 2xx; `json()` decodifica il corpo (`ErroreConnettore` se non è JSON); `intestazione('retry-after')` legge un'intestazione.

**Invio.** `Connettore::invia($richiesta, $accettati)` restituisce la risposta riuscita, oppure una con uno stato elencato in `$accettati` (per esempio `[404]` quando «non trovato» è un esito normale); in tutti gli altri casi lancia `ErroreConnettore`.

**Politica dei tentativi.** `new Politica($tentativi = 3, $attesainiziale = 0.5, $attesamassima = 30.0, $ritenta500 = false)`. Si ritenta:

| Situazione | Ritenta? |
|---|---|
| errore di rete prima della risposta | sì |
| 429 e 503 (il servizio non ha eseguito) | sì, per qualunque metodo |
| 502 e 504 | sì per GET, PUT, DELETE, HEAD; per POST e PATCH solo con l'intestazione `Idempotency-Key` |
| 500 | solo con `$ritenta500 = true`, con la stessa regola di 502 e 504 |
| altri stati (400, 401, 403, 404, 409, 422…) | no |

L'attesa prima del tentativo `n` è `attesainiziale × 2^(n−2)` moltiplicata per un fattore casuale fra 0,5 e 1, al massimo `attesamassima`; se la risposta contiene `Retry-After` (secondi o data) vale quella, sempre entro il massimo.

**Limite di frequenza.** `new Limite($richieste, $secondi, $file)`: al massimo `$richieste` ogni `$secondi`, con il metodo del secchiello di gettoni; prima di una richiesta oltre il limite il connettore attende. Senza `$file` il limite vale nel solo processo; con `$file` è condiviso da tutti i processi PHP del server attraverso un file con blocco esclusivo.

**Trasporti.** `Trasporti\Curl` per la rete; `Trasporti\Finto` per le prove: le risposte (oggetti `Risposta`, `ErroreConnettore` o funzioni che calcolano la risposta dalla richiesta) si mettono in coda con `accoda()`, oppure si calcolano tutte con la funzione passata al costruttore; le richieste ricevute restano in `$ricevute`. Un'applicazione può scrivere un proprio trasporto implementando `Pfi\Connettori\Trasporto`.

# 4. Uso passo per passo

L'esempio usa il fornitore finto e sostituisce l'attesa reale con una funzione che la stampa, così mostra in pochi millisecondi: un 503 con `Retry-After` seguito da un successo, un POST con 502 non ritentato e lo stesso POST ritentato grazie a `Idempotency-Key`, un 404 accettato, un errore di rete persistente, il limite di frequenza. Le righe «[registro …]» sono quelle che il connettore scrive: il parametro `token` dell'indirizzo non compare.

<!-- esempio:uso.php:inizio -->
```php
<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/connettori fuori da Moodle, con il fornitore finto al posto della rete: tentativi, stati accettati,
// errori, limite di frequenza e righe di registro senza segreti.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Connettori\Connettore;
use Pfi\Connettori\ErroreConnettore;
use Pfi\Connettori\Limite;
use Pfi\Connettori\Politica;
use Pfi\Connettori\Richiesta;
use Pfi\Connettori\Risposta;
use Pfi\Connettori\Trasporti\Finto;
use Pfi\Contratti\Registro;

// Registro minimo che stampa gli eventi (in un'applicazione si usa pfi/registro).
$registro = new class implements Registro {
    public function registra(string $livello, string $messaggio, array $contesto = []): void {
        $testo = preg_replace_callback('/\{(\w+)\}/', fn($m) => (string) ($contesto[$m[1]] ?? $m[0]), $messaggio);
        echo "  [registro {$livello}] ", preg_replace('/in \d+ ms/', 'in N ms', $testo), "\n";
    }
};
// Al posto di usleep(): l'esempio annota le attese invece di aspettare davvero. Le attese fra i tentativi hanno una
// variazione casuale, quindi sotto il secondo si stampa solo «meno di un secondo».
$dormi = function(float $s): void {
    if ($s > 0) {
        echo '  (attesa di ', $s >= 1 ? sprintf('%.1f s', $s) : 'meno di un secondo', ")\n";
    }
};

$finto = new Finto();
$api = new Connettore('anagrafe', $finto, new Politica(3, 0.5, 30.0), null, $registro, $dormi);

echo "1. Servizio occupato (503 con Retry-After: 2), poi risposta riuscita\n";
$finto->accoda(new Risposta(503, ['retry-after' => '2']), Finto::json(200, ['codice' => 'A-17', 'stato' => 'attivo']));
$r = $api->invia(new Richiesta('GET', 'https://api.example.org/v1/utenti/A-17?token=abc123'));
echo "  stato {$r->stato} al tentativo {$r->tentativi}: ", $r->json()['stato'], "\n";

echo "2. POST senza Idempotency-Key con 502: non si ritenta (il servizio potrebbe averla eseguita)\n";
$finto->accoda(new Risposta(502, [], 'Bad gateway'));
try {
    $api->invia(Richiesta::json('POST', 'https://api.example.org/v1/pratiche', ['oggetto' => 'prova']));
} catch (ErroreConnettore $e) {
    echo "  ErroreConnettore: {$e->getMessage()} (stato {$e->stato}, corpo disponibile: {$e->risposta->corpo})\n";
}

echo "3. Lo stesso POST con Idempotency-Key: si ritenta\n";
$finto->accoda(new Risposta(502), Finto::json(201, ['id' => 99]));
$r = $api->invia(Richiesta::json('POST', 'https://api.example.org/v1/pratiche', ['oggetto' => 'prova'],
    ['Idempotency-Key' => 'pratica-2026-0001']));
echo "  stato {$r->stato}, id ", $r->json()['id'], "\n";

echo "4. 404 accettato come risposta (non trovato non è un errore per il chiamante)\n";
$finto->accoda(new Risposta(404));
$r = $api->invia(new Richiesta('GET', 'https://api.example.org/v1/utenti/X-1'), [404]);
echo "  stato {$r->stato}\n";

echo "5. Errore di rete a ogni tentativo\n";
$finto->accoda(...array_fill(0, 3, new ErroreConnettore('errore di rete 7: connessione rifiutata', 0)));
try {
    $api->invia(new Richiesta('GET', 'https://api.example.org/v1/stato'));
} catch (ErroreConnettore $e) {
    echo "  ErroreConnettore: {$e->getMessage()} (di rete: ", $e->di_rete() ? 'sì' : 'no', ")\n";
}

echo "6. Limite di 2 richieste al secondo con un orologio fermo: la terza richiesta deve attendere\n";
$limitato = new Connettore('anagrafe-limitata', new Finto(fn() => new Risposta(204)), new Politica(1),
    new Limite(2, 1.0, null, fn() => 1000.0), null, $dormi);
for ($i = 1; $i <= 3; $i++) {
    $stato = $limitato->invia(new Richiesta('GET', 'https://api.example.org/v1/ping'))->stato;
    echo "  richiesta {$i}: stato {$stato}\n";
}
echo "Richieste ricevute dal fornitore finto nei casi 1-5: ", count($finto->ricevute), "\n";
```

Uscita (verificata eseguendo l'esempio in un progetto pulito):

```text
1. Servizio occupato (503 con Retry-After: 2), poi risposta riuscita
  (attesa di 2.0 s)
  [registro info] anagrafe: GET https://api.example.org/v1/utenti/A-17?… → 200 in N ms (2 tentativi)
  stato 200 al tentativo 2: attivo
2. POST senza Idempotency-Key con 502: non si ritenta (il servizio potrebbe averla eseguita)
  [registro error] anagrafe: POST https://api.example.org/v1/pratiche → 502 in N ms (1 tentativi)
  ErroreConnettore: anagrafe: il servizio ha risposto 502. (stato 502, corpo disponibile: Bad gateway)
3. Lo stesso POST con Idempotency-Key: si ritenta
  (attesa di meno di un secondo)
  [registro info] anagrafe: POST https://api.example.org/v1/pratiche → 201 in N ms (2 tentativi)
  stato 201, id 99
4. 404 accettato come risposta (non trovato non è un errore per il chiamante)
  [registro info] anagrafe: GET https://api.example.org/v1/utenti/X-1 → 404 in N ms (1 tentativi)
  stato 404
5. Errore di rete a ogni tentativo
  (attesa di meno di un secondo)
  (attesa di meno di un secondo)
  [registro error] anagrafe: GET https://api.example.org/v1/stato → 0 in N ms (3 tentativi)
  ErroreConnettore: anagrafe: servizio non raggiungibile (errore di rete 7: connessione rifiutata). (di rete: sì)
6. Limite di 2 richieste al secondo con un orologio fermo: la terza richiesta deve attendere
  richiesta 1: stato 204
  richiesta 2: stato 204
  (attesa di meno di un secondo)
  richiesta 3: stato 204
Richieste ricevute dal fornitore finto nei casi 1-5: 9
```
<!-- esempio:uso.php:fine -->

Uso tipico con la rete reale, il proxy dell'ente e il token letto da `pfi/segreti`:

```php
use Pfi\Connettori\{Connettore, Politica, Limite, Richiesta};
use Pfi\Connettori\Trasporti\Curl;

$posta = new Connettore('postmark',
    new Curl(30, 10, ['indirizzo' => 'http://proxy.example.org:3128', 'senzaproxy' => 'localhost,.example.org']),
    new Politica(3, 0.5, 30), new Limite(10, 1.0, '/var/tmp/limite-postmark'), $registro->per_modulo('pfi/connettori'));
$r = $posta->invia(Richiesta::json('POST', 'https://api.postmarkapp.com/email', $messaggio,
    ['X-Postmark-Server-Token' => $segreti->valore('postmark.token'), 'Idempotency-Key' => $idmessaggio]));
```

# 5. Configurazione

| Costruttore | Parametri e predefiniti |
|---|---|
| `Connettore` | `$nome` (compare nel registro e negli errori), `$trasporto`, `$politica` (`new Politica()`), `$limite` (nessuno), `$registro` (nullo), `$dormi` (funzione di attesa; `usleep`) |
| `Politica` | `$tentativi` da 1 a 10 (3), `$attesainiziale` in secondi (0,5), `$attesamassima` (30), `$ritenta500` (no) |
| `Limite` | `$richieste` (almeno 1), `$secondi` (più di 0), `$file` (nessuno), `$orologio` (ora corrente; da fissare solo nelle prove) |
| `Trasporti\Curl` | `$attesa` totale in secondi (30), `$connessione` in secondi (10), `$proxy` (`indirizzo`, `utente`, `password`, `senzaproxy` come elenco di host separati da virgole), `$ca` (file delle autorità di certificazione; quello del sistema), `$massimo` byte della risposta (20 MiB), `$agente` (`PFI-connettori/1.0`) |
| `Trasporti\Finto` | `$sempre` (funzione che calcola la risposta quando la coda è vuota) |

Nelle pagine web usare pochi tentativi e attese brevi; nei processi del cron tentativi e attese più lunghi.

# 6. Errori ed eccezioni

`Pfi\Connettori\ErroreConnettore` (estende `Pfi\Contratti\Eccezione`) ha `stato` (0 per errore di rete), `risposta` (l'ultima risposta, se c'è) e `di_rete()`. Il messaggio non contiene mai il corpo della risposta, che può riportare dati personali; il corpo si legge da `$e->risposta->corpo`.

| Situazione | Classe | Messaggio |
|---|---|---|
| nessuna risposta dopo i tentativi | `ErroreConnettore` | «<nome>: servizio non raggiungibile (<errore>).» |
| risposta non riuscita e non ritentabile, o tentativi esauriti | `ErroreConnettore` | «<nome>: il servizio ha risposto <stato>.» |
| risposta oltre la dimensione massima | `ErroreConnettore` | «Risposta del servizio oltre la dimensione massima ammessa.» |
| `json()` su un corpo non JSON | `ErroreConnettore` | «La risposta del servizio non è JSON valido.» |
| metodo non ammesso, indirizzo non http/https | `Eccezione` | «Metodo HTTP non ammesso: «…»», «Indirizzo della richiesta non valido.» |
| politica o limite non validi | `Eccezione` | «Politica dei tentativi non valida.», «Limite di frequenza non valido.» |
| estensione cURL assente | `Eccezione` | «Estensione cURL di PHP non disponibile.» |

# 7. Sicurezza

- Il certificato del server si verifica sempre; non esiste un'opzione per disattivarlo. Per un servizio interno con un'autorità propria si passa il file `$ca`.
- I reindirizzamenti non si seguono: il chiamante riceve lo stato 3xx e decide, e le credenziali non raggiungono un host diverso da quello configurato.
- Nel registro l'indirizzo perde credenziali e parametri (`?…`), e i tratti del percorso lunghi almeno 24 caratteri che sembrano chiavi (come il token di un bot Telegram nel percorso) diventano «…».
- Le credenziali vanno nelle intestazioni, lette dal deposito (`pfi/segreti`) al momento della chiamata; non vanno nel codice né nei file di configurazione in chiaro.
- La credenziale del proxy, se presente, si passa al trasporto e non compare nei registri del connettore.

# 8. Prove di salute

| Codice | Classe | Che cosa controlla |
|---|---|---|
| `connettori.raggiungibili` | `Pfi\Connettori\Salute\Raggiungibili` | senza servizi: solo la presenza di cURL; con servizi (`['nome' => [$connettore, $indirizzo]]`): una richiesta `HEAD` con 5 secondi di tempo massimo a ciascuno; qualunque stato HTTP vale come raggiungibile (molti servizi rispondono 401 senza credenziali); tutti irraggiungibili `guasto`, alcuni `avviso` |

# 9. Uso nel portale

Nel portale i connettori sono usati da `pfi/comunicazione` per i fornitori di posta via API e per i canali di messaggistica, con il proxy dell'ente e i limiti di frequenza dei fornitori; i fornitori senza account di prova si provano con il trasporto finto. Informazione utile solo per confronto.

# 10. Limiti noti

- Le attese fra i tentativi bloccano il processo che chiama.
- Il limite condiviso su file conta le richieste di un solo server; con più server ciascuno ha il proprio limite.
- Non ci sono flussi in streaming: il corpo della risposta si legge tutto in memoria, entro `$massimo`.
- I metodi `OPTIONS`, `TRACE` e `CONNECT` non sono ammessi.

# 11. Indicazioni per gli agenti

- Per ogni servizio esterno creare un `Connettore` con un nome riconoscibile e passare il registro; non chiamare `curl_*` direttamente.
- Per i POST che creano qualcosa aggiungere `Idempotency-Key` se il servizio la supporta, altrimenti accettare che un 502 non venga ritentato.
- Gestire `ErroreConnettore` distinguendo `di_rete()` (riprovare più tardi) dagli stati 4xx (errore della richiesta o delle credenziali).
- Nelle prove automatiche usare `Trasporti\Finto` e una funzione `$dormi` che non attende; controllare `$finto->ricevute` per verificare le richieste inviate.
- Non scrivere nel registro il corpo di una risposta d'errore senza averlo esaminato: può contenere dati personali.

# 12. Riferimento dell'interfaccia

Generato dal codice della versione 1.0.0; lo spazio dei nomi è `Pfi\Connettori\`.

<!-- riferimento:inizio -->
#### `Connettore`

Classe finale. Connettore verso un servizio esterno (release 9.0, blocco M2a; proposta 49 § 3.10): un nome per il registro, il trasporto, la politica dei tentativi, il limite di frequenza facoltativo. Ogni richiesta è registrata con metodo, indirizzo senza parametri, esito, durata e tentativi; mai intestazioni né corpi.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $nome, promossa Pfi\Connettori\Trasporto $trasporto, promossa Pfi\Connettori\Politica $politica = new Politica(), promossa ?Pfi\Connettori\Limite $limite = null, ?Pfi\Contratti\Registro $registro = null, ?Closure $dormi = null)` |  |
| `nome(): string` |  |
| `invia(Pfi\Connettori\Richiesta $richiesta, array $accettati = []): Pfi\Connettori\Risposta` | Invia la richiesta con i tentativi della politica. Restituisce la risposta riuscita (2xx); con $accettati si restituiscono anche altri stati (per esempio 404 per «non trovato»). Altrimenti ErroreConnettore. |

#### `ErroreConnettore`

Classe finale, estende `Pfi\Contratti\Eccezione`; implementa `Throwable`. Errore di un connettore: di rete (stato 0) o risposta non riuscita dopo i tentativi. Il messaggio non contiene il corpo della risposta, che può riportare dati personali o segreti; il corpo resta disponibile al chiamante in $risposta.

Proprietà pubbliche: `readonly int $stato`, `readonly ?Pfi\Connettori\Risposta $risposta`.

| Metodo | Descrizione |
|---|---|
| `__construct(string $messaggio, promossa int $stato = 0, promossa ?Pfi\Connettori\Risposta $risposta = null)` |  |
| `di_rete(): bool` |  |

#### `Limite`

Classe finale. Limite di frequenza a secchiello (token bucket) per connettore: al massimo $richieste nell'arco di $secondi, con l'attesa necessaria prima di una richiesta oltre il limite. Lo stato è nel processo; con $file lo stato è condiviso fra i processi del server attraverso un file con blocco esclusivo.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa int $richieste, promossa float $secondi, promossa ?string $file = null, promossa ?Closure $orologio = null)` |  |
| `prendi(): float` | Prende un gettone; restituisce i secondi da attendere prima di inviare (0 se si invia subito). |

#### `Politica`

Classe finale. Tentativi e attese (proposta 49 § 3.10). Si ritenta solo quando è sicuro: errore di rete prima della risposta, oppure 429, 502, 503, 504 (e 500 se ritenta500). Per POST e PATCH, che non sono idempotenti, si ritenta un errore HTTP solo se la richiesta lo dichiara con l'intestazione Idempotency-Key, oppure se il servizio risponde 429 o 503 (non ha eseguito). L'attesa cresce in modo esponenziale con una variazione casuale e rispetta Retry-After fino al massimo.

Proprietà pubbliche: `readonly int $tentativi`, `readonly float $attesainiziale`, `readonly float $attesamassima`, `readonly bool $ritenta500`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa int $tentativi = 3, promossa float $attesainiziale = 0.5, promossa float $attesamassima = 30.0, promossa bool $ritenta500 = false)` |  |
| `ritentabile(Pfi\Connettori\Richiesta $richiesta, ?Pfi\Connettori\Risposta $risposta): bool` |  |
| `attesa(int $n, ?Pfi\Connettori\Risposta $risposta, ?Closure $caso = null): float` | Attesa in secondi prima del tentativo numero $n (dal secondo in poi). |

#### `Richiesta`

Classe finale. Richiesta HTTP verso un servizio esterno. Il corpo è già codificato; json() lo prepara da un array.

Proprietà pubbliche: `readonly string $metodo`, `readonly string $url`, `readonly array $intestazioni`, `readonly ?string $corpo`, `readonly ?float $attesa`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $metodo, promossa string $url, promossa array $intestazioni = [], promossa ?string $corpo = null, promossa ?float $attesa = null)` |  |
| `static json(string $metodo, string $url, array $dati, array $intestazioni = []): self` |  |
| `static modulo(string $metodo, string $url, array $campi, array $intestazioni = []): self` |  |
| `con_intestazione(string $nome, string $valore): self` | Stessa richiesta con un'intestazione in più o sostituita. |
| `url_per_registro(): string` | Indirizzo per il registro: schema, host e percorso, senza credenziali né parametri; i tratti del percorso che sembrano chiavi (almeno 24 caratteri di lettere, cifre e «:_-», come il token di Telegram) diventano «…». |

#### `Risposta`

Classe finale. Risposta HTTP. Le intestazioni hanno i nomi in minuscolo.

Proprietà pubbliche: `readonly int $stato`, `readonly array $intestazioni`, `readonly string $corpo`, `readonly float $durata`, `readonly int $tentativi`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa int $stato, promossa array $intestazioni = [], promossa string $corpo = '', promossa float $durata = 0.0, promossa int $tentativi = 1)` |  |
| `riuscita(): bool` |  |
| `intestazione(string $nome): ?string` |  |
| `json(): array` | Corpo JSON decodificato; ErroreConnettore se non è JSON. |
| `con_tentativi(int $n): self` |  |

#### `Salute\Raggiungibili`

Classe finale, implementa `Pfi\Contratti\ProvaDiSalute`. Prova di salute: i servizi configurati rispondono (qualunque stato HTTP vale come raggiungibile, perché senza credenziali molti servizi rispondono 401). Senza connettori (costruzione dal manifesto) controlla solo cURL.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa array $servizi = [])` |  |
| `codice(): string` | Codice stabile della prova, per esempio «registro.scrittura». |
| `descrizione(): string` | Descrizione in italiano di che cosa controlla. |
| `esegui(): Pfi\Contratti\EsitoSalute` |  |

#### `Trasporti\Curl`

Classe finale, implementa `Pfi\Connettori\Trasporto`. Trasporto con l'estensione cURL di PHP. Verifica sempre il certificato del server; proxy dell'ente facoltativo (con le eccezioni di $senzaproxy); nessun reindirizzamento seguito, perché un reindirizzamento può portare le credenziali verso un altro host; corpo della risposta limitato a $massimo byte.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa float $attesa = 30.0, promossa float $connessione = 10.0, promossa array $proxy = [], promossa ?string $ca = null, promossa int $massimo = 20971520, promossa string $agente = 'PFI-connettori/1.0')` |  |
| `invia(Pfi\Connettori\Richiesta $richiesta): Pfi\Connettori\Risposta` |  |

#### `Trasporti\Finto`

Classe finale, implementa `Pfi\Connettori\Trasporto`. Fornitore finto per le prove (proposta 49 § 3.10; D-161 d: senza account di prova i fornitori di posta via API si provano con questo trasporto). Le risposte si accodano o si calcolano con una funzione; le richieste ricevute restano in $ricevute per i controlli.

Proprietà pubbliche: `array $ricevute`.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa ?Closure $sempre = null)` |  |
| `accoda(Pfi\Connettori\Risposta\|Pfi\Connettori\ErroreConnettore\|Closure ...$risposte): self` |  |
| `static json(int $stato, array $dati, array $intestazioni = []): Pfi\Connettori\Risposta` |  |
| `invia(Pfi\Connettori\Richiesta $richiesta): Pfi\Connettori\Risposta` |  |

#### `Trasporto`

Interfaccia. Trasporto delle richieste: cURL in esercizio, Finto nelle prove. ErroreConnettore con stato 0 per gli errori di rete.

| Metodo | Descrizione |
|---|---|
| `invia(Pfi\Connettori\Richiesta $richiesta): Pfi\Connettori\Risposta` |  |

<!-- riferimento:fine -->

# Registro delle versioni del manuale

| Versione | Data | Modifiche |
|---|---|---|
| 1.0.0 | 16 novembre 2026 | prima versione, per pfi/connettori 1.0.0 |
