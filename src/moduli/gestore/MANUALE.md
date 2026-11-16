---
titolo: "Manuale di pfi/gestore"
sottotitolo: "Moduli universali del Portale di autoformazione IT: installazione, aggiornamento e ritorno indietro dei moduli con pacchetti firmati"
versione: "1.0.0"
data: "2026-11-16"
verifica_fonti: "2026-11-16"
prossima_revisione: "a ogni nuova versione del modulo"
rilascio: "pfi/gestore 1.0.0"
autori: "Gaetano Giarrusso e Ufficio USIS"
hash: "n.d. (calcolato nel MANIFEST)"
classificazione: "USO INTERNO"
---

# Manuale di pfi/gestore

Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale. Copyright 2026 Gaetano Giarrusso, licenza GPL v3 o successiva.

Il manuale è scritto per uno sviluppatore che riusa il modulo in un'applicazione PHP diversa dal portale e per un agente di intelligenza artificiale che deve scrivere codice che lo usa. Il riferimento dell'interfaccia (capitolo 12) è estratto dal codice; gli esempi sono eseguiti in un progetto pulito e l'uscita riportata è quella ottenuta. Distribuzione, versioni e regole comuni sono nella guida generale (`src/moduli/MANUALE-GENERALE.md`).

# 1. A che cosa serve

`pfi/gestore` amministra i moduli universali installati **su un server senza Composer**, in una cartella comune: installa un modulo da un **pacchetto firmato**, lo aggiorna tenendo affiancate le versioni, esegue le prove di salute della versione nuova e, se falliscono, rimette in uso la precedente; permette il ritorno indietro e l'eliminazione delle versioni non usate; controlla le dipendenze nei due versi; tiene la storia delle operazioni.

È il secondo canale di distribuzione dei moduli, accanto al repository Composer descritto nella guida generale: Composer serve a chi sviluppa un'applicazione e ne fissa le versioni nel `composer.lock`; il gestore serve a chi gestisce un server e deve aggiornare un modulo senza ricostruire l'applicazione. Il gestore è a sua volta un modulo universale e si aggiorna con la stessa procedura.

# 2. Installazione

Requisiti: PHP 8.2 o successivo con l'estensione `sodium`; `pfi/contratti` e `pfi/diagnostica` ^1.0; il programma `tar`.

```bash
composer require pfi/gestore:^1.0
```

Sul server, la prima volta: la cartella dei moduli (`/usr/local/lib/pfi/moduli` nel portale) e la chiave pubblica di firma (`/etc/pfi/moduli-firma.pub`). La coppia di chiavi la crea **chi pubblica i moduli**, fuori dal server:

```bash
php tools/firma_moduli.php chiavi /percorso/sicuro            # moduli-firma.chiave (privata, 0600) e moduli-firma.pub
php tools/firma_moduli.php pacchetto src/moduli/registro /percorso/pacchetti /percorso/sicuro/moduli-firma.chiave
```

Lo strumento `tools/firma_moduli.php` è nel repository dei moduli; la chiave privata non si stampa mai, si legge solo dal file.

# 3. Concetti

**Struttura sul server.**

```
/usr/local/lib/pfi/moduli/
  contratti/1.0.0/   contratti/attuale -> 1.0.0
  registro/1.0.0/    registro/1.1.0/   registro/attuale -> 1.1.0
  .storia.json
```

Il caricatore di `pfi/contratti` (`Caricatore::registra($base)`) registra le classi dalle cartelle `attuale`. Un aggiornamento installa la versione nuova accanto alle altre e sposta il collegamento `attuale` in modo atomico (collegamento nuovo e rinomina).

**Pacchetto firmato.** `<nome>-<versione>.tar.gz` con il contenuto della cartella del modulo e, accanto, `<archivio>.firma`: un JSON con nome, versione, SHA-256 dell'archivio e firma Ed25519 dell'impronta. Le chiavi sono in base64; `Pacchetto::genera_chiavi()` restituisce **prima la pubblica, poi la privata**.

