<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione\Ricezione;

use Pfi\Comunicazione\ErroreInvio;
use Pfi\Comunicazione\Posta\Gettone;
use Pfi\Contratti\Eccezione;
use Pfi\Contratti\EsitoSalute;

/**
 * Casella IMAP4rev1 (RFC 3501): TLS dall'inizio (porta 993) o STARTTLS (143); accesso con account e password (LOGIN)
 * oppure con gettone OAuth 2.0 (AUTHENTICATE XOAUTH2), che serve per Microsoft 365 e Google Workspace, dove l'accesso
 * con password è disattivato [DA VERIFICARE: ambiti dei gettoni per IMAP presso i due fornitori]. Legge i messaggi non
 * letti della cartella con UID SEARCH UNSEEN e BODY.PEEK[], poi li segna letti.
 */
final class Imap implements Casella {

    use Collegamento;

    private int $n = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $porta = 993,
        private readonly string $sicurezza = 'tls',
        private readonly string $utente = '',
        #[\SensitiveParameter] private readonly string $password = '',
        private readonly ?Gettone $gettone = null,
        private readonly string $cartella = 'INBOX',
        private readonly float $attesa = 30.0,
        private readonly ?string $ca = null,
    ) {
        if (!in_array($sicurezza, ['tls', 'starttls'], true)) {
            throw new Eccezione('La casella IMAP richiede TLS o STARTTLS.');
        }
    }

    public function ricevi(int $massimo = 50, bool $segna = true): array {
        try {
            $this->apri();
            $this->comando('SELECT ' . self::stringa($this->cartella));
            $uid = [];
            foreach ($this->comando('UID SEARCH UNSEEN') as $r) {
                if (preg_match('/^\* SEARCH ?([\d ]*)/', $r, $m)) {
                    array_push($uid, ...array_filter(explode(' ', trim($m[1])), 'strlen'));
                }
            }
            $fuori = [];
            foreach (array_slice($uid, 0, $massimo) as $u) {
                $dati = '';
                foreach ($this->comando("UID FETCH {$u} (BODY.PEEK[])") as $r) {
                    if (is_array($r)) {
                        $dati = $r[1];
                    }
                }
                $fuori[] = Ricevuto::da_mime($u, $dati);
                if ($segna) {
                    $this->comando("UID STORE {$u} +FLAGS.SILENT (\\Seen)");
                }
            }
            $this->comando('LOGOUT', false);
            $this->chiudi();
            return $fuori;
        } catch (ErroreInvio $e) {
            $this->chiudi();
            throw $e;
        }
    }

    public function salute(): EsitoSalute {
        try {
            $this->apri();
            $this->comando('SELECT ' . self::stringa($this->cartella));
            $this->comando('LOGOUT', false);
            $this->chiudi();
            return EsitoSalute::buono('Casella IMAP raggiungibile e accesso riuscito.', ['server' => "{$this->host}:{$this->porta}"]);
        } catch (ErroreInvio $e) {
            $this->chiudi();
            return EsitoSalute::guasto($e->getMessage());
        }
    }

    private function apri(): void {
        $this->connetti($this->host, $this->porta, $this->sicurezza, $this->attesa, $this->ca);
        if (!str_starts_with($this->riga(), '* OK')) {
            throw new ErroreInvio('Saluto IMAP inatteso.', true);
        }
        if ($this->sicurezza === 'starttls') {
            $this->comando('STARTTLS');
            $this->cifra();
        }
        if ($this->gettone !== null) {
            $x = base64_encode("user={$this->utente}\x01auth=Bearer {$this->gettone->valore()}\x01\x01");
            $this->comando("AUTHENTICATE XOAUTH2 {$x}", true, true);
        } else {
            $this->comando('LOGIN ' . self::stringa($this->utente) . ' ' . self::stringa($this->password), true, true);
        }
    }

    /** Stringa IMAP tra virgolette con «\» e «"» protetti; i caratteri di controllo non sono ammessi. */
    private static function stringa(string $s): string {
        if (preg_match('/[\r\n\0]/', $s)) {
            throw new ErroreInvio('Valore con caratteri di controllo per IMAP.', false);
        }
        return '"' . addcslashes($s, '"\\') . '"';
    }

    /**
     * Invia un comando con etichetta e restituisce le risposte non etichettate; un letterale {n} torna come
     * [riga, dati].
     *
     * @return list<string|array{0:string,1:string}>
     */
    private function comando(string $c, bool $controlla = true, bool $riservato = false): array {
        $tag = 'P' . (++$this->n);
        $this->scrivi("{$tag} {$c}");
        $fuori = [];
        while (true) {
            $r = $this->riga();
            if (str_starts_with($r, "{$tag} ")) {
                if ($controlla && !preg_match("/^{$tag} OK/i", $r)) {
                    $verbo = $riservato ? 'accesso' : strtok($c, ' ');
                    throw new ErroreInvio("Casella IMAP, {$verbo}: " . trim(substr($r, strlen($tag) + 1, 120)), str_contains($r, ' NO [UNAVAILABLE]'));
                }
                return $fuori;
            }
            if (str_starts_with($r, '+')) {
                $this->scrivi('');
                continue;
            }
            if (preg_match('/\{(\d+)\}\r\n$/', $r, $m)) {
                $dati = '';
                $n = (int) $m[1];
                while (strlen($dati) < $n) {
                    $pezzo = fread($this->flusso, min(65536, $n - strlen($dati)));
                    if ($pezzo === false || $pezzo === '') {
                        throw new ErroreInvio('Messaggio IMAP troncato.', true);
                    }
                    $dati .= $pezzo;
                }
                $fuori[] = [$r, $dati];
                continue;
            }
            $fuori[] = $r;
        }
    }
}
