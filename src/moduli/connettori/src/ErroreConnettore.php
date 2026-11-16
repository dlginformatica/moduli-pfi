<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Connettori;

use Pfi\Contratti\Eccezione;

/**
 * Errore di un connettore: di rete (stato 0) o risposta non riuscita dopo i tentativi. Il messaggio non contiene il corpo
 * della risposta, che può riportare dati personali o segreti; il corpo resta disponibile al chiamante in $risposta.
 */
final class ErroreConnettore extends Eccezione {

    public function __construct(string $messaggio, public readonly int $stato = 0, public readonly ?Risposta $risposta = null) {
        parent::__construct($messaggio);
    }

    public function di_rete(): bool {
        return $this->stato === 0;
    }
}
