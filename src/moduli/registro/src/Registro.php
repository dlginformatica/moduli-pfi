<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Registro;

use Pfi\Contratti\Eccezione;
use Pfi\Contratti\Registro as ContrattoRegistro;

/**
 * Registro dei moduli universali (release 9.0, blocco M2; proposta 49 § 3.3).
 *
 * Implementa l'interfaccia Registro di pfi/contratti con i metodi di PSR-3; ogni destinazione ha un livello minimo;
 * il contesto e il messaggio passano dal mascheratore prima di qualsiasi destinazione; l'identificativo di correlazione
 * segue un'operazione fra più moduli. Una destinazione che fallisce non ferma le altre né l'applicazione.
 */
final class Registro implements ContrattoRegistro {

    /** @var list<array{0:Destinazione,1:int}> */
    private array $destinazioni = [];

    private string $correlazione;

    /** @var \Closure():\DateTimeImmutable */
    private \Closure $orologio;

    /** @var list<string> */
    private array $guasti = [];

    public function __construct(
        private readonly Mascheratore $mascheratore = new Mascheratore(),
        private string $modulo = 'applicazione',
        ?string $correlazione = null,
        ?\Closure $orologio = null,
    ) {
        $this->correlazione = $correlazione ?? self::nuova_correlazione();
        $this->orologio = $orologio ?? fn() => new \DateTimeImmutable('now');
    }

    /** Identificativo casuale di 16 caratteri esadecimali. */
    public static function nuova_correlazione(): string {
        return bin2hex(random_bytes(8));
    }

    public function aggiungi(Destinazione $destinazione, string $livellominimo = self::DEBUG): self {
        if (!isset(self::LIVELLI[$livellominimo])) {
            throw new Eccezione("Livello sconosciuto: «{$livellominimo}»");
        }
        $this->destinazioni[] = [$destinazione, self::LIVELLI[$livellominimo]];
        return $this;
    }

    /** Stesso registro e stesse destinazioni, con un altro modulo predefinito (per passarlo a un modulo). */
    public function per_modulo(string $modulo): self {
        $r = clone $this;
        $r->modulo = $modulo;
        return $r;
    }

    /** Stesso registro con un altro identificativo di correlazione (una nuova operazione). */
    public function con_correlazione(?string $correlazione = null): self {
        $r = clone $this;
        $r->correlazione = $correlazione ?? self::nuova_correlazione();
        return $r;
    }

    public function correlazione(): string {
        return $this->correlazione;
    }

    public function mascheratore(): Mascheratore {
        return $this->mascheratore;
    }

    /** @return list<Destinazione> */
    public function destinazioni(): array {
        return array_map(fn($d) => $d[0], $this->destinazioni);
    }

    /** Messaggi degli errori delle destinazioni dall'avvio (senza segreti), per la diagnostica. */
    public function guasti(): array {
        return $this->guasti;
    }

    public function registra(string $livello, string $messaggio, array $contesto = []): void {
        if (!isset(self::LIVELLI[$livello])) {
            throw new Eccezione("Livello sconosciuto: «{$livello}»");
        }
        $gravita = self::LIVELLI[$livello];
        $attive = array_filter($this->destinazioni, fn($d) => $gravita <= $d[1]);
        if (!$attive) {
            return;
        }
        $modulo = isset($contesto['modulo']) && is_string($contesto['modulo']) ? $contesto['modulo'] : $this->modulo;
        $correlazione = isset($contesto['correlazione']) && is_string($contesto['correlazione'])
            ? $contesto['correlazione'] : $this->correlazione;
        unset($contesto['modulo'], $contesto['correlazione']);
        $pulito = $this->mascheratore->contesto($contesto);
        $voce = new Voce(($this->orologio)(), $livello, $this->mascheratore->testo(self::interpola($messaggio, $pulito)),
            $modulo, $correlazione, $pulito);
        foreach ($attive as [$destinazione]) {
            try {
                $destinazione->scrivi($voce);
            } catch (\Throwable $e) {
                $this->guasti[] = $destinazione->nome() . ': ' . $this->mascheratore->testo($e->getMessage());
                $this->guasti = array_slice($this->guasti, -20);
            }
        }
    }

    /** @param array<string,mixed> $contesto */
    public static function interpola(string $messaggio, array $contesto): string {
        return preg_replace_callback('/\{([A-Za-z0-9_.]+)\}/', function($m) use ($contesto) {
            if (!array_key_exists($m[1], $contesto)) {
                return $m[0];
            }
            $v = $contesto[$m[1]];
            return match (true) {
                $v === null => 'null',
                is_bool($v) => $v ? 'true' : 'false',
                is_scalar($v) => (string) $v,
                default => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            };
        }, $messaggio);
    }

    public function emergency(string|\Stringable $m, array $c = []): void {
        $this->registra(self::EMERGENZA, (string) $m, $c);
    }

    public function alert(string|\Stringable $m, array $c = []): void {
        $this->registra(self::ALLARME, (string) $m, $c);
    }

    public function critical(string|\Stringable $m, array $c = []): void {
        $this->registra(self::CRITICO, (string) $m, $c);
    }

    public function error(string|\Stringable $m, array $c = []): void {
        $this->registra(self::ERRORE, (string) $m, $c);
    }

    public function warning(string|\Stringable $m, array $c = []): void {
        $this->registra(self::AVVISO, (string) $m, $c);
    }

    public function notice(string|\Stringable $m, array $c = []): void {
        $this->registra(self::NOTA, (string) $m, $c);
    }

    public function info(string|\Stringable $m, array $c = []): void {
        $this->registra(self::INFORMAZIONE, (string) $m, $c);
    }

    public function debug(string|\Stringable $m, array $c = []): void {
        $this->registra(self::DEBUG, (string) $m, $c);
    }

    /** Forma di PSR-3: log($livello, $messaggio, $contesto). */
    public function log(mixed $livello, string|\Stringable $m, array $c = []): void {
        $this->registra((string) $livello, (string) $m, $c);
    }
}
