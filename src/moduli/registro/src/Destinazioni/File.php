<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Registro\Destinazioni;

use Pfi\Contratti\Eccezione;
use Pfi\Contratti\EsitoSalute;
use Pfi\Registro\Destinazione;
use Pfi\Registro\Voce;

/**
 * File di testo con una riga JSON per evento, rotazione per dimensione e conservazione dei file ruotati per numero e
 * per giorni. Il file è creato con permessi 0640; la rotazione rinomina «registro.log» in «registro.log.1» e così via.
 */
final class File implements Destinazione {

    public function __construct(
        private readonly string $percorso,
        private readonly int $dimensionemassima = 10 * 1024 * 1024,
        private readonly int $filemassimi = 10,
        private readonly int $giorni = 90,
    ) {
        if ($dimensionemassima < 1024 || $filemassimi < 1 || $giorni < 1) {
            throw new Eccezione('Rotazione del registro su file non valida: dimensione almeno 1024 byte, almeno un file e un giorno.');
        }
    }

    public function nome(): string {
        return 'file';
    }

    public function scrivi(Voce $voce): void {
        $riga = $voce->json() . "\n";
        clearstatcache(true, $this->percorso);
        if (is_file($this->percorso) && filesize($this->percorso) + strlen($riga) > $this->dimensionemassima) {
            $this->ruota();
        }
        $nuovo = !is_file($this->percorso);
        $vecchia = umask(0027);
        try {
            if (@file_put_contents($this->percorso, $riga, FILE_APPEND | LOCK_EX) === false) {
                throw new Eccezione('Scrittura non riuscita nel file del registro.');
            }
        } finally {
            umask($vecchia);
        }
        if ($nuovo) {
            @chmod($this->percorso, 0640);
        }
    }

    /** Ruota i file e applica la conservazione; si può chiamare anche dal cron. */
    public function ruota(): void {
        $fp = @fopen($this->percorso . '.lock', 'c');
        if ($fp) {
            flock($fp, LOCK_EX);
        }
        try {
            clearstatcache();
            @unlink($this->percorso . '.' . $this->filemassimi);
            for ($i = $this->filemassimi - 1; $i >= 1; $i--) {
                if (is_file($this->percorso . '.' . $i)) {
                    rename($this->percorso . '.' . $i, $this->percorso . '.' . ($i + 1));
                }
            }
            if (is_file($this->percorso)) {
                rename($this->percorso, $this->percorso . '.1');
            }
            $this->conserva();
        } finally {
            if ($fp) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
    }

    /** Elimina i file ruotati più vecchi dei giorni di conservazione; restituisce quanti ne ha eliminati. */
    public function conserva(?int $adesso = null): int {
        $limite = ($adesso ?? time()) - $this->giorni * 86400;
        $n = 0;
        foreach (glob($this->percorso . '.[0-9]*') ?: [] as $f) {
            if (preg_match('/\.\d+$/', $f) && filemtime($f) < $limite && @unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    public function salute(): EsitoSalute {
        $cartella = dirname($this->percorso);
        if (!is_dir($cartella)) {
            return EsitoSalute::guasto('La cartella del file del registro non esiste.', ['cartella' => $cartella]);
        }
        $scrivibile = is_file($this->percorso) ? is_writable($this->percorso) : is_writable($cartella);
        return $scrivibile ? EsitoSalute::buono('File del registro scrivibile.', ['file' => $this->percorso])
            : EsitoSalute::guasto('File del registro non scrivibile.', ['file' => $this->percorso]);
    }
}
