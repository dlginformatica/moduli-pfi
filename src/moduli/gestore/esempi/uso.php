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
