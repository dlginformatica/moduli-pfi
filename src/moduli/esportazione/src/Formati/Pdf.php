<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esportazione\Formati;

use Pfi\Contratti\Eccezione;
use Pfi\Esportazione\Base;
use Pfi\Esportazione\Impaginatore;

/** PDF: la tabella del formato HTML impaginata dal motore di stampa (pfi/stampa); senza motore il formato non c'è. */
final class Pdf extends Base {

    private Html $html;

    /** @var resource|null */
    private $buffer = null;

    public function __construct(private readonly Impaginatore $impaginatore) {
        $this->html = new Html();
    }

    public function nome(): string {
        return 'pdf';
    }

    public function estensione(): string {
        return 'pdf';
    }

    public function tipo(): string {
        return 'application/pdf';
    }

    protected function apri(): void {
        $this->buffer = fopen('php://temp/maxmemory:8388608', 'w+');
        $this->html->inizia($this->buffer, $this->colonne, $this->titolo);
    }

    protected function scrivi_riga(array $valori): void {
        $this->html->riga($valori);
    }

    protected function termina(): void {
        $this->html->chiudi();
        rewind($this->buffer);
        $pdf = $this->impaginatore->pdf((string) stream_get_contents($this->buffer));
        fclose($this->buffer);
        if (!str_starts_with($pdf, '%PDF-')) {
            throw new Eccezione('Il motore di stampa non ha prodotto un PDF.');
        }
        $this->out($pdf);
    }
}
