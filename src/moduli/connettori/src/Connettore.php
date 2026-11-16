<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Connettori;

use Pfi\Contratti\Registro;
use Pfi\Contratti\RegistroNullo;

/**
 * Connettore verso un servizio esterno (release 9.0, blocco M2a; proposta 49 § 3.10): un nome per il registro, il
 * trasporto, la politica dei tentativi, il limite di frequenza facoltativo. Ogni richiesta è registrata con metodo,
 * indirizzo senza parametri, esito, durata e tentativi; mai intestazioni né corpi.
 */
final class Connettore {

    private Registro $registro;

    /** @var \Closure(float):void */
    private \Closure $dormi;

    public function __construct(
        private readonly string $nome,
        private readonly Trasporto $trasporto,
        private readonly Politica $politica = new Politica(),
        private readonly ?Limite $limite = null,
        ?Registro $registro = null,
        ?\Closure $dormi = null,
    ) {
        $this->registro = $registro ?? new RegistroNullo();
        $this->dormi = $dormi ?? function(float $s): void {
            if ($s > 0) {
                usleep((int) round($s * 1e6));
            }
        };
    }

    public function nome(): string {
        return $this->nome;
    }

    /**
     * Invia la richiesta con i tentativi della politica. Restituisce la risposta riuscita (2xx); con $accettati si
     * restituiscono anche altri stati (per esempio 404 per «non trovato»). Altrimenti ErroreConnettore.
     *
     * @param list<int> $accettati
     */
    public function invia(Richiesta $richiesta, array $accettati = []): Risposta {
        $inizio = microtime(true);
        $risposta = null;
        $errore = null;
        for ($n = 1; $n <= $this->politica->tentativi; $n++) {
            if ($n > 1) {
                ($this->dormi)($this->politica->attesa($n, $risposta));
            }
            if ($this->limite !== null) {
                ($this->dormi)($this->limite->prendi());
            }
            try {
                $risposta = $this->trasporto->invia($richiesta);
                $errore = null;
            } catch (ErroreConnettore $e) {
                $risposta = null;
                $errore = $e;
            }
            if ($risposta !== null && ($risposta->riuscita() || in_array($risposta->stato, $accettati, true))) {
                $this->annota(Registro::INFORMAZIONE, $richiesta, $risposta->stato, $n, $inizio);
                return $risposta->con_tentativi($n);
            }
            if (!$this->politica->ritentabile($richiesta, $risposta)) {
                break;
            }
        }
        $stato = $risposta?->stato ?? 0;
        $this->annota(Registro::ERRORE, $richiesta, $stato, min($n, $this->politica->tentativi), $inizio);
        if ($risposta === null) {
            throw new ErroreConnettore("{$this->nome}: servizio non raggiungibile ("
                . ($errore?->getMessage() ?? 'errore di rete') . ').', 0);
        }
        throw new ErroreConnettore("{$this->nome}: il servizio ha risposto {$stato}.", $stato, $risposta);
    }

    private function annota(string $livello, Richiesta $r, int $stato, int $tentativi, float $inizio): void {
        $this->registro->registra($livello, '{connettore}: {metodo} {indirizzo} → {stato} in {millisecondi} ms ({tentativi} tentativi)', [
            'modulo' => 'pfi/connettori', 'connettore' => $this->nome, 'metodo' => $r->metodo,
            'indirizzo' => $r->url_per_registro(), 'stato' => $stato, 'tentativi' => $tentativi,
            'millisecondi' => (int) round((microtime(true) - $inizio) * 1000)]);
    }
}
