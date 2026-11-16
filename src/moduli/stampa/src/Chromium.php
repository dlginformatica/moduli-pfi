<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Stampa;

use Pfi\Contratti\Eccezione;
use Pfi\Esportazione\Impaginatore;

/**
 * Impaginazione HTML → PDF con Chromium senza interfaccia (D-161 c): programma `chrome-headless-shell` o
 * `chromium --headless`, profilo temporaneo, nessuna rete in background, tempo massimo. L'HTML arriva da un file
 * temporaneo leggibile solo dal processo; la politica di sicurezza dei contenuti del Documento blocca le risorse esterne.
 *
 * La sandbox di Chromium resta attiva salvo $senzasandbox (necessario solo se il processo gira come root, per esempio
 * nelle prove): con l'utente del server web la sandbox richiede gli spazi dei nomi utente del kernel [DA VERIFICARE
 * sulla VM del kit].
 */
final class Chromium implements Impaginatore {

    public function __construct(
        private readonly string $programma,
        private readonly int $tempomassimo = 60,
        private readonly bool $senzasandbox = false,
        private readonly ?string $cartella = null,
    ) {
        if (!str_starts_with($programma, '/') || !is_file($programma) || !is_executable($programma)) {
            throw new Eccezione('Programma Chromium non trovato o non eseguibile.');
        }
    }

    /** Primo programma disponibile fra quelli abituali dei sistemi Debian e Ubuntu, o null. */
    public static function cerca(): ?string {
        foreach (['/usr/bin/chrome-headless-shell', '/usr/bin/chromium-headless-shell', '/usr/bin/chromium', '/usr/bin/chromium-browser',
            '/usr/bin/google-chrome'] as $p) {
            if (is_file($p) && is_executable($p)) {
                return $p;
            }
        }
        return null;
    }

    public function pdf(string $html): string {
        $base = rtrim($this->cartella ?? sys_get_temp_dir(), '/') . '/pfistampa-' . bin2hex(random_bytes(8));
        if (!@mkdir($base, 0700)) {
            throw new Eccezione('Cartella temporanea della stampa non creata.');
        }
        try {
            file_put_contents("{$base}/documento.html", $html);
            $argomenti = [$this->programma, '--headless', '--disable-gpu', '--disable-dev-shm-usage', '--no-first-run',
                '--disable-background-networking', '--disable-component-update', '--disable-sync', '--disable-default-apps',
                '--disable-extensions', '--no-pdf-header-footer', '--user-data-dir=' . $base . '/profilo',
                '--print-to-pdf=' . $base . '/documento.pdf', 'file://' . $base . '/documento.html'];
            if ($this->senzasandbox) {
                array_splice($argomenti, 1, 0, ['--no-sandbox']);
            }
            $p = proc_open($argomenti, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
                $tubi, $base, ['HOME' => $base, 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'LANG' => 'C.UTF-8']);
            if (!is_resource($p)) {
                throw new Eccezione('Chromium non avviato.');
            }
            stream_set_blocking($tubi[2], false);
            $fine = microtime(true) + $this->tempomassimo;
            $errori = '';
            while (($s = proc_get_status($p))['running']) {
                $errori .= (string) fread($tubi[2], 8192);
                if (microtime(true) > $fine) {
                    proc_terminate($p, 9);
                    proc_close($p);
                    throw new Eccezione("Impaginazione oltre il tempo massimo di {$this->tempomassimo} secondi.");
                }
                usleep(50000);
            }
            fclose($tubi[2]);
            proc_close($p);
            $pdf = @file_get_contents("{$base}/documento.pdf");
            if ($pdf === false || !str_starts_with($pdf, '%PDF-')) {
                throw new Eccezione('Chromium non ha prodotto il PDF (codice ' . $s['exitcode'] . ').');
            }
            return $pdf;
        } finally {
            exec('rm -rf ' . escapeshellarg($base));
        }
    }
}
