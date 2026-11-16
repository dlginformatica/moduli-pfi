<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esportazione;

use Pfi\Contratti\Eccezione;

/**
 * Base dei formati in archivio ZIP (Office Open XML e OpenDocument): le righe vanno in un file temporaneo come frammento
 * XML, l'archivio si compone alla chiusura e si copia nell'uscita. Richiede l'estensione zip di PHP.
 */
abstract class ArchivioZip extends Base {

    /** @var resource|null */
    private $parziale = null;

    private string $tmp = '';

    protected function apri(): void {
        if (!class_exists(\ZipArchive::class)) {
            throw new Eccezione('Estensione zip di PHP non disponibile.');
        }
        $this->tmp = tempnam(sys_get_temp_dir(), 'pfiesp');
        $this->parziale = fopen($this->tmp, 'w+');
        $this->frammento($this->riga_xml($this->colonne, true));
    }

    protected function frammento(string $s): void {
        fwrite($this->parziale, $s);
    }

    protected function scrivi_riga(array $valori): void {
        $this->frammento($this->riga_xml($valori, false));
    }

    /** @param list<mixed> $valori */
    abstract protected function riga_xml(array $valori, bool $intestazione): string;

    /** @return array<string,string> file dell'archivio (nome => contenuto); il segnaposto «%RIGHE%» riceve le righe */
    abstract protected function parti(): array;

    /** Nomi dei file da conservare senza compressione e per primi (il «mimetype» di OpenDocument). */
    protected function non_compressi(): array {
        return [];
    }

    protected function termina(): void {
        $zip = tempnam(sys_get_temp_dir(), 'pfizip');
        $z = new \ZipArchive();
        try {
            if ($z->open($zip, \ZipArchive::OVERWRITE) !== true) {
                throw new Eccezione('Archivio dell\'esportazione non creato.');
            }
            $parti = $this->parti();
            foreach ($this->non_compressi() as $n) {
                $z->addFromString($n, $parti[$n]);
                $z->setCompressionName($n, \ZipArchive::CM_STORE);
                unset($parti[$n]);
            }
            foreach ($parti as $nome => $contenuto) {
                if (str_contains($contenuto, '%RIGHE%')) {
                    [$prima, $dopo] = explode('%RIGHE%', $contenuto, 2);
                    $f = tempnam(sys_get_temp_dir(), 'pfipart');
                    $out = fopen($f, 'w');
                    fwrite($out, $prima);
                    rewind($this->parziale);
                    stream_copy_to_stream($this->parziale, $out);
                    fwrite($out, $dopo);
                    fclose($out);
                    $z->addFile($f, $nome);
                    $dacancellare[] = $f;
                } else {
                    $z->addFromString($nome, $contenuto);
                }
            }
            if (!$z->close()) {
                throw new Eccezione('Archivio dell\'esportazione non chiuso.');
            }
            $in = fopen($zip, 'r');
            stream_copy_to_stream($in, $this->uscita);
            fclose($in);
        } finally {
            fclose($this->parziale);
            @unlink($this->tmp);
            @unlink($zip);
            foreach ($dacancellare ?? [] as $f) {
                @unlink($f);
            }
        }
    }
}
