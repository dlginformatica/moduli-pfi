<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Connettori;

/** Trasporto delle richieste: cURL in esercizio, Finto nelle prove. ErroreConnettore con stato 0 per gli errori di rete. */
interface Trasporto {

    public function invia(Richiesta $richiesta): Risposta;
}
