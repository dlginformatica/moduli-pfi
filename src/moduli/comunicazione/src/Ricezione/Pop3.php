<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione\Ricezione;

use Pfi\Comunicazione\ErroreInvio;
use Pfi\Contratti\Eccezione;
use Pfi\Contratti\EsitoSalute;

/**
 * Casella POP3 (RFC 1939): TLS dall'inizio (porta 995) o STLS (110, RFC 2595); accesso con USER e PASS. POP3 non
 * conserva lo stato di lettura: i messaggi già trattati si riconoscono dal loro UIDL con $visti (insieme degli UIDL
 * già letti, conservato dall'applicazione) oppure si eliminano dal server con $cancella.
 */
final class Pop3 implements Casella {

    use Collegamento;

    /** @var list<string> UIDL letti nell'ultima ricezione, da conservare fra i visti */
    public array $letti = [];

    /**
     * @param \Closure(string):bool|null $visto vero se l'UIDL è già stato trattato
     */
    public function __construct(
        private readonly string $host,
        private readonly int $porta = 995,
        private readonly string $sicurezza = 'tls',
        private readonly string $utente = '',
        #[\SensitiveParameter] private readonly string $password = '',
        private readonly bool $cancella = false,
        private readonly ?\Closure $visto = null,
        private readonly float $attesa = 30.0,
        private readonly ?string $ca = null,
    ) {
        if (!in_array($sicurezza, ['tls', 'starttls'], true)) {
            throw new Eccezione('La casella POP3 richiede TLS o STLS.');
        }
        if (!$cancella && $visto === null) {
            throw new Eccezione('POP3: indicare come riconoscere i messaggi già letti oppure la cancellazione dal server.');
        }
    }

    public function ricevi(int $massimo = 50, bool $segna = true): array {
        $this->letti = [];
        try {
            $this->apri();
            $elenco = [];
            foreach ($this->multiriga('UIDL') as $r) {
                [$num, $uid] = explode(' ', trim($r), 2) + [1 => ''];
                $elenco[(int) $num] = $uid;
            }
            $fuori = [];
            foreach ($elenco as $num => $uid) {
                if (count($fuori) >= $massimo) {
                    break;
                }
                if ($this->visto !== null && ($this->visto)($uid)) {
                    continue;
                }
                $fuori[] = Ricevuto::da_mime($uid, implode("\r\n", $this->multiriga("RETR {$num}")) . "\r\n");
                $this->letti[] = $uid;
                if ($segna && $this->cancella) {
                    $this->ok("DELE {$num}");
                }
            }
            $this->ok('QUIT');
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
            $this->ok('QUIT');
            $this->chiudi();
            return EsitoSalute::buono('Casella POP3 raggiungibile e accesso riuscito.', ['server' => "{$this->host}:{$this->porta}"]);
        } catch (ErroreInvio $e) {
            $this->chiudi();
            return EsitoSalute::guasto($e->getMessage());
        }
    }

    private function apri(): void {
        $this->connetti($this->host, $this->porta, $this->sicurezza, $this->attesa, $this->ca);
        $this->risposta('saluto');
        if ($this->sicurezza === 'starttls') {
            $this->ok('STLS');
            $this->cifra();
        }
        if (preg_match('/[\r\n\0]/', $this->utente . $this->password)) {
            throw new ErroreInvio('Credenziali POP3 con caratteri di controllo.', false);
        }
        $this->ok('USER ' . $this->utente, 'accesso');
        $this->ok('PASS ' . $this->password, 'accesso');
    }

    private function ok(string $c, ?string $fase = null): string {
        $this->scrivi($c);
        return $this->risposta($fase ?? strtok($c, ' '));
    }

    private function risposta(string $fase): string {
        $r = $this->riga();
        if (!str_starts_with($r, '+OK')) {
            throw new ErroreInvio("Casella POP3, {$fase}: " . trim(substr($r, 0, 120)), false);
        }
        return $r;
    }

    /** @return list<string> righe di una risposta su più righe, con il punto iniziale ripristinato */
    private function multiriga(string $c): array {
        $this->ok($c);
        $righe = [];
        while (($r = $this->riga()) !== ".\r\n") {
            $r = rtrim($r, "\r\n");
            $righe[] = str_starts_with($r, '..') ? substr($r, 1) : $r;
        }
        return $righe;
    }
}
