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