**Installazione** (`installa($archivio, $autore)`):

1. verifica della firma con la chiave pubblica del server e dell'impronta; il manifesto del pacchetto deve corrispondere a nome e versione firmati; nessun percorso fuori dalla cartella e nessun collegamento simbolico;
2. dipendenze nei due versi: il modulo nuovo trova le versioni che richiede, e i moduli installati che dipendono da lui restano soddisfatti;
3. estrazione in `<nome>/<versione>` e spostamento di `attuale`;
4. prove di salute del modulo in un processo PHP nuovo (`bin/pfi-salute-modulo`), che carica il codice appena installato e usa `pfi/diagnostica` se presente;
5. con una prova guasta si rimette in uso la versione precedente, la storia registra «annullato» e l'operazione lancia un'eccezione.

**Ritorno indietro.** `indietro($nome, $autore, $versione)` rimette in uso la versione indicata o, senza indicazione, quella da cui si era aggiornato (dalla storia), con lo stesso controllo delle dipendenze. `rimuovi($nome, $versione)` elimina una versione non in uso.

**Combinazione collaudata.** `scostamenti($collaudata)` confronta le versioni in uso con quelle collaudate di una release (`['pfi/registro' => '1.0.0', …]`) e restituisce le differenze come `[in uso, collaudata]`.

# 4. Uso passo per passo

L'esempio, senza root, genera le chiavi, crea pacchetti firmati di `pfi/contratti`, `pfi/diagnostica` e di un modulo di prova in quattro versioni, li installa in una cartella dei moduli, mostra l'aggiornamento annullato da una prova di salute guasta, il ritorno indietro, la rimozione, quattro pacchetti rifiutati e la storia.

