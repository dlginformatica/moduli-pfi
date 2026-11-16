<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Connettori;

use Pfi\Contratti\Eccezione;

/** Richiesta HTTP verso un servizio esterno. Il corpo è già codificato; json() lo prepara da un array. */
final class Richiesta {

    /**
     * @param array<string,string> $intestazioni
     */
    public function __construct(
        public readonly string $metodo,
        public readonly string $url,
        public readonly array $intestazioni = [],
        public readonly ?string $corpo = null,
        public readonly ?float $attesa = null,
    ) {
        if (!in_array($metodo, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) {
            throw new Eccezione("Metodo HTTP non ammesso: «{$metodo}»");
        }
        $schema = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($schema, ['https', 'http'], true) || !parse_url($url, PHP_URL_HOST)) {
            throw new Eccezione('Indirizzo della richiesta non valido.');
        }
    }

    public static function json(string $metodo, string $url, array $dati, array $intestazioni = []): self {
        return new self($metodo, $url, ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $intestazioni,
            json_encode($dati, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function modulo(string $metodo, string $url, array $campi, array $intestazioni = []): self {
        return new self($metodo, $url, ['Content-Type' => 'application/x-www-form-urlencoded'] + $intestazioni,
            http_build_query($campi, '', '&', PHP_QUERY_RFC3986));
    }

    /** Stessa richiesta con un'intestazione in più o sostituita. */
    public function con_intestazione(string $nome, string $valore): self {
        $i = $this->intestazioni;
        foreach (array_keys($i) as $k) {
            if (strcasecmp($k, $nome) === 0) {
                unset($i[$k]);
            }
        }
        $i[$nome] = $valore;
        return new self($this->metodo, $this->url, $i, $this->corpo, $this->attesa);
    }

    /**
     * Indirizzo per il registro: schema, host e percorso, senza credenziali né parametri; i tratti del percorso che
     * sembrano chiavi (almeno 24 caratteri di lettere, cifre e «:_-», come il token di Telegram) diventano «…».
     */
    public function url_per_registro(): string {
        $p = parse_url($this->url);
        $percorso = preg_replace('~(?<=/)[^/]*[A-Za-z0-9:_-]{24,}[^/]*~', '…', $p['path'] ?? '/');
        return ($p['scheme'] ?? '') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '')
            . $percorso . (isset($p['query']) ? '?…' : '');
    }
}
