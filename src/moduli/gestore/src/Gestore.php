<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Gestore;

use Pfi\Contratti\Eccezione;
use Pfi\Contratti\EsitoSalute;
use Pfi\Contratti\Manifesto;
use Pfi\Contratti\Registro;
use Pfi\Contratti\RegistroNullo;
use Pfi\Contratti\Versione;

/**
 * Gestione dei moduli universali installati (release 9.0, blocco M7b; D-162; proposta 49 § 3.9).
 *
 * Struttura sul server: <base>/<nome>/<versione>/ con il modulo e <base>/<nome>/attuale, collegamento alla versione in
 * uso. Un aggiornamento verifica la firma del pacchetto, controlla le dipendenze in entrambi i versi (il modulo nuovo
 * trova ciò che richiede; i moduli che dipendono da lui restano soddisfatti), installa la versione accanto alle altre,
 * sposta il collegamento in modo atomico, esegue le prove di salute del modulo e, se una prova è guasta, torna alla
 * versione precedente. La storia delle operazioni è in <base>/.storia.json.
 *
 * Le operazioni che scrivono richiedono i permessi sulla cartella dei moduli: nel portale le esegue il servizio
 * pfi-esecutore, mai il server web.
 */
final class Gestore {

    private Registro $registro;

    /** @var \Closure(string,string):EsitoSalute */
    private \Closure $salute;

    /**
     * @param \Closure(string $nome, string $cartella):EsitoSalute|null $salute prove di salute del modulo installato in
     *     $cartella; predefinita: processo PHP separato con bin/pfi-salute-modulo, che carica il codice nuovo
     */
    public function __construct(
        private readonly string $base,
        private readonly string $pubblica,
        ?Registro $registro = null,
        ?\Closure $salute = null,
    ) {
        $this->registro = $registro ?? new RegistroNullo();
        $this->salute = $salute ?? self::salute_in_processo($base);
    }

