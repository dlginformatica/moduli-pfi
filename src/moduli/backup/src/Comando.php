<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Backup;

use Pfi\Contratti\Eccezione;

/** Esecuzione di comandi senza shell con ambiente ridotto, ingresso e uscita da file e tempo massimo. */
final class Comando {

    /**
     * @param list<string> $argomenti
     * @param array<string,string> $ambiente variabili aggiunte (per esempio PGHOST)
     * @return array{0:int,1:string} codice e messaggi d'errore (al massimo 4 KiB)
     */
    public static function esegui(array $argomenti, ?string $ingresso = null, ?string $uscita = null, array $ambiente = [],
        int $tempo = 3600): array {
        $env = ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C.UTF-8'] + $ambiente;
        $p = proc_open($argomenti, [0 => $ingresso !== null ? ['file', $ingresso, 'r'] : ['file', '/dev/null', 'r'],
            1 => $uscita !== null ? ['file', $uscita, 'w'] : ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $tubi, '/', $env);
        if (!is_resource($p)) {
            throw new Eccezione('Comando non avviato: ' . basename($argomenti[0]));
        }
        stream_set_blocking($tubi[2], false);
        $errori = '';
        $fine = time() + $tempo;
        while (($s = proc_get_status($p))['running']) {
            $errori .= (string) fread($tubi[2], 4096);
            if (time() > $fine) {
                proc_terminate($p, 9);
                proc_close($p);
                throw new Eccezione(basename($argomenti[0]) . ": oltre il tempo massimo di {$tempo} secondi.");
            }
            usleep(100000);
        }
        $errori .= (string) stream_get_contents($tubi[2]);
        fclose($tubi[2]);
        proc_close($p);
        return [(int) $s['exitcode'], substr(trim($errori), 0, 4096)];
    }

    /** Come esegui(), con Eccezione se il codice non è 0. */
    public static function riuscito(array $argomenti, ?string $ingresso = null, ?string $uscita = null, array $ambiente = [], int $tempo = 3600): void {
        [$c, $e] = self::esegui($argomenti, $ingresso, $uscita, $ambiente, $tempo);
        if ($c !== 0) {
            throw new Eccezione(basename($argomenti[0]) . " non riuscito (codice {$c}): " . mb_substr($e, 0, 300));
        }
    }
}
