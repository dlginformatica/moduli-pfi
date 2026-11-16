<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Backup\Unita;

use Pfi\Backup\Cifratura;
use Pfi\Backup\Comando;
use Pfi\Backup\Unita;
use Pfi\Contratti\Eccezione;

/**
 * Cartella in un archivio tar.gz (cartella dei dati di Moodle, configurazione del server), facoltativamente cifrato
 * (la configurazione /etc/pfi contiene segreti: F-03). Ripristino: il contenuto attuale si accantona in $accantona
 * (spostandolo, la cartella resta al suo posto anche se è un punto di montaggio), poi si estrae l'archivio e si
 * assegna il proprietario, se indicato.
 */
final class Cartella implements Unita {

    public function __construct(
        private readonly string $nome,
        private readonly string $percorso,
        private readonly string $accantona,
        private readonly ?Cifratura $cifratura = null,
        private readonly ?string $proprietario = null,
        private readonly array $escludi = [],
    ) {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,30}$/', $nome) || !str_starts_with($percorso, '/') || !str_starts_with($accantona, '/')) {
            throw new Eccezione('Nome o percorsi dell\'unità non validi.');
        }
    }

    public function nome(): string {
        return $this->nome;
    }

    public function descrizione(): string {
        return "Cartella {$this->percorso}" . ($this->cifratura ? ' (cifrata)' : '');
    }

    private function file(): string {
        return $this->nome . '.tar.gz' . ($this->cifratura ? '.cifrato' : '');
    }

    public function salva(string $cartella): array {
        if (!is_dir($this->percorso)) {
            throw new Eccezione("Cartella da salvare assente: {$this->percorso}");
        }
        $tar = "{$cartella}/{$this->nome}.tar.gz";
        $a = ['tar', '--numeric-owner', '-C', $this->percorso];
        foreach ($this->escludi as $e) {
            $a[] = '--exclude=./' . ltrim((string) $e, './');
        }
        Comando::riuscito([...$a, '-czf', $tar, '.']);
        if ($this->cifratura) {
            $this->cifratura->cifra($tar, "{$cartella}/" . $this->file());
            unlink($tar);
        }
        return [$this->file()];
    }

    /** Archivio in chiaro (decifrato in un file temporaneo se serve) e funzione che lo elimina. */
    private function archivio(string $cartella): array {
        $f = "{$cartella}/" . $this->file();
        if (!$this->cifratura) {
            return [$f, fn() => null];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pfibk');
        unlink($tmp);
        $this->cifratura->decifra($f, $tmp);
        return [$tmp, fn() => @unlink($tmp)];
    }

    public function ripristina(string $cartella): void {
        [$tar, $pulisci] = $this->archivio($cartella);
        try {
            if (!is_dir($this->percorso) && !mkdir($this->percorso, 0750, true)) {
                throw new Eccezione("Cartella da ripristinare non creata: {$this->percorso}");
            }
            $dest = rtrim($this->accantona, '/') . "/{$this->nome}-prima-del-ripristino-" . date('Ymd-His');
            if (!mkdir($dest, 0700, true)) {
                throw new Eccezione('Cartella per accantonare i dati attuali non creata.');
            }
            $voci = array_map(fn($v) => "{$this->percorso}/{$v}", array_values(array_diff(scandir($this->percorso), ['.', '..'])));
            if ($voci) {
                Comando::riuscito(['mv', '-t', $dest, '--', ...$voci]);
            }
            Comando::riuscito(['tar', '--numeric-owner', '-C', $this->percorso, '-xzf', $tar]);
            if ($this->proprietario !== null) {
                Comando::riuscito(['chown', '-R', $this->proprietario, $this->percorso]);
            }
        } finally {
            $pulisci();
        }
    }

    public function prova(string $cartella): void {
        [$tar, $pulisci] = $this->archivio($cartella);
        try {
            Comando::riuscito(['tar', '-tzf', $tar], null, '/dev/null');
        } finally {
            $pulisci();
        }
    }
}
