<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

// Avvio delle prove: pfi/contratti dalla cartella dei moduli del progetto, oppure da PFI_MODULI.
declare(strict_types=1);

$base = getenv('PFI_MODULI') ?: dirname(__DIR__, 2);
require_once($base . '/contratti/avvio.php');
\Pfi\Contratti\Caricatore::registra_cartella(dirname(__DIR__));
\Pfi\Contratti\Caricatore::registra_cartella($base . '/diagnostica');
