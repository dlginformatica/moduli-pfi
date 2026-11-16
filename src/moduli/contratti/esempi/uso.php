<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/contratti fuori da Moodle: versioni, manifesto, prova di salute, registro nullo.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Contratti\EsitoSalute;
use Pfi\Contratti\Manifesto;
use Pfi\Contratti\ProvaDiSalute;
use Pfi\Contratti\RegistroNullo;
use Pfi\Contratti\Versione;

// 1. Versioni e vincoli nella forma di Composer.
$v = Versione::da_testo('1.4.2');
foreach (['^1.0', '~1.3', '>=1.0 <1.4', '^2.0 || ^1.4'] as $vincolo) {
    printf("%s soddisfa %-14s %s\n", $v, $vincolo, $v->soddisfa($vincolo) ? 'sì' : 'no');
}

// 2. Manifesto di un modulo dell'applicazione e requisiti mancanti.
$manifesto = Manifesto::da_json(json_encode([
    'nome' => 'pfi/fatture',
    'versione' => '0.1.0',
    'descrizione' => 'Modulo di prova',
    'spazio' => 'App\\Fatture\\',
    'richiede' => ['pfi/contratti' => '^1.0', 'pfi/registro' => '^1.0'],
]));
$disponibili = ['pfi/contratti' => Versione::da_testo('1.0.0')];
echo "Requisiti mancanti di {$manifesto->nome}: " . implode('; ', $manifesto->requisiti_mancanti($disponibili, '8.3.0')) . "\n";

// 3. Una prova di salute: rapida, in sola lettura, messaggio in italiano senza segreti.
final class CartellaScrivibile implements ProvaDiSalute {
    public function __construct(private string $cartella) {}
    public function codice(): string { return 'fatture.cartella'; }
    public function descrizione(): string { return 'La cartella delle fatture esiste ed è scrivibile'; }
    public function esegui(): EsitoSalute {
        return is_dir($this->cartella) && is_writable($this->cartella)
            ? EsitoSalute::buono('Cartella scrivibile')
            : EsitoSalute::guasto('Cartella assente o non scrivibile', ['cartella' => $this->cartella]);
    }
}
$esito = (new CartellaScrivibile(sys_get_temp_dir()))->esegui();
echo "Prova fatture.cartella: {$esito->stato} ({$esito->messaggio})\n";

// 4. Registro nullo: lo si passa ai moduli quando l'applicazione non ha ancora un registro.
(new RegistroNullo())->registra(RegistroNullo::INFORMAZIONE, 'Avvio di {app}', ['app' => 'fatture']);
echo "Livello numerico di 'warning': " . RegistroNullo::LIVELLI['warning'] . "\n";