    public static function salute_in_processo(string $base): \Closure {
        return function(string $nome, string $cartella) use ($base): EsitoSalute {
            $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/pfi-salute-modulo', $base, $nome], [1 => ['pipe', 'w'],
                2 => ['file', '/dev/null', 'w']], $t);
            $out = (string) stream_get_contents($t[1]);
            fclose($t[1]);
            proc_close($p);
            $d = json_decode($out, true);
            return is_array($d) && isset($d['stato'], $d['messaggio'])
                ? new EsitoSalute($d['stato'], $d['messaggio']) : EsitoSalute::guasto('Prove di salute non eseguibili.');
        };
    }

    private static function cartella_nome(string $nome): string {
        if (!preg_match('#^pfi/([a-z][a-z0-9-]*)$#', $nome, $m)) {
            throw new Eccezione("Nome di modulo non valido: «{$nome}»");
        }
        return $m[1];
    }

    /** @return array<string,Manifesto> manifesti delle versioni in uso */
    public function installati(): array {
        $fuori = [];
        foreach (glob($this->base . '/*/attuale/modulo.json') ?: [] as $f) {
            $m = Manifesto::da_file($f);
            $fuori[$m->nome] = $m;
        }
        ksort($fuori);
        return $fuori;
    }

    /** @return list<string> versioni presenti di un modulo, dalla più recente */
    public function versioni(string $nome): array {
        $d = $this->base . '/' . self::cartella_nome($nome);
        $v = array_values(array_filter(scandir($d) ?: [], fn($x) => preg_match('/^\d+\.\d+\.\d+$/', $x) && is_dir("{$d}/{$x}")));
        usort($v, fn($a, $b) => Versione::da_testo($b)->confronta(Versione::da_testo($a)));
        return $v;
    }

    /**
     * Stato per il pannello: per ogni modulo versione in uso, altre versioni presenti, requisiti mancanti.
     *
     * @return array<string,array{versione:string,descrizione:string,versioni:list<string>,mancanti:list<string>,richiede:array<string,string>}>
     */
    public function elenco(): array {
        $installati = $this->installati();
        $disponibili = array_map(fn($m) => $m->versione, $installati);
        $fuori = [];
        foreach ($installati as $nome => $m) {
            $fuori[$nome] = ['versione' => (string) $m->versione, 'descrizione' => $m->descrizione, 'versioni' => $this->versioni($nome),
                'mancanti' => $m->requisiti_mancanti($disponibili), 'richiede' => $m->richiede];
        }
        return $fuori;
    }

    /**
     * Combinazione rispetto a quella collaudata di una release (D-162, rischio: un'installazione aggiornata modulo per
     * modulo si discosta dalla release del kit).
     *
     * @param array<string,string> $collaudata nome => versione
     * @return array<string,array{0:?string,1:?string}> nome => [installata, collaudata] per i soli scostamenti
     */
    public function scostamenti(array $collaudata): array {
        $s = [];
        $inst = array_map(fn($m) => (string) $m->versione, $this->installati());
        foreach (array_unique([...array_keys($inst), ...array_keys($collaudata)]) as $n) {
            if (($inst[$n] ?? null) !== ($collaudata[$n] ?? null)) {
                $s[$n] = [$inst[$n] ?? null, $collaudata[$n] ?? null];
            }
        }
        ksort($s);
        return $s;
    }

    /** Requisiti violati se $nuovo sostituisse la versione in uso del suo modulo. */
    private function conflitti(Manifesto $nuovo): array {
        $disponibili = array_map(fn($m) => $m->versione, $this->installati());
        $problemi = $nuovo->requisiti_mancanti($disponibili);
        $disponibili[$nuovo->nome] = $nuovo->versione;
        foreach ($this->installati() as $nome => $m) {
            if ($nome !== $nuovo->nome && isset($m->richiede[$nuovo->nome]) && !$nuovo->versione->soddisfa($m->richiede[$nuovo->nome])) {
                $problemi[] = "{$nome} {$m->versione} richiede {$nuovo->nome} «{$m->richiede[$nuovo->nome]}»";
            }
        }
        return $problemi;
    }

    /**
     * Installa un pacchetto firmato e lo mette in uso; restituisce il manifesto installato.
     * Con la prova di salute guasta ripristina la versione precedente e lancia Eccezione.
     */
    public function installa(string $archivio, string $autore): Manifesto {
        $tmp = $this->base . '/.pacchetto-' . bin2hex(random_bytes(6));
        try {
            [$m, $estratta] = Pacchetto::verifica($archivio, $this->pubblica, $tmp);
            $problemi = $this->conflitti($m);
            if ($problemi) {
                throw new Eccezione("{$m->nome} {$m->versione} non installabile: " . implode('; ', $problemi) . '.');
            }
            $dir = $this->base . '/' . self::cartella_nome($m->nome);
            $dest = "{$dir}/{$m->versione}";
            if (is_dir($dest)) {
                throw new Eccezione("{$m->nome} {$m->versione} è già presente: per tornarci si usa il ritorno indietro.");
            }
            if (!is_dir($dir) && !mkdir($dir, 0755)) {
                throw new Eccezione('Cartella del modulo non creata.');
            }
            if (!rename($estratta, $dest)) {
                throw new Eccezione('Versione del modulo non spostata nella cartella dei moduli.');
            }
        } finally {
            if (is_dir($tmp)) {
                exec('rm -rf ' . escapeshellarg($tmp));
            }
        }
        $precedente = $this->attuale($m->nome);
        $this->usa($m->nome, (string) $m->versione);
        $esito = ($this->salute)($m->nome, $dest);
        if ($esito->stato === EsitoSalute::GUASTO) {
            if ($precedente !== null) {
                $this->usa($m->nome, $precedente);
            }
            $this->storia($m->nome, 'annullato', (string) $m->versione, $precedente, $autore, $esito->messaggio);
            throw new Eccezione("{$m->nome} {$m->versione}: prova di salute guasta ({$esito->messaggio}); "
                . ($precedente !== null ? "rimessa in uso la versione {$precedente}." : 'modulo non in uso.'));
        }
        $this->storia($m->nome, 'aggiornato', (string) $m->versione, $precedente, $autore, $esito->messaggio);
        return $m;
    }

    /** Torna alla versione presente indicata o, senza indicazione, alla precedente usata. */
    public function indietro(string $nome, string $autore, ?string $versione = null): string {
        $attuale = $this->attuale($nome) ?? throw new Eccezione("{$nome} non è installato.");
        if ($versione === null) {
            foreach (array_reverse($this->leggi_storia()) as $s) {
                if ($s['modulo'] === $nome && $s['azione'] === 'aggiornato' && $s['a'] === $attuale && $s['da'] !== null) {
                    $versione = $s['da'];
                    break;
                }
            }
            $versione ??= $this->versioni($nome)[1] ?? throw new Eccezione("{$nome}: nessuna versione precedente presente.");
        }
        if (!in_array($versione, $this->versioni($nome), true)) {
            throw new Eccezione("{$nome} {$versione} non è presente.");
        }
        $m = Manifesto::da_file("{$this->base}/" . self::cartella_nome($nome) . "/{$versione}/modulo.json");
        $problemi = $this->conflitti($m);
        if ($problemi) {
            throw new Eccezione("Ritorno a {$nome} {$versione} non possibile: " . implode('; ', $problemi) . '.');
        }
        $this->usa($nome, $versione);
        $this->storia($nome, 'riportato', $versione, $attuale, $autore, '');
        return $versione;
    }

    public function attuale(string $nome): ?string {
        $l = $this->base . '/' . self::cartella_nome($nome) . '/attuale';
        return is_link($l) ? basename((string) readlink($l)) : null;
    }

    /** Sposta il collegamento «attuale» in modo atomico (collegamento nuovo e rinomina). */
    private function usa(string $nome, string $versione): void {
        $dir = $this->base . '/' . self::cartella_nome($nome);
        $tmp = "{$dir}/.attuale-" . bin2hex(random_bytes(4));
        if (!symlink($versione, $tmp) || !rename($tmp, "{$dir}/attuale")) {
            @unlink($tmp);
            throw new Eccezione('Collegamento alla versione in uso non aggiornato.');
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /** Elimina una versione non in uso. */
    public function rimuovi(string $nome, string $versione): void {
        if ($this->attuale($nome) === $versione) {
            throw new Eccezione('La versione in uso non si elimina.');
        }
        if (!in_array($versione, $this->versioni($nome), true)) {
            throw new Eccezione("{$nome} {$versione} non è presente.");
        }
        exec('rm -rf ' . escapeshellarg($this->base . '/' . self::cartella_nome($nome) . '/' . $versione));
        $this->storia($nome, 'eliminata', $versione, null, '', '');
    }

    /** @return list<array{quando:string,modulo:string,azione:string,a:string,da:?string,autore:string,nota:string}> */
    public function leggi_storia(): array {
        $d = json_decode((string) @file_get_contents($this->base . '/.storia.json'), true);
        return is_array($d) ? $d : [];
    }

    private function storia(string $nome, string $azione, string $a, ?string $da, string $autore, string $nota): void {
        $s = $this->leggi_storia();
        $s[] = ['quando' => date(DATE_RFC3339), 'modulo' => $nome, 'azione' => $azione, 'a' => $a, 'da' => $da, 'autore' => $autore,
            'nota' => mb_substr($nota, 0, 200)];
        file_put_contents($this->base . '/.storia.json.tmp', json_encode(array_slice($s, -500), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename($this->base . '/.storia.json.tmp', $this->base . '/.storia.json');
        $this->registro->registra($azione === 'annullato' ? Registro::ERRORE : Registro::NOTA, 'Modulo {nome}: {azione} {a}' . ($da ? ' (da {da})' : ''),
            ['modulo' => 'pfi/gestore', 'nome' => $nome, 'azione' => $azione, 'a' => $a, 'da' => $da, 'autore' => $autore]);
    }
}
