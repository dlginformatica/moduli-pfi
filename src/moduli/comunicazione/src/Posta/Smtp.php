<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione\Posta;

use Pfi\Comunicazione\Canale;
use Pfi\Comunicazione\ErroreInvio;
use Pfi\Comunicazione\Invio;
use Pfi\Comunicazione\Messaggio;
use Pfi\Comunicazione\Mime;
use Pfi\Contratti\Eccezione;
use Pfi\Contratti\EsitoSalute;

/**
 * Posta in uscita con SMTP (RFC 5321) con server, porta, sicurezza, account e password (proposta 49 § 3.4).
 *
 * Sicurezza: «starttls» (porta 587, RFC 3207), «tls» (porta 465, TLS dall'inizio, RFC 8314), «nessuna» (solo per un
 * inoltro locale; l'autenticazione senza TLS si rifiuta). Il certificato del server si verifica sempre.
 * Autenticazione PLAIN o LOGIN (RFC 4954) se l'account è indicato. Le risposte 4xx sono errori temporanei, le 5xx
 * definitivi. La password non compare mai nel registro né nei messaggi d'errore.
 */
final class Smtp implements Canale {

    /** @var resource|null */
    private $flusso = null;

    /** @var list<string> */
    private array $estensioni = [];

    public function __construct(
        private readonly string $host,
        private readonly int $porta = 587,
        private readonly string $sicurezza = 'starttls',
        private readonly string $utente = '',
        #[\SensitiveParameter] private readonly string $password = '',
        private readonly float $attesa = 30.0,
        private readonly ?string $helo = null,
        private readonly ?string $ca = null,
    ) {
        if (!in_array($sicurezza, ['starttls', 'tls', 'nessuna'], true)) {
            throw new Eccezione("Sicurezza SMTP sconosciuta: «{$sicurezza}»");
        }
        if ($sicurezza === 'nessuna' && $utente !== '') {
            throw new Eccezione('Autenticazione SMTP senza TLS non ammessa: usare starttls o tls.');
        }
    }

    public function tipo(): string {
        return 'posta';
    }

    public function nome(): string {
        return 'posta smtp';
    }

    public function invia(Messaggio $m): Invio {
        [$dati] = Mime::componi($m);
        $destinatari = array_map(fn($i) => $i->valore, [...$m->destinatari, ...$m->cc, ...$m->ccn]);
        try {
            $this->apri();
            $utf8 = (bool) preg_match('/[^\x20-\x7e]/', implode('', $destinatari) . $m->mittente->valore);
            if ($utf8 && !in_array('SMTPUTF8', $this->estensioni, true)) {
                throw new ErroreInvio('Il server SMTP non accetta indirizzi con caratteri non ASCII (SMTPUTF8).', false);
            }
            $this->comando('MAIL FROM:<' . $m->mittente->valore . '>' . ($utf8 ? ' SMTPUTF8' : ''), [250]);
            foreach ($destinatari as $d) {
                $this->comando("RCPT TO:<{$d}>", [250, 251]);
            }
            $this->comando('DATA', [354]);
            $corpo = preg_replace('/^\./m', '..', $dati);
            $risposta = $this->comando($corpo . (str_ends_with($corpo, "\r\n") ? '' : "\r\n") . '.', [250]);
            $this->chiudi();
            $id = preg_match('/queued as\s+([A-Za-z0-9._@-]{4,})/i', $risposta, $x)
                || preg_match('/\bid=([A-Za-z0-9._@-]{4,})/i', $risposta, $x) ? $x[1] : '';
            return new Invio($id, substr($risposta, 0, 200));
        } catch (ErroreInvio $e) {
            $this->chiudi(true);
            throw $e;
        }
    }

    public function salute(): EsitoSalute {
        try {
            $this->apri();
            $this->chiudi();
            return EsitoSalute::buono('Server SMTP raggiungibile' . ($this->utente !== '' ? ' e autenticazione riuscita.' : '.'),
                ['server' => $this->host . ':' . $this->porta, 'sicurezza' => $this->sicurezza]);
        } catch (ErroreInvio $e) {
            $this->chiudi(true);
            return EsitoSalute::guasto($e->getMessage(), ['server' => $this->host . ':' . $this->porta]);
        }
    }

