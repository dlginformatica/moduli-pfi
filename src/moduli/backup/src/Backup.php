<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Backup;

use Pfi\Contratti\Eccezione;
use Pfi\Contratti\Registro;
use Pfi\Contratti\RegistroNullo;

/**
 * Backup granulare con pulsante unico (release 9.0, blocco M7; proposta 49 § 3.8).
 *
 * Ogni esecuzione crea nella cartella dei backup una sottocartella AAAAMMGG-HHMMSS (0700) con i file delle unità scelte,
 * MANIFESTO.json (unità, descrizioni, file, dimensioni, rilascio, data, durata), RILASCIO (compatibile con
 * pfi-ripristino) e SHA256SUMS; copia il backup nelle destinazioni remote; applica la conservazione per numero e per
 * giorni, locale e remota. La cartella incompleta di un'esecuzione non riuscita si elimina.
 */
final class Backup {

    /** @var array<string,Unita> */
    private array $unita = [];

    /** @var list<Destinazione> */
    private array $destinazioni = [];

    private Registro $registro;

    public function __construct(
        private readonly string $cartella,
        private readonly string $rilascio = 'sconosciuto',
        private readonly int $numero = 7,
        private readonly int $giorni = 30,
        ?Registro $registro = null,
    ) {
        if ($numero < 1 || $giorni < 1) {
            throw new Eccezione('La conservazione del backup è di almeno un backup e un giorno.');
        }
        $this->registro = $registro ?? new RegistroNullo();
    }

    public function unita(Unita $u): self {
        $this->unita[$u->nome()] = $u;
        return $this;
    }

    public function destinazione(Destinazione $d): self {
        $this->destinazioni[] = $d;
        return $this;
    }

    /** @return array<string,Unita> */
    public function elenco_unita(): array {
        return $this->unita;
    }

    public static function valido(string $id): bool {
        return (bool) preg_match('/^\d{8}-\d{6}$/', $id);
    }

