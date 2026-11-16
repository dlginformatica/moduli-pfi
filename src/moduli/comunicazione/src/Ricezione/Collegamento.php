<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione\Ricezione;

use Pfi\Comunicazione\ErroreInvio;

/** Connessione TCP con TLS dall'inizio o dopo il comando di passaggio a TLS, con verifica del certificato. */
trait Collegamento {

    /** @var resource|null */
    private $flusso = null;

    private function connetti(string $host, int $porta, string $sicurezza, float $attesa, ?string $ca): void {
        $this->ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT] + ($ca ? ['cafile' => $ca] : []);
        $f = @stream_socket_client(($sicurezza === 'tls' ? 'tls' : 'tcp') . "://{$host}:{$porta}", $n, $e, $attesa,
            STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $this->ssl]));
        if ($f === false) {
            throw new ErroreInvio("Casella {$host}:{$porta} non raggiungibile: {$e}", true);
        }
        stream_set_timeout($f, (int) ceil($attesa));
        $this->flusso = $f;
    }

    private array $ssl = [];

    private function cifra(): void {
        stream_context_set_option($this->flusso, ['ssl' => $this->ssl]);
        if (!@stream_socket_enable_crypto($this->flusso, true, $this->ssl['crypto_method'])) {
            throw new ErroreInvio('Negoziazione TLS con la casella non riuscita (certificato o protocollo).', false);
        }
    }

    private function riga(): string {
        $r = fgets($this->flusso, 65536);
        if ($r === false) {
            throw new ErroreInvio('Connessione con la casella interrotta o tempo scaduto.', true);
        }
        return $r;
    }

    private function scrivi(string $s): void {
        if (@fwrite($this->flusso, $s . "\r\n") === false) {
            throw new ErroreInvio('Connessione con la casella interrotta.', true);
        }
    }

    private function chiudi(): void {
        if ($this->flusso) {
            @fclose($this->flusso);
            $this->flusso = null;
        }
    }
}
