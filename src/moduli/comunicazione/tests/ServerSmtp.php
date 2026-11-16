<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione\Prove;

/**
 * Server SMTP finto per le prove, in un processo figlio: EHLO, STARTTLS con certificato autofirmato, AUTH PLAIN,
 * MAIL, RCPT (rifiuta gli indirizzi che iniziano con «rifiuta» con 550 e quelli con «pieno» con 452), DATA.
 * Scrive in un file la trascrizione e i messaggi ricevuti.
 */
final class ServerSmtp {

    public int $porta;
    private int $pid;
    public string $trascrizione;

    public function __construct(string $cartella, string $cert, string $chiave, bool $starttls = true, string $password = 'Segreta!1') {
        $this->trascrizione = $cartella . '/smtp.txt';
        $ctx = stream_context_create(['ssl' => ['local_cert' => $cert, 'local_pk' => $chiave]]);
        $s = stream_socket_server('tcp://127.0.0.1:0', $n, $e, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
        $this->porta = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        $pid = pcntl_fork();
        if ($pid > 0) {
            $this->pid = $pid;
            fclose($s);
            return;
        }
        while ($c = @stream_socket_accept($s, 5)) {
            $this->sessione($c, $starttls, $password);
        }
        exit(0);
    }

    private function scrivi(string $t): void {
        file_put_contents($this->trascrizione, $t, FILE_APPEND);
    }

    private function sessione($c, bool $starttls, string $password): void {
        $tx = function(string $r) use ($c) {
            fwrite($c, $r . "\r\n");
        };
        $tx('220 prova ESMTP');
        $tls = false;
        while (($riga = fgets($c)) !== false) {
            $cmd = strtoupper(substr(trim($riga), 0, 4));
            $this->scrivi('C: ' . (str_starts_with(strtoupper($riga), 'AUTH') ? 'AUTH …' : trim($riga)) . "\n");
            if ($cmd === 'EHLO') {
                $tx('250-prova');
                $tx('250-SMTPUTF8');
                if ($starttls && !$tls) {
                    $tx('250-STARTTLS');
                }
                $tx('250 AUTH PLAIN LOGIN');
            } elseif ($cmd === 'STAR') {
                $tx('220 pronto');
                stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
                $tls = true;
            } elseif ($cmd === 'AUTH') {
                $dati = base64_decode(substr(trim($riga), 11));
                $tx($dati === "\0utente\0{$password}" ? '235 ok' : '535 credenziali errate');
            } elseif ($cmd === 'MAIL') {
                $tx('250 ok');
            } elseif ($cmd === 'RCPT') {
                $tx(str_contains($riga, '<rifiuta') ? '550 sconosciuto' : (str_contains($riga, '<pieno') ? '452 casella piena' : '250 ok'));
            } elseif ($cmd === 'DATA') {
                $tx('354 avanti');
                $msg = '';
                while (($l = fgets($c)) !== false && $l !== ".\r\n") {
                    $msg .= $l;
                }
                $this->scrivi("MESSAGGIO\n" . $msg . "FINE\n");
                $tx('250 2.0.0 Ok: queued as ABC123XYZ');
            } elseif ($cmd === 'QUIT') {
                $tx('221 ciao');
                break;
            } else {
                $tx('502 ?');
            }
        }
        fclose($c);
    }

    public function ferma(): void {
        posix_kill($this->pid, SIGTERM);
        pcntl_waitpid($this->pid, $st);
    }
}
