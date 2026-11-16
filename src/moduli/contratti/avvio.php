<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

// Avvio senza Composer: carica il caricatore e registra pfi/contratti stesso. Uso:
//   require '/usr/local/lib/pfi/moduli/contratti/attuale/avvio.php';
//   \Pfi\Contratti\Caricatore::registra('/usr/local/lib/pfi/moduli');

declare(strict_types=1);

require_once(__DIR__ . '/src/Eccezione.php');
require_once(__DIR__ . '/src/Versione.php');
require_once(__DIR__ . '/src/Manifesto.php');
require_once(__DIR__ . '/src/Caricatore.php');
\Pfi\Contratti\Caricatore::registra_cartella(__DIR__);
