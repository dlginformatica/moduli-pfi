<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/stampa fuori da Moodle: modello del canale «stampa», pagina con intestazione e numeri di pagina,
// anteprima HTML con la politica dei contenuti, PDF con Chromium, prova di salute.
// Richiede Chromium sul server: il percorso si indica con la variabile PFI_CHROMIUM oppure lo trova Chromium::cerca().
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Contratti\Eccezione;
use Pfi\Modelli\Archivi\Memoria;
use Pfi\Modelli\Modelli;
use Pfi\Modelli\Modello;
use Pfi\Modelli\Schema;
use Pfi\Stampa\Chromium;
use Pfi\Stampa\Pagina;
use Pfi\Stampa\Qr;
use Pfi\Stampa\Salute\Motore;
use Pfi\Stampa\Stampa;

// 1. Modello di stampa: parti html (obbligatoria), titolo, intestazione e piedipagina.
$schema = (new Schema('corsi/foglio_presenze', 'Foglio delle presenze'))
    ->segnaposto('corso', 'Titolo del corso', 'Sicurezza informatica')
    ->segnaposto('partecipanti', 'Partecipanti', [['nome' => 'Mario Rossi']], true)
    ->segnaposto('partecipanti.nome', 'Nome e cognome', 'Mario Rossi');
$modelli = (new Modelli(new Memoria()))->dichiara($schema, new Modello('corsi/foglio_presenze', 'stampa', 'it', [
    'titolo' => 'Presenze: {{corso}}',
    'intestazione' => '{{corso}}',
    'piedipagina' => 'Foglio firme, pagina {pagina} di {pagine}',
    'html' => '<h1>{{corso}}</h1><table><thead><tr><th>Partecipante</th><th>Firma</th></tr></thead><tbody>'
        . '{{#partecipanti}}<tr><td>{{nome}}</td><td></td></tr>{{/partecipanti}}</tbody></table>'
        . '<img src="https://example.org/logo.png" alt="logo esterno: la politica dei contenuti lo blocca">',
]));

$dati = ['corso' => 'Sicurezza <informatica>', 'partecipanti' => []];
for ($i = 1; $i <= 60; $i++) {
    $dati['partecipanti'][] = ['nome' => "Partecipante {$i}"];
}
$stile = 'table{width:100%;border-collapse:collapse}td,th{border:1px solid #999;padding:6px;height:8mm}';

// 2. Anteprima: documento HTML completo, utile anche per l'editor.
$programma = getenv('PFI_CHROMIUM') ?: Chromium::cerca();
$chromium = $programma ? new Chromium($programma, 60, function_exists('posix_geteuid') && posix_geteuid() === 0) : null;
$stampa = new Stampa($modelli, $chromium);
$html = $stampa->anteprima('corsi/foglio_presenze', $dati, 'it', new Pagina('A4', false, [25, 15, 20, 15]), $stile);
preg_match('/http-equiv="Content-Security-Policy" content="([^"]+)"/', $html, $csp);
preg_match('/@page\{[^@]*/', $html, $page);
echo "Politica dei contenuti: {$csp[1]}\n";
echo "Regole di pagina: {$page[0]}\n";
echo "Titolo con escape: ", preg_match('#<title>(.*?)</title>#', $html, $t) ? $t[1] : '', "\n";

// 3. PDF.
if ($chromium === null) {
    echo "Chromium non trovato: indicare il programma con PFI_CHROMIUM.\n";
} else {
    $pdf = $stampa->pdf('corsi/foglio_presenze', $dati, 'it', new Pagina('A4', false, [25, 15, 20, 15]), $stile);
    file_put_contents(__DIR__ . '/presenze.pdf', $pdf);
    echo "PDF: inizia con ", substr($pdf, 0, 5), ", pagine: ", preg_match_all('#/Type\s*/Page[^s]#', $pdf), "\n";
}

// 4. Codici QR: TCPDF (presente in Moodle) o il programma qrencode; senza nessuno dei due, un'eccezione chiara.
try {
    $img = Qr::img('https://example.org/attestato/A-17', 'Verifica dell\'attestato');
    echo 'QR: ', substr($img, 0, 37), "…\n";
} catch (Eccezione $e) {
    echo "QR: {$e->getMessage()}\n";
}

// 5. Prova di salute con impaginazione di prova.
$esito = (new Motore($chromium, true))->esegui();
echo "Salute: {$esito->stato}, {$esito->messaggio}\n";
