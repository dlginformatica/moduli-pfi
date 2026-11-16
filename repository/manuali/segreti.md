---
titolo: "Manuale di pfi/segreti"
sottotitolo: "Moduli universali del Portale di autoformazione IT: deposito cifrato di credenziali, chiavi e token"
versione: "1.0.0"
data: "2026-11-16"
verifica_fonti: "2026-11-16"
prossima_revisione: "a ogni nuova versione del modulo"
rilascio: "pfi/segreti 1.0.0"
autori: "Gaetano Giarrusso e Ufficio USIS"
hash: "n.d. (calcolato nel MANIFEST)"
classificazione: "USO INTERNO"
---

# Manuale di pfi/segreti

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale. Copyright 2026 Gaetano Giarrusso, licenza GPL v3 o successiva.

Il manuale è scritto per uno sviluppatore che riusa il modulo in un'applicazione PHP diversa dal portale e per un agente di intelligenza artificiale che deve scrivere codice che lo usa. Il riferimento dell'interfaccia (capitolo 12) è estratto dal codice; gli esempi sono eseguiti in un progetto pulito e l'uscita riportata è quella ottenuta. Distribuzione, versioni e regole comuni sono nella guida generale (`src/moduli/MANUALE-GENERALE.md`).

# 1. A che cosa serve

`pfi/segreti` conserva password, token e chiavi di un'applicazione in un **deposito cifrato su disco**, invece che in chiaro nei file di configurazione o nel database. Ogni segreto è una voce con un nome scelto dall'applicazione; il valore è cifrato con una chiave tenuta in un file separato. Per riconoscere un segreto senza mostrarlo (nei pannelli, nei registri, nei confronti) il modulo calcola un'**impronta** breve e non reversibile.

Implementa l'interfaccia `Pfi\Contratti\Segreti`, quindi ogni modulo che chiede un oggetto `Segreti` (per esempio `pfi/comunicazione` per le credenziali dei fornitori) può ricevere il deposito.

Usarlo quando l'applicazione deve conservare credenziali di servizi esterni sul server. Non usarlo per le password degli utenti (vanno salvate con `password_hash()`), né come archivio di dati personali, né per condividere segreti fra server diversi senza copiare anche la chiave.

# 2. Installazione

Requisiti: PHP 8.2 o successivo con l'estensione `sodium` (inclusa in PHP dalla 7.2, va solo abilitata se il pacchetto della distribuzione la separa); `pfi/contratti` ^1.0.

```bash
composer require pfi/segreti:^1.0
```

Preparazione sul server, una volta sola (utente del servizio `www-data` come esempio):

```bash
sudo install -d -o www-data -g www-data -m 0700 /var/lib/applicazione/segreti
sudo install -d -o root -g www-data -m 0750 /etc/applicazione
openssl rand -hex 32 | sudo tee /etc/applicazione/chiave-segreti >/dev/null
sudo chown root:www-data /etc/applicazione/chiave-segreti && sudo chmod 0640 /etc/applicazione/chiave-segreti
```

La chiave deve avere almeno 32 caratteri, stare fuori dalla cartella dei dati e dal database, ed essere copiata nel backup **separatamente** dalle voci: chi ha entrambe legge i segreti, chi perde la chiave li perde.

# 3. Concetti

**Chiave del deposito.** Un file di testo con almeno 32 caratteri; la chiave di cifratura vera è il BLAKE2b a 32 byte del testo (spazi iniziali e finali ignorati). Con meno di 32 caratteri il deposito non è attivo.

**Voce.** Un file `<cartella>/<sha256 del nome>.cifrato` che contiene un nonce di 24 byte seguito dal valore cifrato con XSalsa20-Poly1305 (`sodium_crypto_secretbox`). Il nome del segreto non compare né nel nome del file né nel contenuto: dal deposito non si ricava l'elenco dei segreti, che l'applicazione deve conoscere. Le voci si scrivono in modo atomico (file temporaneo e rinomina) con permessi 0600.

**Impronta.** I primi 12 caratteri esadecimali di HMAC-SHA-256 del valore con la chiave, nella forma `xxxx-xxxx-xxxx`. È stabile finché non cambiano valore e chiave; cambia dopo una rotazione della chiave.

