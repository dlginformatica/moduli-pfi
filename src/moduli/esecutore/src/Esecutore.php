<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esecutore;

use Pfi\Contratti\Eccezione;
use Pfi\Contratti\Registro;
use Pfi\Contratti\RegistroNullo;

/**
 * Lato del servizio di sistema (release 9.0, blocco M2b; proposta 49 § 3.10): prende le richieste dalla coda una alla
 * volta, le controlla con il catalogo, esegue il comando senza shell con ambiente ridotto e tempo massimo, scrive
 * l'esito e registra ogni operazione con la correlazione.
 *
 * Controlli sulla richiesta: file regolare e non collegamento, al massimo 64 KiB, JSON valido, identificativo uguale al
 * nome del file, età entro la scadenza, operazione nel catalogo, parametri ammessi.
 */
final class Esecutore {

    private Registro $registro;

    public function __construct(
        private readonly Coda $coda,
        private readonly Catalogo $catalogo,
        ?Registro $registro = null,
        private readonly int $scadenza = 3600,
        private readonly string $percorso = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
    ) {
        $this->registro = $registro ?? new RegistroNullo();
    }

    /** Esegue le richieste in coda, dalla più vecchia; restituisce quante ne ha trattate. */
    public function elabora(int $massimo = 100): int {
        $this->interrotte();
        $file = glob($this->coda->cartella('richieste') . '/*.json') ?: [];
        usort($file, fn($a, $b) => (@filemtime($a) <=> @filemtime($b)) ?: strcmp($a, $b));
        $n = 0;
        foreach (array_slice($file, 0, $massimo) as $f) {
            $id = basename($f, '.json');
            if (!Coda::valido($id)) {
                $this->scarta($f);
                continue;
            }
            $incorso = $this->coda->cartella('in-corso') . "/{$id}.json";
            if (is_link($f)) {
                @unlink($f);
                $this->scrivi_esito($id, ['stato' => 'rifiutata', 'motivo' => 'Richiesta non valida (collegamento simbolico).']);
                continue;
            }
            if (!@rename($f, $incorso)) {
                continue;
            }
            $this->tratta($id, $incorso);
            $n++;
        }
        // File temporanei di richieste mai completate: fuori dalla cartella delle richieste, che deve restare vuota
        // (l'unità .path di systemd avvia il servizio finché vi resta qualcosa).
        foreach (glob($this->coda->cartella('richieste') . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $f) {
            if (!str_ends_with($f, '.json') && @filemtime($f) < time() - 3600) {
                $this->scarta($f);
            }
        }
        return $n;
    }

    /** Sposta un file non valido della cartella delle richieste fra quelli in corso, dove la pulizia lo elimina. */
    private function scarta(string $f): void {
        if (!@rename($f, $this->coda->cartella('in-corso') . '/.scartata-' . bin2hex(random_bytes(4)))) {
            @unlink($f);
        }
    }

    /** Le richieste rimaste «in corso» da un'esecuzione precedente si chiudono come interrotte. */
    private function interrotte(): void {
        foreach (glob($this->coda->cartella('in-corso') . '/*.json') ?: [] as $f) {
            $id = basename($f, '.json');
            if (Coda::valido($id) && !is_file($this->coda->cartella('esiti') . "/{$id}.json")) {
                $this->scrivi_esito($id, ['stato' => 'interrotta', 'motivo' => 'Il servizio si è fermato durante l\'esecuzione.']);
            }
            @unlink($f);
        }
    }

    private function tratta(string $id, string $file): void {
        $inizio = microtime(true);
        $richiesta = [];
        try {
            $richiesta = $this->leggi($id, $file);
            $op = $this->catalogo->operazione((string) $richiesta['operazione']);
            $valori = $op->controlla((array) ($richiesta['parametri'] ?? []));
        } catch (Eccezione $e) {
            $this->scrivi_esito($id, ['stato' => 'rifiutata', 'motivo' => $e->getMessage()]);
            $this->registro->registra(Registro::AVVISO, 'Richiesta {id} rifiutata: {motivo}', ['modulo' => 'pfi/esecutore',
                'id' => $id, 'motivo' => $e->getMessage(), 'correlazione' => (string) ($richiesta['correlazione'] ?? '') ?: $id]);
            @unlink($file);
            return;
        }
        $correlazione = (string) ($richiesta['correlazione'] ?? '') ?: $id;
        $contesto = ['modulo' => 'pfi/esecutore', 'correlazione' => $correlazione, 'id' => $id, 'operazione' => $op->nome,
            'richiedente' => (string) ($richiesta['richiedente'] ?? '')];
        $this->registro->registra(Registro::NOTA, 'Operazione {operazione} avviata per {richiedente} ({id})', $contesto);
        $this->scrivi_esito($id, ['stato' => 'in corso', 'operazione' => $op->nome, 'iniziata' => time()]);
        [$codice, $uscita, $scaduta] = $this->esegui($op->argomenti($valori), $op->tempomassimo, $op->uscitamassima, $correlazione);
        $stato = $scaduta ? 'interrotta' : ($codice === 0 ? 'eseguita' : 'non riuscita');
        $this->scrivi_esito($id, ['stato' => $stato, 'operazione' => $op->nome, 'codice' => $codice, 'uscita' => $uscita,
            'durata' => round(microtime(true) - $inizio, 3), 'terminata' => time()]
            + ($scaduta ? ['motivo' => "Tempo massimo di {$op->tempomassimo} secondi superato."] : []));
        $this->registro->registra($codice === 0 ? Registro::NOTA : Registro::ERRORE,
            'Operazione {operazione} {stato} con codice {codice} in {secondi} s ({id})',
            $contesto + ['stato' => $stato, 'codice' => $codice, 'secondi' => round(microtime(true) - $inizio, 1)]);
        @unlink($file);
    }

    private function leggi(string $id, string $file): array {
        $st = @lstat($file);
        if ($st === false || ($st['mode'] & 0170000) !== 0100000 || $st['size'] > Coda::MASSIMO) {
            throw new Eccezione('Richiesta non valida (file non regolare o troppo grande).');
        }
        $d = json_decode((string) file_get_contents($file, false, null, 0, Coda::MASSIMO), true, 16);
        if (!is_array($d) || ($d['id'] ?? null) !== $id || !is_string($d['operazione'] ?? null)) {
            throw new Eccezione('Richiesta non valida (contenuto).');
        }
        if (!is_int($d['creata'] ?? null) || $d['creata'] < time() - $this->scadenza) {
            throw new Eccezione('Richiesta scaduta.');
        }
        return $d;
    }

    /**
     * @param list<string> $argomenti
     * @return array{0:int,1:string,2:bool} codice di uscita, uscita (standard ed errori) troncata, tempo scaduto
     */
    private function esegui(array $argomenti, int $tempo, int $massimo, string $correlazione): array {
        $ambiente = ['PATH' => $this->percorso, 'LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8', 'PFI_CORRELAZIONE' => $correlazione];
        $p = @proc_open($argomenti, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubi, '/', $ambiente);
        if (!is_resource($p)) {
            return [127, 'Comando non avviato.', false];
        }
        stream_set_blocking($tubi[1], false);
        stream_set_blocking($tubi[2], false);
        $uscita = '';
        $fine = microtime(true) + $tempo;
        $scaduta = false;
        while (true) {
            $leggi = array_filter([$tubi[1], $tubi[2]], fn($t) => !feof($t));
            if (!$leggi) {
                break;
            }
            $w = $e = null;
            if (@stream_select($leggi, $w, $e, 0, 200000) > 0) {
                foreach ($leggi as $t) {
                    $dati = fread($t, 8192);
                    if ($dati !== false && strlen($uscita) < $massimo) {
                        $uscita .= substr($dati, 0, $massimo - strlen($uscita));
                    }
                }
            }
            if (microtime(true) > $fine) {
                $scaduta = true;
                proc_terminate($p, 15);
                usleep(500000);
                proc_terminate($p, 9);
                break;
            }
        }
        fclose($tubi[1]);
        fclose($tubi[2]);
        $stato = proc_get_status($p);
        $codice = proc_close($p);
        if ($stato['running'] === false && $stato['exitcode'] !== -1) {
            $codice = $stato['exitcode'];
        }
        return [$scaduta ? 124 : $codice, $uscita, $scaduta];
    }

    private function scrivi_esito(string $id, array $esito): void {
        $esito = ['id' => $id] + $esito;
        $f = $this->coda->cartella('esiti') . "/{$id}.json";
        $tmp = $f . '.tmp';
        $vecchia = umask(0027);
        try {
            file_put_contents($tmp, json_encode($esito, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            rename($tmp, $f);
        } finally {
            umask($vecchia);
        }
    }

    /** Elimina gli esiti più vecchi di $giorni; restituisce quanti. */
    public function pulisci(int $giorni = 30, ?int $adesso = null): int {
        $limite = ($adesso ?? time()) - $giorni * 86400;
        $n = 0;
        $file = array_merge(glob($this->coda->cartella('esiti') . '/*.json') ?: [],
            glob($this->coda->cartella('in-corso') . '/.scartata-*') ?: []);
        foreach ($file as $f) {
            if (filemtime($f) < $limite && @unlink($f)) {
                $n++;
            }
        }
        return $n;
    }
}
