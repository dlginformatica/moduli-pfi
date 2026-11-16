<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/connettori fuori da Moodle, con il fornitore finto al posto della rete: tentativi, stati accettati,
// errori, limite di frequenza e righe di registro senza segreti.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Connettori\Connettore;
use Pfi\Connettori\ErroreConnettore;
use Pfi\Connettori\Limite;
use Pfi\Connettori\Politica;
use Pfi\Connettori\Richiesta;
use Pfi\Connettori\Risposta;
use Pfi\Connettori\Trasporti\Finto;
use Pfi\Contratti\Registro;

// Registro minimo che stampa gli eventi (in un'applicazione si usa pfi/registro).
$registro = new class implements Registro {
    public function registra(string $livello, string $messaggio, array $contesto = []): void {
        $testo = preg_replace_callback('/\{(\w+)\}/', fn($m) => (string) ($contesto[$m[1]] ?? $m[0]), $messaggio);
        echo "  [registro {$livello}] ", preg_replace('/in \d+ ms/', 'in N ms', $testo), "\n";
    }
};
// Al posto di usleep(): l'esempio annota le attese invece di aspettare davvero. Le attese fra i tentativi hanno una
// variazione casuale, quindi sotto il secondo si stampa solo «meno di un secondo».
$dormi = function(float $s): void {
    if ($s > 0) {
        echo '  (attesa di ', $s >= 1 ? sprintf('%.1f s', $s) : 'meno di un secondo', ")\n";
    }
};

$finto = new Finto();
$api = new Connettore('anagrafe', $finto, new Politica(3, 0.5, 30.0), null, $registro, $dormi);

echo "1. Servizio occupato (503 con Retry-After: 2), poi risposta riuscita\n";
$finto->accoda(new Risposta(503, ['retry-after' => '2']), Finto::json(200, ['codice' => 'A-17', 'stato' => 'attivo']));
$r = $api->invia(new Richiesta('GET', 'https://api.example.org/v1/utenti/A-17?token=abc123'));
echo "  stato {$r->stato} al tentativo {$r->tentativi}: ", $r->json()['stato'], "\n";

echo "2. POST senza Idempotency-Key con 502: non si ritenta (il servizio potrebbe averla eseguita)\n";
$finto->accoda(new Risposta(502, [], 'Bad gateway'));
try {
    $api->invia(Richiesta::json('POST', 'https://api.example.org/v1/pratiche', ['oggetto' => 'prova']));
} catch (ErroreConnettore $e) {
    echo "  ErroreConnettore: {$e->getMessage()} (stato {$e->stato}, corpo disponibile: {$e->risposta->corpo})\n";
}

echo "3. Lo stesso POST con Idempotency-Key: si ritenta\n";
$finto->accoda(new Risposta(502), Finto::json(201, ['id' => 99]));
$r = $api->invia(Richiesta::json('POST', 'https://api.example.org/v1/pratiche', ['oggetto' => 'prova'],
    ['Idempotency-Key' => 'pratica-2026-0001']));
echo "  stato {$r->stato}, id ", $r->json()['id'], "\n";

echo "4. 404 accettato come risposta (non trovato non è un errore per il chiamante)\n";
$finto->accoda(new Risposta(404));
$r = $api->invia(new Richiesta('GET', 'https://api.example.org/v1/utenti/X-1'), [404]);
echo "  stato {$r->stato}\n";

echo "5. Errore di rete a ogni tentativo\n";
$finto->accoda(...array_fill(0, 3, new ErroreConnettore('errore di rete 7: connessione rifiutata', 0)));
try {
    $api->invia(new Richiesta('GET', 'https://api.example.org/v1/stato'));
} catch (ErroreConnettore $e) {
    echo "  ErroreConnettore: {$e->getMessage()} (di rete: ", $e->di_rete() ? 'sì' : 'no', ")\n";
}

echo "6. Limite di 2 richieste al secondo con un orologio fermo: la terza richiesta deve attendere\n";
$limitato = new Connettore('anagrafe-limitata', new Finto(fn() => new Risposta(204)), new Politica(1),
    new Limite(2, 1.0, null, fn() => 1000.0), null, $dormi);
for ($i = 1; $i <= 3; $i++) {
    $stato = $limitato->invia(new Richiesta('GET', 'https://api.example.org/v1/ping'))->stato;
    echo "  richiesta {$i}: stato {$stato}\n";
}
echo "Richieste ricevute dal fornitore finto nei casi 1-5: ", count($finto->ricevute), "\n";