**Ripiego.** Con il ripiego attivo (predefinito), se una voce non è nel deposito e il suo nome è il percorso di un file leggibile, `valore()` restituisce il contenuto del file. Serve a passare gradualmente da credenziali in file al deposito: si usa come nome il percorso del file, si sposta il segreto nel deposito, poi si cancella il file. Il formato è compatibile con il deposito delle chiavi della release 8.0 del portale.

**Rotazione.** `ruota($nuovotesto)` decifra tutte le voci con la chiave attuale e le cifra con quella nuova; se anche una sola voce non si decifra si ferma senza toccare nulla. Il nuovo testo va poi scritto nel file della chiave dal chiamante.

# 4. Uso passo per passo

L'esempio crea un deposito di prova, scrive e legge un segreto, mostra il ripiego sul file, ruota la chiave e esegue la prova di salute.

<!-- esempio:uso.php:inizio -->
```php
<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/segreti: deposito cifrato, impronta, ripiego sul file, rotazione della chiave, prova di salute.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Segreti\Deposito;
use Pfi\Segreti\Salute\Chiave;

// Cartelle di prova. In esercizio la chiave sta fuori dalla cartella dei dati (per esempio /etc/applicazione/chiave),
// è leggibile solo dall'utente del servizio e il suo testo è casuale: qui è fisso solo per rendere l'uscita ripetibile.
$base = __DIR__ . '/deposito';
@mkdir("{$base}/voci", 0700, true);
chmod("{$base}/voci", 0700);
file_put_contents("{$base}/chiave", str_repeat('chiave-di-esempio-', 3) . "\n");
chmod("{$base}/chiave", 0600);

$deposito = new Deposito("{$base}/chiave", "{$base}/voci");
echo 'Deposito attivo: ' . ($deposito->attivo() ? 'sì' : 'no') . "\n";

// Scrittura: restituisce l'impronta, che si può mostrare o registrare al posto del valore.
$impronta = $deposito->scrivi('smtp.password', 'P4ssw0rd-di-prova');
echo "Impronta della password SMTP: {$impronta}\n";
echo 'Valore letto: ' . $deposito->valore('smtp.password') . "\n";
echo 'Presente «api.token»: ' . ($deposito->presente('api.token') ? 'sì' : 'no') . "\n";

// Ripiego: se il nome è un file leggibile e la voce non è nel deposito, si legge il file (passaggio graduale).
file_put_contents("{$base}/token.txt", 'token-dal-file');
echo 'Dal file con il ripiego: ' . $deposito->valore("{$base}/token.txt") . "\n";
echo 'Senza ripiego: ' . var_export((new Deposito("{$base}/chiave", "{$base}/voci", false))->valore("{$base}/token.txt"), true) . "\n";

// Rotazione: cifra di nuovo le voci con la chiave nuova; poi il chiamante scrive il nuovo testo nel file della chiave.
$nuova = str_repeat('nuova-chiave-di-esempio-', 2);
$voci = $deposito->ruota($nuova);
file_put_contents("{$base}/chiave", $nuova . "\n");
$dopo = new Deposito("{$base}/chiave", "{$base}/voci");
echo "Voci cifrate di nuovo: {$voci}; valore dopo la rotazione: " . $dopo->valore('smtp.password') . "\n";
echo 'Impronta cambiata con la chiave: ' . ($dopo->impronta('smtp.password') !== $impronta ? 'sì' : 'no') . "\n";

// Prova di salute: chiave e cartella con permessi stretti.
$esito = (new Chiave($dopo))->esegui();
echo "Prova segreti.deposito: {$esito->stato} ({$esito->messaggio})\n";
chmod("{$base}/chiave", 0644);
$esito = (new Chiave($dopo))->esegui();
echo "Con la chiave leggibile da tutti: {$esito->stato} ({$esito->messaggio})\n";
```

