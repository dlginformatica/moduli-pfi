<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/esecutore fuori da Moodle. Per mostrarlo in un solo processo, la pagina (che accoda) e il servizio
// (che esegue) girano qui insieme e senza root; in esercizio il servizio è bin/pfi-esecutore avviato da systemd come root
// e il catalogo è un file di root (Catalogo::da_file).
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Contratti\Eccezione;
use Pfi\Esecutore\Catalogo;
use Pfi\Esecutore\Coda;
use Pfi\Esecutore\Esecutore;
use Pfi\Esecutore\Salute\Coda as SaluteCoda;

$base = __DIR__ . '/coda';
$archivi = __DIR__ . '/archivi';
@mkdir($archivi . '/2026-11', 0750, true);
touch($archivi . '/2026-11/portale-2026-11-15.tar.zst');

// Catalogo: elenco chiuso di operazioni, ciascuna con un comando come elenco di argomenti (mai una riga di shell).
$catalogo = Catalogo::da_json(json_encode(['operazioni' => [
    'saluta' => [
        'descrizione' => 'Stampa un saluto',
        'comando' => ['/bin/echo', 'Ciao', '{nome}', '{?formale:(cordiali saluti)}'],
        'parametri' => [
            'nome' => ['tipo' => 'testo', 'modello' => '^[A-Za-z ]{1,40}$'],
            'formale' => ['tipo' => 'booleano'],
        ],
    ],
    'elenca-archivio' => [
        'descrizione' => 'Elenca una cartella degli archivi',
        'comando' => ['/bin/ls', '-1', '{cartella}'],
        'parametri' => ['cartella' => ['tipo' => 'percorso', 'radice' => $archivi]],
    ],
    'attesa-lunga' => [
        'descrizione' => 'Comando che supera il tempo massimo',
        'comando' => ['/bin/sleep', '5'],
        'tempomassimo' => 1,
    ],
]]));

$coda = new Coda($base);
$coda->prepara();

// Lato pagina: accoda le richieste (l'autorizzazione dell'utente si controlla prima di questo punto).
$richieste = [
    'saluto normale' => $coda->richiedi('saluta', ['nome' => 'Mario Rossi', 'formale' => true], 'mrossi'),
    'tentativo di iniezione' => $coda->richiedi('saluta', ['nome' => 'x; rm -rf /'], 'mrossi'),
    'percorso ammesso' => $coda->richiedi('elenca-archivio', ['cartella' => $archivi . '/2026-11'], 'mrossi'),
    'percorso fuori radice' => $coda->richiedi('elenca-archivio', ['cartella' => $archivi . '/../coda'], 'mrossi'),
    'operazione sconosciuta' => $coda->richiedi('cancella-tutto', [], 'mrossi'),
    'tempo massimo superato' => $coda->richiedi('attesa-lunga', [], 'mrossi'),
];
echo "Stato appena accodata: ", $coda->esito($richieste['saluto normale'])['stato'], "\n";

// Lato servizio: prende ed esegue le richieste, una alla volta, dalla più vecchia.
$trattate = (new Esecutore($coda, $catalogo))->elabora();
echo "Richieste trattate dal servizio: {$trattate}\n\n";

// Lato pagina: legge gli esiti.
foreach ($richieste as $caso => $id) {
    $e = $coda->attendi($id, 2.0);
    $riga = "{$caso}: {$e['stato']}";
    if (isset($e['codice'])) {
        $riga .= ", codice {$e['codice']}";
    }
    if (!empty($e['uscita'])) {
        $riga .= ', uscita «' . trim($e['uscita']) . '»';
    }
    if (isset($e['motivo'])) {
        $riga .= ", motivo: {$e['motivo']}";
    }
    echo $riga, "\n";
}

// Il controllo dei parametri si può usare anche nella pagina, prima di accodare.
try {
    $catalogo->operazione('saluta')->controlla(['nome' => 'Mario', 'colore' => 'rosso']);
} catch (Eccezione $e) {
    echo "\nControllo nella pagina: {$e->getMessage()}\n";
}
$esito = (new SaluteCoda($coda))->esegui();
echo "Salute della coda: {$esito->stato}, {$esito->messaggio}\n";
