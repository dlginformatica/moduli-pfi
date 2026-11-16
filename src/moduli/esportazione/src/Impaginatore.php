<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esportazione;

/** Motore che trasforma un documento HTML in PDF; lo fornisce pfi/stampa (Chromium senza interfaccia, D-161 c). */
interface Impaginatore {

    /** @return string contenuto del PDF */
    public function pdf(string $html): string;
}
