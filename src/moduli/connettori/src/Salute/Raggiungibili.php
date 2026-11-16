<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Connettori\Salute;

use Pfi\Connettori\Connettore;
use Pfi\Connettori\ErroreConnettore;
use Pfi\Connettori\Richiesta;
use Pfi\Contratti\EsitoSalute;
use Pfi\Contratti\ProvaDiSalute;

/**
 * Prova di salute: i servizi configurati rispondono (qualunque stato HTTP vale come raggiungibile, perché senza
 * credenziali molti servizi rispondono 401). Senza connettori (costruzione dal manifesto) controlla solo cURL.
 */
final class Raggiungibili implements ProvaDiSalute {

    /** @param array<string,array{0:Connettore,1:string}> $servizi nome => [connettore, indirizzo da provare] */
    public function __construct(private readonly array $servizi = []) {
    }

    public function codice(): string {
        return 'connettori.raggiungibili';
    }

    public function descrizione(): string {
        return 'Servizi esterni raggiungibili';
    }

    public function esegui(): EsitoSalute {
        if (!function_exists('curl_init')) {
            return EsitoSalute::guasto('Estensione cURL di PHP non disponibile.');
        }
        if (!$this->servizi) {
            return EsitoSalute::buono('cURL disponibile; nessun servizio da provare in questa configurazione.');
        }
        $irraggiungibili = [];
        $dettagli = [];
        foreach ($this->servizi as $nome => [$connettore, $url]) {
            try {
                $r = $connettore->invia(new Richiesta('HEAD', $url, [], null, 5.0), range(100, 599));
                $dettagli[$nome] = $r->stato;
            } catch (ErroreConnettore $e) {
                $irraggiungibili[] = $nome;
                $dettagli[$nome] = 'non raggiungibile';
            }
        }
        if (!$irraggiungibili) {
            return EsitoSalute::buono('Servizi esterni raggiungibili.', $dettagli);
        }
        $tutti = count($irraggiungibili) === count($this->servizi);
        return new EsitoSalute($tutti ? EsitoSalute::GUASTO : EsitoSalute::AVVISO,
            'Servizi non raggiungibili: ' . implode(', ', $irraggiungibili) . '.', $dettagli);
    }
}
