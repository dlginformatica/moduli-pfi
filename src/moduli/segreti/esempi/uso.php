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