Uscita (verificata eseguendo l'esempio in un progetto pulito):

```text
Deposito attivo: sì
Impronta della password SMTP: 1c0d-d3d8-eef0
Valore letto: P4ssw0rd-di-prova
Presente «api.token»: no
Dal file con il ripiego: token-dal-file
Senza ripiego: NULL
Voci cifrate di nuovo: 1; valore dopo la rotazione: P4ssw0rd-di-prova
Impronta cambiata con la chiave: sì
Prova segreti.deposito: buono (Deposito attivo)
Con la chiave leggibile da tutti: guasto (Permessi troppo larghi: la chiave è leggibile da tutti)
```
<!-- esempio:uso.php:fine -->

Uso tipico in un'applicazione:

```php
$segreti = new \Pfi\Segreti\Deposito('/etc/applicazione/chiave-segreti', '/var/lib/applicazione/segreti', true, $registro);
$password = $segreti->valore('smtp.password') ?? throw new RuntimeException('Password SMTP non configurata');
```

# 5. Configurazione

| Parametro del costruttore | Significato | Predefinito |
|---|---|---|
| `$filechiave` | percorso del file della chiave | obbligatorio |
| `$cartella` | cartella delle voci, già esistente, scrivibile dall'utente del servizio, permessi 0700 | obbligatorio |
| `$ripiego` | leggere dal file con lo stesso nome se la voce manca | `true` |
| `$registro` | registro degli eventi (`Pfi\Contratti\Registro`) | registro nullo |

Il modulo non crea la cartella né il file della chiave: sono compiti di installazione, con proprietario e permessi decisi da chi gestisce il server.

# 6. Errori ed eccezioni

`valore()`, `presente()` e `impronta()` non lanciano eccezioni per voci assenti: restituiscono `null` o `false`. Una voce alterata o cifrata con un'altra chiave si legge come assente e produce un evento di livello `error` nel registro («Voce del deposito non decifrabile»). Lanciano `Pfi\Contratti\Eccezione`:

| Metodo | Situazione | Messaggio |
|---|---|---|
| `scrivi()` | nome vuoto o valore vuoto | «Nome o valore del segreto vuoto» |
| `scrivi()` | cartella assente o non scrivibile | «Cartella del deposito non scrivibile» |
| `scrivi()` | scrittura o rinomina non riuscita | «Scrittura della voce del deposito non riuscita» |
| `ruota()` | nuovo testo più corto di 32 caratteri | «La nuova chiave del deposito deve avere almeno 32 caratteri» |
| `ruota()` | una voce non si decifra | «Rotazione interrotta: una voce non si decifra con la chiave attuale (…)» |
| scrittura, lettura, impronta | chiave assente o corta quando serve | «Chiave del deposito assente o troppo corta» |

# 7. Sicurezza

- Il registro riceve scritture, rimozioni, rotazioni e voci non decifrabili **senza il valore e senza il nome in chiaro**: il nome compare come impronta SHA-256 di 12 caratteri.
- Il valore restituito da `valore()` è una stringa PHP normale: non scriverla nei registri, nelle eccezioni o nelle risposte. Per mostrare che un segreto è configurato usare `impronta()`.
- Chiave e voci devono stare in posti diversi e con permessi stretti (chiave 0640 o 0600, cartella 0700). La prova di salute segnala come guasto una chiave o una cartella accessibili a tutti.
- Il deposito protegge dalla lettura dei file di configurazione e dei backup delle sole voci; non protegge da chi esegue codice con l'utente del servizio, che può leggere anche la chiave.
- La rotazione si fa ad applicazione ferma o in manutenzione: le voci si riscrivono una per una e il file della chiave si sostituisce dopo.

# 8. Prove di salute

| Codice | Classe | Che cosa controlla |
|---|---|---|
| `segreti.deposito` | `Pfi\Segreti\Salute\Chiave` | chiave presente e lunga almeno 32 caratteri, cartella presente, chiave e cartella non accessibili a tutti; nei dettagli il numero di voci |

Costruita senza deposito (come la crea la diagnostica dal manifesto) dà `avviso` «Deposito dei segreti non configurato»; l'applicazione la crea passando il proprio `Deposito`.

# 9. Uso nel portale

Nel portale la chiave è in `/etc/pfi/deposito/chiave` e le voci sostituiscono i file di credenziali dei servizi (`/etc/pfi/servizi/*.json`), usati come nomi delle voci grazie al ripiego. Il deposito è compatibile con quello della release 8.0. Informazione utile solo per confronto: un'altra applicazione sceglie percorsi propri.

# 10. Limiti noti

- I nomi delle voci non si ricavano dal deposito: l'elenco dei segreti attesi lo conosce l'applicazione.
- Non c'è blocco fra processi: due scritture contemporanee della stessa voce sono entrambe atomiche e vince l'ultima.
- Scadenze e promemoria di rotazione non sono gestiti dal modulo.
- La rotazione tiene in memoria tutti i valori in chiaro per il tempo dell'operazione.

# 11. Indicazioni per gli agenti

- Leggere un segreto con `valore()` e controllare `null`; non presumere che esista.
- Non registrare, stampare o includere nelle eccezioni il valore di un segreto; usare `impronta()` per riconoscerlo.
- Passare il `Deposito` ai moduli come `Pfi\Contratti\Segreti`, non come classe concreta, quando il modulo accetta l'interfaccia.
- Non creare la chiave dal codice dell'applicazione e non metterla nel repository, nel database o accanto alle voci.
- Per una nuova chiave usare `ruota()` e solo dopo scrivere il file della chiave; mai sostituire prima il file.

# 12. Riferimento dell'interfaccia

Generato dal codice della versione 1.0.0; lo spazio dei nomi è `Pfi\Segreti\`.

<!-- riferimento:inizio -->
#### `Deposito`

Classe finale, implementa `Pfi\Contratti\Segreti`. Deposito cifrato dei segreti (release 9.0, blocco M1a; D-163).

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $filechiave, promossa string $cartella, promossa bool $ripiego = true, ?Pfi\Contratti\Registro $registro = null)` |  |
| `attivo(): bool` | Il deposito è utilizzabile: chiave leggibile e abbastanza lunga, cartella delle voci presente. |
| `valore(string $nome): ?string` | Valore del segreto, o null se non c'è. |
| `presente(string $nome): bool` | Il segreto esiste ed è non vuoto. |
| `impronta(string $nome): ?string` | Impronta non reversibile del valore (per riconoscerlo senza mostrarlo), o null se non c'è. |
| `impronta_valore(string $valore): string` | Impronta di un valore con la chiave del deposito. |
| `nel_deposito(string $nome): bool` | La voce c'è nel deposito (indipendentemente dal ripiego sul file). |
| `scrivi(string $nome, string $valore): string` | Scrive o sostituisce una voce, in modo atomico, leggibile solo dal proprietario del processo. |
| `togli(string $nome): bool` | Toglie una voce; restituisce false se non c'era. |
| `ruota(string $nuovotesto): int` | Cifra di nuovo tutte le voci con una chiave nuova (rotazione della chiave del deposito). Le voci si riscrivono in modo atomico una per una; se una voce non si decifra con la chiave attuale la rotazione si ferma prima di toccare il file della chiave. Il nuovo testo della chiave va poi scritto dal chiamante nel file della chiave. |
| `voci(): int` | Numero di voci nel deposito. |
| `file_chiave(): string` | File della chiave (per le prove di salute). |
| `cartella(): string` | Cartella delle voci (per le prove di salute). |

#### `Salute\Chiave`

Classe finale, implementa `Pfi\Contratti\ProvaDiSalute`. Prova di salute del deposito: chiave presente e protetta, cartella delle voci presente e non accessibile ad altri.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa ?Pfi\Segreti\Deposito $deposito = null)` |  |
| `codice(): string` | Codice stabile della prova, per esempio «registro.scrittura». |
| `descrizione(): string` | Descrizione in italiano di che cosa controlla. |
| `esegui(): Pfi\Contratti\EsitoSalute` |  |

<!-- riferimento:fine -->

# Registro delle versioni del manuale

| Versione | Data | Modifiche |
|---|---|---|
| 1.0.0 | 16 novembre 2026 | prima versione, per pfi/segreti 1.0.0 |
