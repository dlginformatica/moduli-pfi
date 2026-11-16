<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/diagnostica fuori da Moodle: prove dai manifesti e costruite dall'applicazione, stato, rapporti.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Contratti\EsitoSalute;
use Pfi\Contratti\Manifesto;
use Pfi\Contratti\ProvaDiSalute;
use Pfi\Diagnostica\Diagnostica;

// Una prova senza argomenti nel costruttore: la diagnostica la costruisce da sola leggendo il manifesto.
final class EstensioniPhp implements ProvaDiSalute {
    public function codice(): string { return 'fatture.php'; }
    public function descrizione(): string { return 'Estensioni PHP richieste'; }
    public function esegui(): EsitoSalute {
        $mancanti = array_values(array_filter(['json', 'mbstring'], fn($e) => !extension_loaded($e)));
        return $mancanti ? EsitoSalute::guasto('Estensioni mancanti: ' . implode(', ', $mancanti))
            : EsitoSalute::buono('Estensioni presenti');
    }
}

// Una prova con una dipendenza: l'applicazione la costruisce e la passa come sostituzione.
final class ServizioRaggiungibile implements ProvaDiSalute {
    public function __construct(private bool $raggiungibile) {}
    public function codice(): string { return 'fatture.servizio'; }
    public function descrizione(): string { return 'Il servizio esterno risponde'; }
    public function esegui(): EsitoSalute {
        return $this->raggiungibile ? EsitoSalute::buono('Risponde') : EsitoSalute::guasto('Non risponde');
    }
}

// Una prova difettosa: l'eccezione non ferma la diagnostica e il suo testo non finisce nel rapporto.
final class ProvaDifettosa implements ProvaDiSalute {
    public function codice(): string { return 'fatture.difettosa'; }
    public function descrizione(): string { return 'Prova che lancia un\'eccezione'; }
    public function esegui(): EsitoSalute { throw new RuntimeException('password=segreta'); }
}

$manifesto = Manifesto::da_json(json_encode([
    'nome' => 'pfi/fatture', 'versione' => '1.0.0', 'descrizione' => 'Applicazione di prova', 'spazio' => 'App\\',
    'salute' => [EstensioniPhp::class, ServizioRaggiungibile::class, 'App\\ClasseInesistente'],
]));

$diagnostica = (new Diagnostica())
    ->da_manifesti([$manifesto], [ServizioRaggiungibile::class => new ServizioRaggiungibile(true)])
    ->aggiungi('pfi/fatture', new ProvaDifettosa());
$risultati = $diagnostica->esegui();

echo Diagnostica::markdown($risultati), "\n";
echo Diagnostica::json($risultati, new DateTimeImmutable('2026-11-16T09:00:00+01:00')), "\n";
echo 'Codice di uscita per il monitoraggio: ', Diagnostica::stato($risultati) === 'guasto' ? 1 : 0, "\n";