    private function apri(): void {
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host, 'SNI_enabled' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT];
        if ($this->ca !== null) {
            $ssl['cafile'] = $this->ca;
        }
        $schema = $this->sicurezza === 'tls' ? 'tls' : 'tcp';
        $f = @stream_socket_client("{$schema}://{$this->host}:{$this->porta}", $n, $e, $this->attesa, STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => $ssl]));
        if ($f === false) {
            throw new ErroreInvio("Server SMTP {$this->host}:{$this->porta} non raggiungibile: {$e}", true);
        }
        stream_set_timeout($f, (int) ceil($this->attesa));
        $this->flusso = $f;
        $this->risposta([220]);
        $this->ehlo();
        if ($this->sicurezza === 'starttls') {
            if (!in_array('STARTTLS', $this->estensioni, true)) {
                throw new ErroreInvio('Il server SMTP non offre STARTTLS.', false);
            }
            $this->comando('STARTTLS', [220]);
            stream_context_set_option($f, ['ssl' => $ssl]);
            if (!@stream_socket_enable_crypto($f, true, $ssl['crypto_method'])) {
                throw new ErroreInvio('Negoziazione TLS con il server SMTP non riuscita (certificato o protocollo).', false);
            }
            $this->ehlo();
        }
        if ($this->utente !== '') {
            $this->autentica();
        }
    }

    private function ehlo(): void {
        $nome = $this->helo ?? (gethostname() ?: 'localhost');
        $r = $this->comando('EHLO ' . preg_replace('/[^A-Za-z0-9.-]/', '', $nome), [250]);
        $this->estensioni = [];
        foreach (array_slice(explode("\n", $r), 1) as $riga) {
            $parole = preg_split('/\s+/', strtoupper(trim(substr($riga, 4))));
            if ($parole[0] === 'AUTH') {
                array_push($this->estensioni, ...array_map(fn($a) => 'AUTH=' . $a, array_slice($parole, 1)));
            }
            $this->estensioni[] = $parole[0];
        }
    }

    private function autentica(): void {
        if (in_array('AUTH=PLAIN', $this->estensioni, true)) {
            $this->comando('AUTH PLAIN ' . base64_encode("\0{$this->utente}\0{$this->password}"), [235], true);
        } elseif (in_array('AUTH=LOGIN', $this->estensioni, true)) {
            $this->comando('AUTH LOGIN', [334]);
            $this->comando(base64_encode($this->utente), [334], true);
            $this->comando(base64_encode($this->password), [235], true);
        } else {
            throw new ErroreInvio('Il server SMTP non offre un metodo di autenticazione supportato (PLAIN o LOGIN).', false);
        }
    }

    /** @param list<int> $attesi */
    private function comando(string $riga, array $attesi, bool $riservato = false): string {
        if (@fwrite($this->flusso, $riga . "\r\n") === false) {
            throw new ErroreInvio('Connessione SMTP interrotta.', true);
        }
        return $this->risposta($attesi, $riservato ? 'autenticazione' : strtok($riga, " \r\n"));
    }

    /** @param list<int> $attesi */
    private function risposta(array $attesi, string $fase = 'connessione'): string {
        $testo = '';
        while (true) {
            $riga = fgets($this->flusso, 4096);
            if ($riga === false) {
                $meta = stream_get_meta_data($this->flusso);
                throw new ErroreInvio('Server SMTP: ' . ($meta['timed_out'] ? 'tempo scaduto' : 'connessione chiusa') . " durante {$fase}.", true);
            }
            $testo .= $riga;
            if (strlen($riga) < 4 || $riga[3] !== '-') {
                break;
            }
        }
        $codice = (int) substr($testo, 0, 3);
        if (!in_array($codice, $attesi, true)) {
            $breve = trim(preg_replace('/\s+/', ' ', substr($testo, 0, 200)));
            if ($fase === 'autenticazione') {
                throw new ErroreInvio("Server SMTP: autenticazione rifiutata ({$codice}).", $codice >= 400 && $codice < 500, (string) $codice);
            }
            throw new ErroreInvio("Server SMTP, {$fase}: {$breve}", $codice >= 400 && $codice < 500, (string) $codice);
        }
        return $testo;
    }

    private function chiudi(bool $subito = false): void {
        if ($this->flusso !== null) {
            if (!$subito) {
                @fwrite($this->flusso, "QUIT\r\n");
                @fgets($this->flusso, 512);
            }
            @fclose($this->flusso);
            $this->flusso = null;
        }
    }
}
