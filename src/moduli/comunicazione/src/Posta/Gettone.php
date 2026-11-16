<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione\Posta;

use Pfi\Comunicazione\ErroreInvio;
use Pfi\Connettori\Connettore;
use Pfi\Connettori\ErroreConnettore;
use Pfi\Connettori\Richiesta;

/**
 * Gettone di accesso OAuth 2.0 con le credenziali del client (RFC 6749 § 4.4) o con un'asserzione JWT firmata
 * (RFC 7523, conto di servizio Google), conservato nel processo fino a un minuto prima della scadenza.
 */
final class Gettone {

    private ?string $valore = null;
    private int $scade = 0;

    /**
     * @param array<string,string> $campi campi del modulo per l'indirizzo del gettone
     * @param \Closure():array<string,string>|null $calcola campi calcolati a ogni richiesta (asserzione JWT)
     */
    private function __construct(
        private readonly Connettore $connettore,
        private readonly string $url,
        #[\SensitiveParameter] private readonly array $campi,
        private readonly ?\Closure $calcola = null,
    ) {
    }

    /** Microsoft identity platform, credenziali del client per Microsoft Graph. */
    public static function microsoft(Connettore $c, string $tenant, string $client, #[\SensitiveParameter] string $segreto,
        string $ambito = 'https://graph.microsoft.com/.default'): self {
        if (!preg_match('/^[A-Za-z0-9.-]{1,100}$/', $tenant)) {
            throw new ErroreInvio('Tenant Microsoft non valido.', false);
        }
        return new self($c, "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
            ['grant_type' => 'client_credentials', 'client_id' => $client, 'client_secret' => $segreto, 'scope' => $ambito]);
    }

    /**
     * Conto di servizio Google con delega a livello di dominio: il JWT firmato RS256 impersona $utente.
     *
     * @param string $chiavejson contenuto del file JSON della chiave del conto di servizio
     */
    public static function google(Connettore $c, #[\SensitiveParameter] string $chiavejson, string $utente, string $ambito): self {
        $k = json_decode($chiavejson, true);
        if (!is_array($k) || empty($k['client_email']) || empty($k['private_key'])) {
            throw new ErroreInvio('Chiave del conto di servizio Google non valida.', false);
        }
        $url = $k['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $calcola = function() use ($k, $utente, $ambito, $url): array {
            $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $adesso = time();
            $testo = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode(['iss' => $k['client_email'],
                'sub' => $utente, 'scope' => $ambito, 'aud' => $url, 'iat' => $adesso, 'exp' => $adesso + 3600]));
            $chiave = openssl_pkey_get_private($k['private_key']);
            if ($chiave === false || !openssl_sign($testo, $firma, $chiave, OPENSSL_ALGO_SHA256)) {
                throw new ErroreInvio('Firma del JWT del conto di servizio non riuscita.', false);
            }
            return ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $testo . '.' . $b64($firma)];
        };
        return new self($c, $url, [], $calcola);
    }

    public function valore(): string {
        if ($this->valore !== null && time() < $this->scade - 60) {
            return $this->valore;
        }
        $campi = $this->campi + ($this->calcola ? ($this->calcola)() : []);
        try {
            $r = $this->connettore->invia(Richiesta::modulo('POST', $this->url, $campi));
        } catch (ErroreConnettore $e) {
            throw new ErroreInvio('Gettone di accesso non ottenuto (' . ($e->stato ?: 'rete') . ').',
                $e->stato === 0 || $e->stato >= 500 || $e->stato === 429, (string) $e->stato);
        }
        $d = json_decode($r->corpo, true);
        if (!is_array($d) || empty($d['access_token'])) {
            throw new ErroreInvio('Risposta del servizio dei gettoni senza access_token.', true);
        }
        $this->valore = (string) $d['access_token'];
        $this->scade = time() + (int) ($d['expires_in'] ?? 3600);
        return $this->valore;
    }
}
