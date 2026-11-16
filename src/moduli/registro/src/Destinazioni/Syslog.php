<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Registro\Destinazioni;

use Pfi\Contratti\Eccezione;
use Pfi\Contratti\EsitoSalute;
use Pfi\Registro\Destinazione;
use Pfi\Registro\Rfc5424;
use Pfi\Registro\Voce;

/**
 * Syslog secondo RFC 5424 (proposta 49 § 3.3).
 *
 * Trasporti: «udp» (RFC 5426, un evento per datagramma, senza conferma), «tcp» e «tls» (RFC 6587 e RFC 5425, con il
 * conteggio dei byte davanti a ogni evento: «LUNGHEZZA SP EVENTO»), «locale» (funzione syslog() di PHP verso il demone
 * del server, che aggiunge la propria intestazione). Con TLS il certificato del server si verifica sempre; si può
 * indicare un file di autorità e un certificato del client.
 *
 * Una connessione TCP o TLS caduta si riapre una volta al successivo evento; se non riapre, l'evento si perde e
 * l'errore torna al registro, che prosegue con le altre destinazioni.
 */
final class Syslog implements Destinazione {

    /** @var resource|null */
    private $flusso = null;

    /**
     * @param array{ca?:string,certificato?:string,chiave?:string,nome?:string} $tls
     */
    public function __construct(
        private readonly string $trasporto,
        private readonly string $host = '127.0.0.1',
        private readonly int $porta = 514,
        private readonly Rfc5424 $formato = new Rfc5424(),
        private readonly float $attesa = 2.0,
        private readonly array $tls = [],
    ) {
        if (!in_array($trasporto, ['udp', 'tcp', 'tls', 'locale'], true)) {
            throw new Eccezione("Trasporto syslog sconosciuto: «{$trasporto}»");
        }
        if ($porta < 1 || $porta > 65535) {
            throw new Eccezione('Porta syslog non valida.');
        }
    }

    public function nome(): string {
        return 'syslog ' . $this->trasporto;
    }

    public function scrivi(Voce $voce): void {
        if ($this->trasporto === 'locale') {
            $this->locale($voce);
            return;
        }
        $evento = $this->formato->formatta($voce);
        if ($this->trasporto === 'udp') {
            $dati = substr($evento, 0, 8192);
        } else {
            $dati = strlen($evento) . ' ' . $evento;
        }
        for ($tentativo = 0; $tentativo < 2; $tentativo++) {
            $f = $this->apri();
            $scritti = @fwrite($f, $dati);
            if ($scritti === strlen($dati)) {
                return;
            }
            $this->chiudi();
        }
        throw new Eccezione("Evento non inviato al syslog {$this->trasporto} {$this->host}:{$this->porta}.");
    }

    private function locale(Voce $voce): void {
        openlog($this->formato->app(), LOG_PID | LOG_ODELAY, Rfc5424::STRUTTURE[$this->formato->struttura()] * 8);
        $testo = '[' . $voce->modulo . ' ' . $voce->correlazione . '] ' . $voce->messaggio;
        if ($voce->contesto) {
            $testo .= ' ' . json_encode($voce->contesto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (!syslog($voce->gravita(), $testo)) {
            throw new Eccezione('Evento non inviato al syslog locale.');
        }
    }

    /** @return resource */
    private function apri() {
        if ($this->flusso !== null && !feof($this->flusso)) {
            return $this->flusso;
        }
        $opzioni = [];
        $schema = $this->trasporto;
        if ($this->trasporto === 'tls') {
            $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
                'peer_name' => $this->tls['nome'] ?? $this->host, 'SNI_enabled' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT];
            if (!empty($this->tls['ca'])) {
                $ssl['cafile'] = $this->tls['ca'];
            }
            if (!empty($this->tls['certificato'])) {
                $ssl['local_cert'] = $this->tls['certificato'];
                if (!empty($this->tls['chiave'])) {
                    $ssl['local_pk'] = $this->tls['chiave'];
                }
            }
            $opzioni['ssl'] = $ssl;
        }
        $indirizzo = $schema . '://' . (str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host) . ':' . $this->porta;
        $f = @stream_socket_client($indirizzo, $codice, $errore, $this->attesa, STREAM_CLIENT_CONNECT,
            stream_context_create($opzioni));
        if ($f === false) {
            throw new Eccezione("Connessione al syslog {$this->trasporto} {$this->host}:{$this->porta} non riuscita: {$errore}");
        }
        stream_set_timeout($f, (int) ceil($this->attesa));
        return $this->flusso = $f;
    }

    public function chiudi(): void {
        if ($this->flusso !== null) {
            @fclose($this->flusso);
            $this->flusso = null;
        }
    }

    public function __destruct() {
        $this->chiudi();
    }

    public function salute(): EsitoSalute {
        $dettagli = ['trasporto' => $this->trasporto, 'destinazione' => $this->host . ':' . $this->porta];
        if ($this->trasporto === 'locale') {
            return EsitoSalute::buono('Syslog del server.', ['trasporto' => 'locale']);
        }
        if ($this->trasporto === 'udp') {
            return EsitoSalute::avviso('Syslog su UDP: gli eventi si inviano senza conferma di ricezione.', $dettagli);
        }
        try {
            $this->apri();
            return EsitoSalute::buono('Syslog raggiungibile.', $dettagli);
        } catch (\Throwable $e) {
            return EsitoSalute::guasto('Syslog non raggiungibile.', $dettagli);
        }
    }
}
