<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/backup fuori da Moodle, senza root: due unità di tipo cartella (una cifrata), una copia in una
// seconda cartella, prova di ripristino, ripristino che accantona i dati attuali, file alterato, conservazione, salute.
// L'unità Postgres (pg_dump, pg_restore) si configura allo stesso modo ma richiede il database.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Backup\Backup;
use Pfi\Backup\Cifratura;
use Pfi\Backup\Destinazioni\Cartella as CartellaRemota;
use Pfi\Backup\Salute\Recente;
use Pfi\Backup\Unita\Cartella;
use Pfi\Contratti\Eccezione;

$b = __DIR__ . '/prova';
foreach (['dati/documenti', 'configurazione', 'backup', 'remoto', 'accantonati', 'chiavi'] as $c) {
    @mkdir("{$b}/{$c}", 0700, true);
}
file_put_contents("{$b}/dati/documenti/verbale.txt", "Verbale della riunione\n");
file_put_contents("{$b}/dati/cache.tmp", 'da non salvare');
file_put_contents("{$b}/configurazione/servizi.json", '{"smtp":"segreto"}');

// La chiave della cifratura si genera una volta e si conserva FUORI dal portale e dal backup.
Cifratura::genera("{$b}/chiavi/backup.chiave");
$cifratura = Cifratura::da_file("{$b}/chiavi/backup.chiave");

$backup = (new Backup("{$b}/backup", 'applicazione-2.4.0', 2, 1))
    ->unita(new Cartella('dati', "{$b}/dati", "{$b}/accantonati", null, null, ['cache.tmp']))
    ->unita(new Cartella('configurazione', "{$b}/configurazione", "{$b}/accantonati", $cifratura))
    ->destinazione(new CartellaRemota("{$b}/remoto"));

// 1. Tre backup in giorni diversi (data fissata solo per l'esempio).
foreach (['2026-11-13 02:00', '2026-11-14 02:00', '2026-11-15 02:00'] as $q) {
    $id = $backup->esegui([], new DateTimeImmutable($q));
}
echo "Ultimo backup: {$id}\n";
$file = array_values(array_diff(scandir($backup->percorso($id)), ['.', '..']));
echo "File: ", implode(', ', $file), "\n";
$m = json_decode(file_get_contents($backup->percorso($id) . '/MANIFESTO.json'), true);
echo "Unità nel manifesto: ", implode(', ', array_keys($m['unita'])), "; rilascio {$m['rilascio']}\n";
echo "La cache esclusa non è nell'archivio: ",
    str_contains(shell_exec('tar -tzf ' . escapeshellarg($backup->percorso($id) . '/dati.tar.gz')), 'cache.tmp') ? 'no' : 'sì', "\n";

// 2. Prova di ripristino (non modifica nulla) e ripristino vero di una sola unità.
echo "Prova di ripristino: ", implode(', ', $backup->ripristina($id)), " ripristinabili\n";
file_put_contents("{$b}/dati/documenti/verbale.txt", "Verbale modificato per errore\n");
$backup->ripristina($id, ['dati'], false);
echo "Dopo il ripristino: ", trim(file_get_contents("{$b}/dati/documenti/verbale.txt")),
    "; dati sostituiti accantonati in ", count(glob("{$b}/accantonati/dati-prima-del-ripristino-*")), " cartella\n";

// 3. Rilascio diverso, file alterato, chiave sbagliata.
$altro = (new Backup("{$b}/backup", 'applicazione-2.5.0'))->unita(new Cartella('dati', "{$b}/dati", "{$b}/accantonati"));
$alterato = $backup->percorso('20261114-020000') . '/dati.tar.gz';
file_put_contents($alterato, 'x', FILE_APPEND);
$chiavesbagliata = new Cifratura(random_bytes(32));
$prove = [
    'rilascio diverso' => fn() => $altro->ripristina($id),
    'file alterato' => fn() => $backup->ripristina('20261114-020000'),
    'chiave sbagliata' => fn() => $chiavesbagliata->decifra($backup->percorso($id) . '/configurazione.tar.gz.cifrato', "{$b}/x.tar.gz"),
];
foreach ($prove as $caso => $f) {
    try {
        $f();
    } catch (Eccezione $e) {
        echo "{$caso}: {$e->getMessage()}\n";
    }
}

// 4. Conservazione (2 backup e comunque quelli dell'ultimo giorno, in locale e in remoto): si applica a ogni esecuzione,
// quindi il backup del 13 è già stato eliminato al terzo backup; chiamata di nuovo, non trova altro da eliminare.
$eliminati = $backup->conserva(new DateTimeImmutable('2026-11-15 12:00'));
echo "Eliminati da una nuova conservazione: ", $eliminati ? implode(', ', $eliminati) : 'nessuno', "\n";
echo "Restano in locale: ", implode(', ', $backup->backup()), "; in remoto: ",
    implode(', ', array_reverse((new CartellaRemota("{$b}/remoto"))->elenco())), "\n";

// 5. Prova di salute dopo un backup di adesso.
$backup->esegui();
$esito = (new Recente($backup, "{$b}/backup"))->esegui();
echo "Salute: {$esito->stato}, {$esito->messaggio}\n";
