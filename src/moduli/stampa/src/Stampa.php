<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Stampa;

use Pfi\Contratti\Eccezione;
use Pfi\Contratti\Registro;
use Pfi\Contratti\RegistroNullo;
use Pfi\Esportazione\Impaginatore;
use Pfi\Modelli\Modelli;

/**
 * Stampa con i modelli (release 9.0, blocco M6; proposta 49 § 3.7): i modelli di stampa sono modelli di pfi/modelli
 * del canale «stampa» con la parte «html» (corpo), e facoltative «titolo», «intestazione» e «piedipagina»; la pagina
 * e lo stile si passano da chi stampa (configurazione del modello nell'adattatore).
 */
final class Stampa {

    private Registro $registro;

    public function __construct(private readonly Modelli $modelli, private readonly ?Impaginatore $impaginatore, ?Registro $registro = null) {
        $this->registro = $registro ?? new RegistroNullo();
    }

    /** Documento HTML completo, per l'anteprima nel portale. */
    public function anteprima(string $chiave, array $dati, string $lingua = 'it', ?Pagina $pagina = null, string $stile = ''): string {
        $p = $this->modelli->rendi($chiave, 'stampa', $lingua, $dati);
        if (!isset($p['html'])) {
            throw new Eccezione("Il modello di stampa «{$chiave}» non ha la parte html.");
        }
        $pagina ??= new Pagina();
        if (isset($p['intestazione']) || isset($p['piedipagina'])) {
            $pagina = new Pagina($pagina->formato, $pagina->orizzontale, $pagina->margini,
                trim($p['intestazione'] ?? $pagina->intestazione), trim($p['piedipagina'] ?? $pagina->piedipagina));
        }
        return Documento::html($p['html'], $pagina, trim($p['titolo'] ?? ''), $lingua, $stile);
    }

    /** PDF del modello reso con i dati. */
    public function pdf(string $chiave, array $dati, string $lingua = 'it', ?Pagina $pagina = null, string $stile = ''): string {
        if ($this->impaginatore === null) {
            throw new Eccezione('Motore di stampa non configurato.');
        }
        $inizio = microtime(true);
        $pdf = $this->impaginatore->pdf($this->anteprima($chiave, $dati, $lingua, $pagina, $stile));
        $this->registro->registra(Registro::INFORMAZIONE, 'Stampa {modello}: {byte} byte in {ms} ms', ['modulo' => 'pfi/stampa',
            'modello' => $chiave, 'byte' => strlen($pdf), 'ms' => (int) round((microtime(true) - $inizio) * 1000)]);
        return $pdf;
    }
}
