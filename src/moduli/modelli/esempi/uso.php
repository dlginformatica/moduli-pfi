<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/modelli fuori da Moodle: schema dei segnaposti, modelli predefiniti, resa con escape HTML,
// lingua di ripiego, versioni modificate in un archivio SQLite, errori di sintassi e ripristino.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Modelli\Archivi\Tabella;
use Pfi\Modelli\ErroreModello;
use Pfi\Modelli\Modelli;
use Pfi\Modelli\Modello;
use Pfi\Modelli\Schema;

// 1. Il componente dichiara i segnaposti del tipo di messaggio e i modelli predefiniti, scritti nel codice.
$schema = (new Schema('fatture/sollecito', 'Sollecito di pagamento'))
    ->segnaposto('cliente', 'Nome del cliente', 'Maria Bianchi')
    ->segnaposto('scadenza', 'Data di scadenza già formattata', '30 novembre 2026')
    ->segnaposto('fatture', 'Fatture aperte, ciascuna con numero e importo', [['numero' => 'F-1', 'importo' => '120,00 €']], true)
    ->segnaposto('fatture.numero', 'Numero della fattura', 'F-1')
    ->segnaposto('fatture.importo', 'Importo già formattato', '120,00 €');

$predefinito = new Modello('fatture/sollecito', 'posta', 'it', [
    'oggetto' => "Sollecito: {{#fatture}}{{numero}} {{/fatture}}\n in scadenza",
    'html' => '<p>Gentile {{cliente}},</p><ul>{{#fatture}}<li>{{numero}}: {{importo}}</li>{{/fatture}}</ul>'
        . '{{^fatture}}<p>Nessuna fattura aperta.</p>{{/fatture}}<p>Scadenza: {{scadenza}}</p>',
    'testo' => "Gentile {{cliente}},\n{{#fatture}}- {{numero}}: {{importo}}\n{{/fatture}}Scadenza: {{scadenza}}",
]);

$pdo = new PDO('sqlite::memory:');
$archivio = new Tabella($pdo);
$archivio->crea_tabella();
$modelli = (new Modelli($archivio, 'it'))->dichiara($schema, $predefinito);

// 2. Resa: nelle parti che finiscono con «html» i valori hanno l'escape; l'oggetto sta su una riga.
$dati = ['cliente' => 'Rossi & <Figli>', 'scadenza' => '30 novembre 2026',
    'fatture' => [['numero' => 'F-118', 'importo' => '250,00 €'], ['numero' => 'F-121', 'importo' => '90,50 €']]];
foreach ($modelli->rendi('fatture/sollecito', 'posta', 'it', $dati) as $parte => $testo) {
    echo "[{$parte}]\n{$testo}\n";
}

// 3. Lingua senza modello: si usa la lingua predefinita.
echo "\nIn francese (ripiego sull'italiano): ", $modelli->rendi('fatture/sollecito', 'posta', 'fr', $dati)['oggetto'], "\n";

// 4. Un amministratore modifica il modello: si salva come nuova versione, con gli avvisi sullo schema.
[$salvato, $avvisi] = $modelli->salva(new Modello('fatture/sollecito', 'posta', 'it', [
    'oggetto' => 'Promemoria per {{cliente}}',
    'html' => '<p>Gentile {{cliente}}, codice {{{codice_cliente}}}</p>',
    'testo' => 'Gentile {{cliente}}',
]), 'admin', 'Testo più breve');
echo "\nSalvata la versione {$salvato->versione}; avvisi:\n- ", implode("\n- ", $avvisi), "\n";
echo "Oggetto attuale: ", $modelli->rendi('fatture/sollecito', 'posta', 'it', $dati)['oggetto'], "\n";

// 5. Errori di sintassi: si segnalano con riga e colonna; i parziali non sono ammessi.
foreach (["Riga uno\nCiao {{cliente}", 'Allegato: {{> altro_modello}}', '{{#fatture}}{{numero}}'] as $sbagliato) {
    try {
        new Modello('fatture/sollecito', 'posta', 'it', ['testo' => $sbagliato]);
    } catch (ErroreModello $e) {
        echo "Errore: {$e->getMessage()}; posizione nelle proprietà: {$e->riga}:{$e->colonna}\n";
    }
}

// 6. Ripristino del predefinito del codice (versione 0) come nuova versione; storico delle versioni.
$modelli->ripristina('fatture/sollecito', 'posta', 'it', 0, 'admin');
echo "\nDopo il ripristino: ", $modelli->rendi('fatture/sollecito', 'posta', 'it', $dati)['oggetto'], "\n";
foreach ($archivio->versioni('fatture/sollecito', 'posta', 'it') as $v) {
    echo "versione {$v['versione']} di {$v['autore']}: {$v['nota']}\n";
}

// 7. Anteprima con i dati di esempio dello schema.
echo "\nAnteprima: ", $modelli->anteprima($predefinito)['testo'], "\n";
