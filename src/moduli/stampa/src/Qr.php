<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Stampa;

use Pfi\Contratti\Eccezione;

/**
 * Codici QR generati sul server, senza servizi esterni (proposta 49 § 3.7): con la classe TCPDF2DBarcode di TCPDF
 * (presente in Moodle) se caricata, altrimenti con il programma `qrencode`. Il risultato è un'immagine SVG da
 * incorporare nel modello come data: (la politica dei contenuti della stampa vieta le immagini esterne).
 */
final class Qr {

    public static function svg(string $testo, int $modulo = 4): string {
        if ($testo === '' || strlen($testo) > 2000) {
            throw new Eccezione('Testo del codice QR vuoto o troppo lungo.');
        }
        if (class_exists('TCPDF2DBarcode')) {
            $b = new \TCPDF2DBarcode($testo, 'QRCODE,M');
            $a = $b->getBarcodeArray();
            $n = (int) $a['num_cols'];
            $q = 4;
            $lato = ($n + 2 * $q) * $modulo;
            $r = '';
            foreach ($a['bcode'] as $y => $riga) {
                foreach ($riga as $x => $v) {
                    if ($v) {
                        $r .= 'M' . (($x + $q) * $modulo) . ',' . (($y + $q) * $modulo) . "h{$modulo}v{$modulo}h-{$modulo}z";
                    }
                }
            }
            return "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$lato} {$lato}\" width=\"{$lato}\" height=\"{$lato}\">"
                . "<rect width=\"100%\" height=\"100%\" fill=\"#fff\"/><path d=\"{$r}\" fill=\"#000\"/></svg>";
        }
        foreach (['/usr/bin/qrencode', '/usr/local/bin/qrencode'] as $p) {
            if (is_executable($p)) {
                $proc = proc_open([$p, '-t', 'SVG', '-l', 'M', '-s', (string) $modulo, '-o', '-'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'],
                    2 => ['file', '/dev/null', 'w']], $tubi);
                fwrite($tubi[0], $testo);
                fclose($tubi[0]);
                $svg = (string) stream_get_contents($tubi[1]);
                fclose($tubi[1]);
                if (proc_close($proc) === 0 && str_contains($svg, '<svg')) {
                    return substr($svg, (int) strpos($svg, '<svg'));
                }
            }
        }
        throw new Eccezione('Nessun generatore di codici QR disponibile (TCPDF o qrencode).');
    }

    /** Immagine pronta per il modello: <img src="data:image/svg+xml;base64,…" alt="…">. */
    public static function img(string $testo, string $alternativo, int $modulo = 4): string {
        return '<img src="data:image/svg+xml;base64,' . base64_encode(self::svg($testo, $modulo)) . '" alt="'
            . htmlspecialchars($alternativo, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '">';
    }
}
