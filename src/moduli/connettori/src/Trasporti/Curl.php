<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Connettori\Trasporti;

use Pfi\Connettori\ErroreConnettore;
use Pfi\Connettori\Richiesta;
use Pfi\Connettori\Risposta;
use Pfi\Connettori\Trasporto;

/**
 * Trasporto con l'estensione cURL di PHP. Verifica sempre il certificato del server; proxy dell'ente facoltativo
 * (con le eccezioni di $senzaproxy); nessun reindirizzamento seguito, perché un reindirizzamento può portare le
 * credenziali verso un altro host; corpo della risposta limitato a $massimo byte.
 */
final class Curl implements Trasporto {

    /**
     * @param array{indirizzo?:string,utente?:string,password?:string,senzaproxy?:string} $proxy
     */
    public function __construct(
        private readonly float $attesa = 30.0,
        private readonly float $connessione = 10.0,
        private readonly array $proxy = [],
        private readonly ?string $ca = null,
        private readonly int $massimo = 20 * 1024 * 1024,
        private readonly string $agente = 'PFI-connettori/1.0',
    ) {
        if (!function_exists('curl_init')) {
            throw new \Pfi\Contratti\Eccezione('Estensione cURL di PHP non disponibile.');
        }
    }

    public function invia(Richiesta $richiesta): Risposta {
        $h = curl_init($richiesta->url);
        $intestazioni = [];
        $corpo = '';
        $troppo = false;
        $righe = ['Expect:'];
        foreach ($richiesta->intestazioni as $k => $v) {
            $righe[] = $k . ': ' . str_replace(["\r", "\n"], '', $v);
        }
        $opzioni = [
            CURLOPT_CUSTOMREQUEST => $richiesta->metodo,
            CURLOPT_HTTPHEADER => $righe,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connessione * 1000),
            CURLOPT_TIMEOUT_MS => (int) (($richiesta->attesa ?? $this->attesa) * 1000),
            CURLOPT_USERAGENT => $this->agente,
            CURLOPT_HEADERFUNCTION => function($c, string $riga) use (&$intestazioni): int {
                if (str_contains($riga, ':')) {
                    [$k, $v] = explode(':', $riga, 2);
                    $intestazioni[strtolower(trim($k))] = trim($v);
                } elseif (preg_match('#^HTTP/\S+\s+\d+#', $riga)) {
                    $intestazioni = [];
                }
                return strlen($riga);
            },
            CURLOPT_WRITEFUNCTION => function($c, string $dati) use (&$corpo, &$troppo): int {
                if (strlen($corpo) + strlen($dati) > $this->massimo) {
                    $troppo = true;
                    return 0;
                }
                $corpo .= $dati;
                return strlen($dati);
            },
        ];
        if ($richiesta->metodo === 'HEAD') {
            $opzioni[CURLOPT_NOBODY] = true;
        }
        if ($richiesta->corpo !== null) {
            $opzioni[CURLOPT_POSTFIELDS] = $richiesta->corpo;
        }
        if ($this->ca !== null) {
            $opzioni[CURLOPT_CAINFO] = $this->ca;
        }
        if (!empty($this->proxy['indirizzo'])) {
            $opzioni[CURLOPT_PROXY] = $this->proxy['indirizzo'];
            if (!empty($this->proxy['utente'])) {
                $opzioni[CURLOPT_PROXYUSERPWD] = $this->proxy['utente'] . ':' . ($this->proxy['password'] ?? '');
            }
            if (!empty($this->proxy['senzaproxy'])) {
                $opzioni[CURLOPT_NOPROXY] = $this->proxy['senzaproxy'];
            }
        }
        curl_setopt_array($h, $opzioni);
        $inizio = microtime(true);
        $ok = curl_exec($h);
        $durata = microtime(true) - $inizio;
        $stato = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $numero = curl_errno($h);
        $testo = curl_error($h);
        curl_close($h);
        if ($troppo) {
            throw new ErroreConnettore('Risposta del servizio oltre la dimensione massima ammessa.', $stato);
        }
        if ($ok === false || $stato === 0) {
            throw new ErroreConnettore("errore di rete {$numero}: {$testo}", 0);
        }
        return new Risposta($stato, $intestazioni, $corpo, $durata);
    }
}
