<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/registro fuori da Moodle: destinazioni con livello minimo, mascheramento, correlazione, RFC 5424.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Registro\Destinazioni\File;
use Pfi\Registro\Destinazioni\Memoria;
use Pfi\Registro\Destinazioni\Tabella;
use Pfi\Registro\Mascheratore;
use Pfi\Registro\Registro;
use Pfi\Registro\Rfc5424;
use Pfi\Registro\Salute\Destinazioni;

$cartella = __DIR__ . '/registri';
@mkdir($cartella, 0750);

// Orologio e correlazione fissi solo per rendere ripetibile l'uscita dell'esempio.
$orologio = fn() => new DateTimeImmutable('2026-11-16T09:30:00+01:00');
$mascheratore = new Mascheratore();
$mascheratore->valore_segreto('Xq7-segreto-letto-dal-deposito');

$memoria = new Memoria();
$tabella = new Tabella(new PDO('sqlite:' . $cartella . '/registro.sqlite'));
$tabella->crea_tabella();
$registro = (new Registro($mascheratore, 'fatture', 'a1b2c3d4e5f60718', $orologio))
    ->aggiungi($memoria)                                                   // tutto, da debug in su
    ->aggiungi(new File($cartella . '/registro.log'), Registro::INFORMAZIONE)
    ->aggiungi($tabella, Registro::AVVISO);                                // solo avvisi e più gravi

// 1. Segnaposto {nome} sostituiti dal contesto; chiavi sensibili, forme nel testo e valori noti mascherati.
$registro->info('Fattura {numero} inviata a {destinatario}', ['numero' => 'F-2026-118', 'destinatario' => 'ufficio@example.org']);
$registro->error('Invio non riuscito: {errore}', [
    'errore' => 'risposta 401 per https://utente:pw1234@api.example.org/invio',
    'password' => 'non-deve-comparire',
    'token_api' => 'Xq7-segreto-letto-dal-deposito',
    'nota' => 'chiave usata: Xq7-segreto-letto-dal-deposito',
]);
$registro->debug('Dettaglio tecnico visibile solo in memoria');

// 2. Un altro modulo con le stesse destinazioni e la stessa correlazione.
$registro->per_modulo('pfi/comunicazione')->warning('Coda di invio lenta', ['in_coda' => 12]);

echo "Eventi in memoria: " . count($memoria->voci) . "\n";
foreach ($memoria->voci as $v) {
    echo $v->json(), "\n";
}
echo "\nRighe nel file (livello info e superiori): " . count(file($cartella . '/registro.log')) . "\n";
echo "Righe nella tabella (warning e superiori): " . count($tabella->cerca()) . "\n";
echo "Ricerca nella tabella per modulo pfi/comunicazione: "
    . $tabella->cerca(['modulo' => 'pfi/comunicazione'])[0]['messaggio'] . "\n";

// 3. Lo stesso evento nella forma RFC 5424 inviata ai syslog (host e pid fissi per l'esempio).
echo "\n", (new Rfc5424('local0', 'server-app', 'fatture', 4242))->formatta($memoria->voci[1]), "\n";

// 4. Prova di salute del registro configurato.
$esito = (new Destinazioni($registro))->esegui();
echo "\nSalute: {$esito->stato}, {$esito->messaggio}\n";