    /**
     * Esegue il backup delle unità indicate (tutte se vuoto) e restituisce l'identificativo.
     *
     * @param list<string> $scelte
     */
    public function esegui(array $scelte = [], ?\DateTimeImmutable $quando = null): string {
        $scelte = $scelte ?: array_keys($this->unita);
        foreach ($scelte as $n) {
            if (!isset($this->unita[$n])) {
                throw new Eccezione("Unità di backup sconosciuta: «{$n}»");
            }
        }
        $quando ??= new \DateTimeImmutable();
        $id = $quando->format('Ymd-His');
        $dir = "{$this->cartella}/{$id}";
        $vecchia = umask(0077);
        $inizio = microtime(true);
        $corr = ['modulo' => 'pfi/backup', 'backup' => $id];
        try {
            if (!is_dir($this->cartella) && !mkdir($this->cartella, 0700, true)) {
                throw new Eccezione('Cartella dei backup non creata.');
            }
            if (!mkdir($dir, 0700)) {
                throw new Eccezione("Backup {$id} già presente o cartella non scrivibile.");
            }
            $manifesto = ['id' => $id, 'rilascio' => $this->rilascio, 'data' => $quando->format(DATE_RFC3339), 'unita' => []];
            foreach ($scelte as $n) {
                $t = microtime(true);
                $file = $this->unita[$n]->salva($dir);
                $manifesto['unita'][$n] = ['descrizione' => $this->unita[$n]->descrizione(), 'file' => $file,
                    'byte' => array_sum(array_map(fn($f) => filesize("{$dir}/{$f}"), $file)), 'secondi' => round(microtime(true) - $t, 1)];
            }
            $manifesto['secondi'] = round(microtime(true) - $inizio, 1);
            file_put_contents("{$dir}/MANIFESTO.json", json_encode($manifesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            file_put_contents("{$dir}/RILASCIO", $this->rilascio . "\n");
            $somme = '';
            foreach (self::file_di($dir) as $f) {
                if ($f !== 'SHA256SUMS') {
                    $somme .= hash_file('sha256', "{$dir}/{$f}") . "  {$f}\n";
                }
            }
            file_put_contents("{$dir}/SHA256SUMS", $somme);
        } catch (\Throwable $e) {
            if (is_dir($dir)) {
                Comando::esegui(['rm', '-rf', '--', $dir]);
            }
            $this->registro->registra(Registro::ERRORE, 'Backup {backup} non riuscito: {errore}', $corr + ['errore' => $e->getMessage()]);
            throw $e;
        } finally {
            umask($vecchia);
        }
        $this->registro->registra(Registro::NOTA, 'Backup {backup} eseguito: unità {unita} in {secondi} s', $corr
            + ['unita' => implode(', ', $scelte), 'secondi' => round(microtime(true) - $inizio, 1)]);
        foreach ($this->destinazioni as $d) {
            try {
                $d->invia($dir, $id);
                $this->registro->registra(Registro::NOTA, 'Backup {backup} copiato in {destinazione}', $corr + ['destinazione' => $d->nome()]);
            } catch (\Throwable $e) {
                $this->registro->registra(Registro::ERRORE, 'Copia del backup {backup} in {destinazione} non riuscita: {errore}',
                    $corr + ['destinazione' => $d->nome(), 'errore' => $e->getMessage()]);
            }
        }
        $this->conserva($quando);
        return $id;
    }

    /** @return list<string> */
    public static function file_di(string $dir): array {
        $f = array_values(array_filter(scandir($dir) ?: [], fn($x) => is_file("{$dir}/{$x}")));
        sort($f);
        return $f;
    }

    /** @return list<string> backup locali dal più recente */
    public function backup(): array {
        $b = array_values(array_filter(scandir($this->cartella) ?: [], fn($x) => self::valido($x) && is_dir("{$this->cartella}/{$x}")));
        rsort($b);
        return $b;
    }

    /** Conserva gli ultimi $numero backup e comunque quelli degli ultimi $giorni; restituisce gli eliminati. */
    public function conserva(?\DateTimeImmutable $adesso = null): array {
        $limite = ($adesso ?? new \DateTimeImmutable())->modify("-{$this->giorni} days")->format('Ymd-His');
        $eliminati = [];
        foreach (array_slice($this->backup(), $this->numero) as $id) {
            if ($id < $limite) {
                Comando::riuscito(['rm', '-rf', '--', "{$this->cartella}/{$id}"]);
                $eliminati[] = $id;
            }
        }
        foreach ($this->destinazioni as $d) {
            try {
                $remoti = $d->elenco();
                rsort($remoti);
                foreach (array_slice($remoti, $this->numero) as $id) {
                    if ($id < $limite) {
                        $d->elimina($id);
                    }
                }
            } catch (\Throwable $e) {
                $this->registro->registra(Registro::AVVISO, 'Conservazione in {destinazione} non riuscita: {errore}',
                    ['modulo' => 'pfi/backup', 'destinazione' => $d->nome(), 'errore' => $e->getMessage()]);
            }
        }
        return $eliminati;
    }

    public function percorso(string $id): string {
        if (!self::valido($id) || !is_dir("{$this->cartella}/{$id}")) {
            throw new Eccezione("Backup sconosciuto: «{$id}»");
        }
        return "{$this->cartella}/{$id}";
    }

    /** Controlla le impronte; Eccezione con i file alterati o mancanti. */
    public static function verifica_impronte(string $dir): array {
        $righe = @file("{$dir}/SHA256SUMS", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$righe) {
            throw new Eccezione('Impronte del backup assenti.');
        }
        $errati = [];
        foreach ($righe as $r) {
            if (!preg_match('/^([0-9a-f]{64})  ([A-Za-z0-9._-]+)$/', $r, $m)) {
                throw new Eccezione('File delle impronte non valido.');
            }
            if (!is_file("{$dir}/{$m[2]}") || !hash_equals($m[1], hash_file('sha256', "{$dir}/{$m[2]}"))) {
                $errati[] = $m[2];
            }
        }
        if ($errati) {
            throw new Eccezione('Impronte non corrispondenti: ' . implode(', ', $errati) . '. Il backup non si ripristina.');
        }
        return json_decode((string) file_get_contents("{$dir}/MANIFESTO.json"), true) ?: [];
    }

    /**
     * Ripristino granulare delle unità indicate (tutte quelle del backup se vuoto) dopo il controllo delle impronte e del
     * rilascio. Con $prova vera non modifica nulla e controlla solo la ripristinabilità. Il ripristino completo del
     * portale (manutenzione, cron, cache) resta a pfi-ripristino, che il servizio esegue attraverso pfi/esecutore.
     *
     * @param list<string> $scelte
     */
    public function ripristina(string $id, array $scelte = [], bool $prova = true, bool $rilasciodiverso = false): array {
        $dir = $this->percorso($id);
        $m = self::verifica_impronte($dir);
        if (($m['rilascio'] ?? '') !== $this->rilascio && !$rilasciodiverso) {
            throw new Eccezione("Il backup viene dal rilascio «{$m['rilascio']}», in servizio c'è «{$this->rilascio}»: confermare il rilascio diverso.");
        }
        $scelte = $scelte ?: array_keys($m['unita'] ?? []);
        foreach ($scelte as $n) {
            if (!isset($m['unita'][$n])) {
                throw new Eccezione("Il backup {$id} non contiene l'unità «{$n}».");
            }
            if (!isset($this->unita[$n])) {
                throw new Eccezione("Unità «{$n}» non configurata in questo portale.");
            }
        }
        foreach ($scelte as $n) {
            $prova ? $this->unita[$n]->prova($dir) : $this->unita[$n]->ripristina($dir);
            $this->registro->registra($prova ? Registro::INFORMAZIONE : Registro::NOTA,
                ($prova ? 'Prova di ripristino' : 'Ripristino') . ' dell\'unità {unita} dal backup {backup} riuscito',
                ['modulo' => 'pfi/backup', 'backup' => $id, 'unita' => $n]);
        }
        return $scelte;
    }
}