<!-- esempio:uso.php:inizio -->
```php
<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/gestore fuori da Moodle, senza root: chiavi di firma, pacchetti firmati, installazione in una
// cartella dei moduli con le versioni affiancate, aggiornamento, ritorno indietro, pacchetti rifiutati, storia.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Contratti\Eccezione;
use Pfi\Gestore\Gestore;
use Pfi\Gestore\Pacchetto;

$lavoro = __DIR__ . '/lavoro';
$base = "{$lavoro}/moduli";            // in esercizio /usr/local/lib/pfi/moduli
@mkdir($base, 0755, true);
@mkdir("{$lavoro}/pacchetti", 0755, true);

// 1. Chi pubblica i moduli genera una volta la coppia di chiavi e conserva la privata fuori dal server.
[$pubblica, $privata] = Pacchetto::genera_chiavi();   // in base64: prima la pubblica, poi la privata

// Un modulo dell'applicazione, in due versioni, per l'esempio.
// Con $guasta la versione dichiara una prova di salute che fallisce.
function modulo(string $dir, string $versione, array $richiede, bool $guasta = false): string {
    @mkdir("{$dir}/src", 0755, true);
    file_put_contents("{$dir}/modulo.json", json_encode(['nome' => 'pfi/saluti', 'versione' => $versione,
        'descrizione' => 'Saluti di prova', 'spazio' => 'Pfi\\Saluti\\', 'richiede' => $richiede,
        'salute' => $guasta ? ['Pfi\\Saluti\\Prova'] : []]));
    file_put_contents("{$dir}/src/Saluto.php", "<?php\nnamespace Pfi\\Saluti;\nfinal class Saluto { const VERSIONE = '{$versione}'; }\n");
    if ($guasta) {
        file_put_contents("{$dir}/src/Prova.php", "<?php\nnamespace Pfi\\Saluti;\nuse Pfi\\Contratti\\{EsitoSalute, ProvaDiSalute};\n"
            . "final class Prova implements ProvaDiSalute {\n    public function codice(): string { return 'saluti.archivio'; }\n"
            . "    public function descrizione(): string { return 'Archivio dei saluti'; }\n"
            . "    public function esegui(): EsitoSalute { return EsitoSalute::guasto('Archivio dei saluti non raggiungibile'); }\n}\n");
    }
    return $dir;
}

// 2. Pacchetti firmati: il primo è pfi/contratti, preso dalla cartella installata da Composer.
$pacchetti = [
    'contratti' => Pacchetto::crea(__DIR__ . '/vendor/pfi/contratti', "{$lavoro}/pacchetti", $privata),
    'saluti 1.0.0' => Pacchetto::crea(modulo("{$lavoro}/saluti-1.0.0", '1.0.0', ['pfi/contratti' => '^1.0']), "{$lavoro}/pacchetti", $privata),
    'saluti 1.1.0' => Pacchetto::crea(modulo("{$lavoro}/saluti-1.1.0", '1.1.0', ['pfi/contratti' => '^1.0']), "{$lavoro}/pacchetti", $privata),
    'diagnostica' => Pacchetto::crea(__DIR__ . '/vendor/pfi/diagnostica', "{$lavoro}/pacchetti", $privata),
    'saluti 1.2.0' => Pacchetto::crea(modulo("{$lavoro}/saluti-1.2.0", '1.2.0', ['pfi/contratti' => '^1.0'], true), "{$lavoro}/pacchetti", $privata),
    'saluti 2.0.0' => Pacchetto::crea(modulo("{$lavoro}/saluti-2.0.0", '2.0.0', ['pfi/registro' => '^1.0']), "{$lavoro}/pacchetti", $privata),
];
echo "Pacchetti: ", implode(', ', array_map('basename', $pacchetti)), " (ciascuno con il file .firma)\n";

// 3. Installazione e aggiornamento. Le prove di salute girano in un processo PHP nuovo (bin/pfi-salute-modulo).
$gestore = new Gestore($base, $pubblica);
foreach (['contratti', 'diagnostica', 'saluti 1.0.0', 'saluti 1.1.0'] as $p) {
    $m = $gestore->installa($pacchetti[$p], 'admin');
    echo "Installato {$m->nome} {$m->versione}\n";
}
foreach ($gestore->elenco() as $nome => $s) {
    echo "  {$nome}: in uso {$s['versione']}, presenti ", implode(', ', $s['versioni']), "\n";
}
echo "Collegamento attuale di pfi/saluti: ", basename((string) readlink("{$base}/saluti/attuale")), "\n";
echo "Scostamenti dalla combinazione collaudata: ",
    json_encode($gestore->scostamenti(['pfi/contratti' => '1.0.0', 'pfi/diagnostica' => '1.0.0', 'pfi/saluti' => '1.0.0'])), "\n";

// Un aggiornamento con la prova di salute guasta si annulla da solo.
try {
    $gestore->installa($pacchetti['saluti 1.2.0'], 'admin');
} catch (Eccezione $e) {
    echo "Aggiornamento a 1.2.0: {$e->getMessage()}\n";
}

// 4. Ritorno alla versione precedente ed eliminazione di una versione non in uso.
echo "Ritorno indietro: in uso ", $gestore->indietro('pfi/saluti', 'admin'), "\n";
$gestore->rimuovi('pfi/saluti', '1.1.0');
echo "Dopo la rimozione: ", implode(', ', $gestore->versioni('pfi/saluti')), "\n";

// 5. Pacchetti rifiutati: dipendenza mancante, firma di un'altra chiave, archivio alterato, versione già presente.
[, $altraprivata] = Pacchetto::genera_chiavi();
$estraneo = Pacchetto::crea(modulo("{$lavoro}/saluti-3.0.0", '3.0.0', []), "{$lavoro}/pacchetti", $altraprivata);
$alterato = "{$lavoro}/pacchetti/alterato.tar.gz";
copy($pacchetti['saluti 1.1.0'], $alterato);
copy($pacchetti['saluti 1.1.0'] . '.firma', $alterato . '.firma');
file_put_contents($alterato, 'x', FILE_APPEND);
foreach (['dipendenza mancante' => $pacchetti['saluti 2.0.0'], 'altra chiave' => $estraneo, 'archivio alterato' => $alterato,
        'già presente' => $pacchetti['saluti 1.0.0']] as $caso => $archivio) {
    try {
        $gestore->installa($archivio, 'admin');
    } catch (Eccezione $e) {
        echo "{$caso}: {$e->getMessage()}\n";
    }
}

// 6. Storia delle operazioni.
foreach ($gestore->leggi_storia() as $s) {
    echo "storia: {$s['modulo']} {$s['azione']} {$s['a']} (prima ", $s['da'] ?? 'nessuna', ')',
        $s['nota'] !== '' ? ": {$s['nota']}" : '', "\n";
}
```

