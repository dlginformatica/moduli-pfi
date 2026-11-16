<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esportazione;

use Pfi\Contratti\Eccezione;
use Pfi\Esportazione\Formati;

/**
 * Punto d'accesso dell'esportazione (release 9.0, blocco M5; proposta 49 § 3.6): formati per nome ed esportazione di
 * righe da qualsiasi sorgente iterabile, in un flusso o in un file.
 */
final class Esportatore {

    /** @var array<string,Formato|\Closure():Formato> */
    private array $formati = [];

    public function __construct(?Impaginatore $impaginatore = null) {
        $this->formati = [
            'csv' => fn() => Formati\Delimitato::csv(),
            'csv_pv' => fn() => Formati\Delimitato::csv(';'),
            'tsv' => fn() => Formati\Delimitato::tsv(),
            'xlsx' => fn() => new Formati\Xlsx(),
            'ods' => fn() => new Formati\Ods(),
            'docx' => fn() => new Formati\Docx(),
            'odt' => fn() => new Formati\Odt(),
            'rtf' => fn() => new Formati\Rtf(),
            'html' => fn() => new Formati\Html(),
            'json' => fn() => new Formati\Json(),
            'xml' => fn() => new Formati\Xml(),
            'md' => fn() => new Formati\Markdown(),
        ];
        if ($impaginatore !== null) {
            $this->formati['pdf'] = fn() => new Formati\Pdf($impaginatore);
        }
    }

    /** Aggiunge o sostituisce un formato configurato (per esempio un CSV con altra codifica o un testo a larghezza fissa). */
    public function formato_con_nome(string $nome, Formato|\Closure $formato): self {
        if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', $nome)) {
            throw new Eccezione('Nome di formato non valido.');
        }
        $this->formati[$nome] = $formato;
        return $this;
    }

    /** @return list<string> */
    public function nomi(): array {
        return array_keys($this->formati);
    }

    public function formato(string $nome): Formato {
        $f = $this->formati[$nome] ?? throw new Eccezione("Formato di esportazione sconosciuto: «{$nome}»");
        return $f instanceof \Closure ? $f() : clone $f;
    }

    /**
     * @param iterable<list<mixed>> $righe
     * @param list<string> $colonne
     * @param resource $uscita
     * @return int righe scritte
     */
    public function esporta(iterable $righe, array $colonne, string $formato, $uscita, string $titolo = ''): int {
        $f = $this->formato($formato);
        $f->inizia($uscita, $colonne, $titolo);
        $n = 0;
        foreach ($righe as $r) {
            $f->riga(array_values((array) $r));
            $n++;
        }
        $f->chiudi();
        return $n;
    }

    /**
     * Esporta in un file (scrittura in un file temporaneo nella stessa cartella e spostamento finale).
     *
     * @param iterable<list<mixed>> $righe
     */
    public function in_file(iterable $righe, array $colonne, string $formato, string $percorso, string $titolo = ''): int {
        $tmp = $percorso . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $out = fopen($tmp, 'w');
        if ($out === false) {
            throw new Eccezione('File dell\'esportazione non scrivibile.');
        }
        try {
            $n = $this->esporta($righe, $colonne, $formato, $out, $titolo);
            fclose($out);
            if (!rename($tmp, $percorso)) {
                throw new Eccezione('File dell\'esportazione non spostato.');
            }
            return $n;
        } catch (\Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($tmp);
            throw $e;
        }
    }
}