Uscita (verificata eseguendo l'esempio in un progetto pulito):

```text
Pacchetti: contratti-1.0.0.tar.gz, saluti-1.0.0.tar.gz, saluti-1.1.0.tar.gz, diagnostica-1.0.0.tar.gz, saluti-1.2.0.tar.gz, saluti-2.0.0.tar.gz (ciascuno con il file .firma)
Installato pfi/contratti 1.0.0
Installato pfi/diagnostica 1.0.0
Installato pfi/saluti 1.0.0
Installato pfi/saluti 1.1.0
  pfi/contratti: in uso 1.0.0, presenti 1.0.0
  pfi/diagnostica: in uso 1.0.0, presenti 1.0.0
  pfi/saluti: in uso 1.1.0, presenti 1.1.0, 1.0.0
Collegamento attuale di pfi/saluti: 1.1.0
Scostamenti dalla combinazione collaudata: {"pfi\/saluti":["1.1.0","1.0.0"]}
Aggiornamento a 1.2.0: pfi/saluti 1.2.0: prova di salute guasta (saluti.archivio: Archivio dei saluti non raggiungibile); rimessa in uso la versione 1.1.0.
Ritorno indietro: in uso 1.0.0
Dopo la rimozione: 1.2.0, 1.0.0
dipendenza mancante: pfi/saluti 2.0.0 non installabile: manca pfi/registro («^1.0»).
altra chiave: Firma del pacchetto non valida: il modulo non si installa.
archivio alterato: Firma del pacchetto non valida: il modulo non si installa.
già presente: pfi/saluti 1.0.0 è già presente: per tornarci si usa il ritorno indietro.
storia: pfi/contratti aggiornato 1.0.0 (prima nessuna): pfi/diagnostica non installato: prove di salute non eseguite.
storia: pfi/diagnostica aggiornato 1.0.0 (prima nessuna): Nessuna prova di salute dichiarata.
storia: pfi/saluti aggiornato 1.0.0 (prima nessuna): Nessuna prova di salute dichiarata.
storia: pfi/saluti aggiornato 1.1.0 (prima 1.0.0): Nessuna prova di salute dichiarata.
storia: pfi/saluti annullato 1.2.0 (prima 1.1.0): saluti.archivio: Archivio dei saluti non raggiungibile
storia: pfi/saluti riportato 1.0.0 (prima 1.1.0)
storia: pfi/saluti eliminata 1.1.0 (prima nessuna)
```
<!-- esempio:uso.php:fine -->

Sul server le stesse operazioni si fanno con il programma `bin/pfi-moduli` (come root, o attraverso `pfi/esecutore`):

```bash
pfi-moduli elenco
pfi-moduli installa /var/tmp/registro-1.1.0.tar.gz admin
pfi-moduli indietro pfi/registro admin [versione]
pfi-moduli rimuovi pfi/registro 1.0.0
```

Le variabili `PFI_MODULI` e `PFI_MODULI_CHIAVE` indicano cartella dei moduli e file della chiave pubblica, se diversi da quelli predefiniti.

# 5. Configurazione

| Elemento | Parametri e predefiniti |
|---|---|
| `Gestore` | `$base` (cartella dei moduli), `$pubblica` (chiave pubblica in base64), `$registro` (nullo), `$salute` (funzione `(nome, cartella) => EsitoSalute`; predefinita la prova in un processo nuovo) |
| `bin/pfi-moduli` | `PFI_MODULI` (`/usr/local/lib/pfi/moduli`), `PFI_MODULI_CHIAVE` (`/etc/pfi/moduli-firma.pub`) |
| `Salute\Dipendenze` | `$gestore` (nessuno: controlla i moduli caricati dal caricatore) |

# 6. Errori ed eccezioni

Tutti `Pfi\Contratti\Eccezione`.

| Situazione | Messaggio |
|---|---|
| firma assente, illeggibile o non valida (altra chiave, archivio alterato) | «Firma del pacchetto assente o non leggibile.», «Firma del pacchetto non valida: il modulo non si installa.» |
| percorsi o collegamenti non ammessi; archivio non estraibile | «Pacchetto con percorsi non ammessi.», «Pacchetto con collegamenti simbolici: non ammesso.», «Pacchetto non estraibile.» |
| manifesto diverso da quanto firmato | «Il manifesto del pacchetto non corrisponde alla firma.» |
| dipendenze non soddisfatte | «<nome> <versione> non installabile: manca … / … richiede …» |
| versione già presente | «<nome> <versione> è già presente: per tornarci si usa il ritorno indietro.» |
| prova di salute guasta | «<nome> <versione>: prova di salute guasta (…); rimessa in uso la versione <precedente>.» |
| ritorno o rimozione impossibili | «Ritorno a … non possibile: …», «… nessuna versione precedente presente.», «… non è presente.», «La versione in uso non si elimina.» |
| chiave privata non valida in `crea()` | «Chiave privata non valida.» |

# 7. Sicurezza

- La chiave privata si conserva fuori dal server e fuori dal repository (nel portale: sul PC di chi pubblica, nella cartella dei segreti); con la chiave pubblica sbagliata nessun modulo si installa.
- Le operazioni che scrivono richiedono i permessi sulla cartella dei moduli: nel portale le esegue il servizio `pfi-esecutore`, e il pannello accoda soltanto le richieste.
- Un pacchetto alterato anche di un byte viene rifiutato; un pacchetto firmato con un'altra chiave anche.
- La storia contiene autore e messaggi delle prove, non segreti.

# 8. Prove di salute

| Codice | Classe | Che cosa controlla |
|---|---|---|
| `gestore.dipendenze` | `Pfi\Gestore\Salute\Dipendenze` | che ogni modulo installato (o caricato, senza gestore) trovi le versioni che richiede; altrimenti `guasto` con l'elenco |

# 9. Uso nel portale

Nel portale il pannello «Moduli universali» è l'adattatore `tool_pfimoduli`: mostra versioni, dipendenze, scostamenti dalla combinazione collaudata della release e prove di salute, e accoda a `pfi-esecutore` installazioni e ritorni eseguiti con `pfi-moduli`. Informazione utile solo per confronto.

# 10. Limiti noti

- Una versione annullata per prova di salute guasta resta nella cartella (nell'esempio, 1.2.0 compare fra le versioni presenti): non è in uso, ma si potrebbe rimettere in uso con `indietro()` indicando quella versione; va eliminata con `rimuovi()`.
- `indietro()` controlla le dipendenze ma non riesegue le prove di salute; dopo un ritorno indietro conviene eseguire la diagnostica.
- La storia di `rimuovi()` non registra l'autore.
- Le prove di salute controllano il modulo, non l'uso che ne fa l'applicazione: dopo un aggiornamento resta consigliata la verifica complessiva dell'applicazione.
- Il gestore aggiorna se stesso in due tempi: il processo in corso termina con il codice vecchio, il successivo usa quello nuovo.

# 11. Indicazioni per gli agenti

- Non scrivere a mano nella cartella dei moduli né spostare i collegamenti `attuale`: usare sempre il gestore o `pfi-moduli`.
- Non chiedere né leggere la chiave privata: i pacchetti li firma chi pubblica i moduli, con `tools/firma_moduli.php`.
- Prima di aggiornare un modulo in esercizio, confrontare con `scostamenti()` la combinazione collaudata della release.
- Dopo un'installazione annullata, eliminare la versione guasta con `rimuovi()`.
- Nelle prove usare una cartella temporanea come base e una funzione `$salute` propria se non si vuole avviare un processo PHP.

# 12. Riferimento dell'interfaccia

Generato dal codice della versione 1.0.0; lo spazio dei nomi è `Pfi\Gestore\`.

<!-- riferimento:inizio -->
#### `Gestore`

Classe finale. Gestione dei moduli universali installati (release 9.0, blocco M7b; D-162; proposta 49 § 3.9).

| Metodo | Descrizione |
|---|---|
| `__construct(promossa string $base, promossa string $pubblica, ?Pfi\Contratti\Registro $registro = null, ?Closure $salute = null)` |  |
| `static salute_in_processo(string $base): Closure` |  |
| `installati(): array` |  |
| `versioni(string $nome): array` |  |
| `elenco(): array` | Stato per il pannello: per ogni modulo versione in uso, altre versioni presenti, requisiti mancanti. |
| `scostamenti(array $collaudata): array` | Combinazione rispetto a quella collaudata di una release (D-162, rischio: un'installazione aggiornata modulo per modulo si discosta dalla release del kit). |
| `installa(string $archivio, string $autore): Pfi\Contratti\Manifesto` | Installa un pacchetto firmato e lo mette in uso; restituisce il manifesto installato. Con la prova di salute guasta ripristina la versione precedente e lancia Eccezione. |
| `indietro(string $nome, string $autore, ?string $versione = null): string` | Torna alla versione presente indicata o, senza indicazione, alla precedente usata. |
| `attuale(string $nome): ?string` |  |
| `rimuovi(string $nome, string $versione): void` | Elimina una versione non in uso. |
| `leggi_storia(): array` |  |

#### `Pacchetto`

Classe finale. Pacchetto firmato di un modulo universale (D-162): archivio `<nome>-<versione>.tar.gz` con la cartella del modulo (modulo.json, src/, LEGGIMI.md, CHANGELOG.md…) e file `.firma` in JSON con nome, versione, impronta SHA-256 dell'archivio e firma Ed25519 dell'impronta (libsodium). La chiave pubblica di verifica sta sul server; quella privata resta a chi pubblica i moduli e non entra mai nel portale.

| Metodo | Descrizione |
|---|---|
| `static genera_chiavi(): array` |  |
| `static crea(string $cartella, string $destinazione, string $privata): string` | Crea il pacchetto di un modulo da una cartella; restituisce il percorso dell'archivio (la firma accanto). |
| `static verifica(string $archivio, string $pubblica, string $temporanea): array` | Verifica firma e impronta, estrae in una cartella temporanea e controlla che il manifesto corrisponda alla firma. |

#### `Salute\Dipendenze`

Classe finale, implementa `Pfi\Contratti\ProvaDiSalute`. Prova di salute: ogni modulo installato trova le versioni dei moduli che richiede.

| Metodo | Descrizione |
|---|---|
| `__construct(promossa ?Pfi\Gestore\Gestore $gestore = null)` |  |
| `codice(): string` | Codice stabile della prova, per esempio «registro.scrittura». |
| `descrizione(): string` | Descrizione in italiano di che cosa controlla. |
| `esegui(): Pfi\Contratti\EsitoSalute` |  |

<!-- riferimento:fine -->

# Registro delle versioni del manuale

| Versione | Data | Modifiche |
|---|---|---|
| 1.0.0 | 16 novembre 2026 | prima versione, per pfi/gestore 1.0.0 |
