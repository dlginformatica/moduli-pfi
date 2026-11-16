<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Segreti;

use Pfi\Contratti\Eccezione;
use Pfi\Contratti\Registro;
use Pfi\Contratti\RegistroNullo;
use Pfi\Contratti\Segreti;

/**
 * Deposito cifrato dei segreti (release 9.0, blocco M1a; D-163).
 *
 * Formato compatibile con il deposito delle chiavi della release 8.0 (tool_pfisocieta, C-07): la chiave del deposito è un
 * file di testo di almeno 32 caratteri fuori dal database e dalla cartella dei dati; la chiave di cifratura è
 * BLAKE2b(testo, 32 byte); ogni voce è il file «<cartella>/<sha256 del nome>.cifrato» con nonce di 24 byte seguito dal
 * contenuto cifrato con XSalsa20-Poly1305 (sodium_crypto_secretbox). Il nome di una voce è di solito il percorso del file del
 * server che la voce sostituisce: con il ripiego attivo, una voce assente si legge dal file con quel nome.
 *
 * L'impronta è HMAC-SHA-256 del valore con la chiave, nella forma «xxxx-xxxx-xxxx»: riconosce un segreto senza mostrarlo e
 * non permette di indovinare un segreto corto.
 */
final class Deposito implements Segreti {

    private Registro $registro;

    public function __construct(
        private readonly string $filechiave,
        private readonly string $cartella,
        private readonly bool $ripiego = true,
        ?Registro $registro = null,
    ) {
        $this->registro = $registro ?? new RegistroNullo();
    }

    /** Il deposito è utilizzabile: chiave leggibile e abbastanza lunga, cartella delle voci presente. */
    public function attivo(): bool {
        return $this->testo_chiave() !== null && is_dir($this->cartella);
    }

    public function valore(string $nome): ?string {
        $voce = $this->voce($nome);
        if (is_string($voce)) {
            return $voce;
        }
        if ($voce === false) {
            $this->registro->registra(Registro::ERRORE, 'Voce del deposito non decifrabile: {voce}',
                ['modulo' => 'pfi/segreti', 'voce' => $this->impronta_nome($nome)]);
        }
        if ($this->ripiego && $nome !== '' && is_file($nome) && is_readable($nome)) {
            return (string) file_get_contents($nome);
        }
        return null;
    }

    public function presente(string $nome): bool {
        $v = $this->valore($nome);
        return $v !== null && trim($v) !== '';
    }

    public function impronta(string $nome): ?string {
        $v = $this->valore($nome);
        return $v === null ? null : $this->impronta_valore($v);
    }

    /** Impronta di un valore con la chiave del deposito. */
    public function impronta_valore(string $valore): string {
        return implode('-', str_split(substr(hash_hmac('sha256', trim($valore), $this->chiave()), 0, 12), 4));
    }

    /** La voce c'è nel deposito (indipendentemente dal ripiego sul file). */
    public function nel_deposito(string $nome): bool {
        return $nome !== '' && is_file($this->file_voce($nome));
    }

    /**
     * Scrive o sostituisce una voce, in modo atomico, leggibile solo dal proprietario del processo.
     *
     * @return string impronta del valore scritto
     */
    public function scrivi(string $nome, string $valore): string {
        if ($nome === '' || trim($valore) === '') {
            throw new Eccezione('Nome o valore del segreto vuoto');
        }
        if (!is_dir($this->cartella) || !is_writable($this->cartella)) {
            throw new Eccezione('Cartella del deposito non scrivibile');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $file = $this->file_voce($nome);
        $temporaneo = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $vecchia = umask(0077);
        try {
            if (file_put_contents($temporaneo, $nonce . sodium_crypto_secretbox($valore, $nonce, $this->chiave())) === false
                    || !rename($temporaneo, $file)) {
                @unlink($temporaneo);
                throw new Eccezione('Scrittura della voce del deposito non riuscita');
            }
        } finally {
            umask($vecchia);
        }
        $impronta = $this->impronta_valore($valore);
        sodium_memzero($valore);
        $this->registro->registra(Registro::NOTA, 'Voce del deposito scritta: {voce}, impronta {impronta}',
            ['modulo' => 'pfi/segreti', 'voce' => $this->impronta_nome($nome), 'impronta' => $impronta]);
        return $impronta;
    }

    /** Toglie una voce; restituisce false se non c'era. */
    public function togli(string $nome): bool {
        $file = $this->file_voce($nome);
        if (!is_file($file)) {
            return false;
        }
        $ok = unlink($file);
        $this->registro->registra(Registro::NOTA, 'Voce del deposito tolta: {voce}',
            ['modulo' => 'pfi/segreti', 'voce' => $this->impronta_nome($nome)]);
        return $ok;
    }

    /**
     * Cifra di nuovo tutte le voci con una chiave nuova (rotazione della chiave del deposito). Le voci si riscrivono in
     * modo atomico una per una; se una voce non si decifra con la chiave attuale la rotazione si ferma prima di toccare il file
     * della chiave. Il nuovo testo della chiave va poi scritto dal chiamante nel file della chiave.
     *
     * @return int voci cifrate di nuovo
     */
    public function ruota(string $nuovotesto): int {
        if (strlen(trim($nuovotesto)) < 32) {
            throw new Eccezione('La nuova chiave del deposito deve avere almeno 32 caratteri');
        }
        $vecchia = $this->chiave();
        $nuova = sodium_crypto_generichash(trim($nuovotesto), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $file = glob($this->cartella . '/*.cifrato') ?: [];
        $chiari = [];
        foreach ($file as $f) {
            $chiaro = self::apri((string) file_get_contents($f), $vecchia);
            if ($chiaro === false) {
                throw new Eccezione('Rotazione interrotta: una voce non si decifra con la chiave attuale (' . basename($f) . ')');
            }
            $chiari[$f] = $chiaro;
        }
        $vecchiaumask = umask(0077);
        try {
            foreach ($chiari as $f => $chiaro) {
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $t = $f . '.ruota.tmp';
                file_put_contents($t, $nonce . sodium_crypto_secretbox($chiaro, $nonce, $nuova));
                rename($t, $f);
                sodium_memzero($chiaro);
            }
        } finally {
            umask($vecchiaumask);
        }
        $this->registro->registra(Registro::AVVISO, 'Chiave del deposito ruotata: {voci} voci cifrate di nuovo',
            ['modulo' => 'pfi/segreti', 'voci' => count($chiari)]);
        return count($chiari);
    }

    /** Numero di voci nel deposito. */
    public function voci(): int {
        return count(glob($this->cartella . '/*.cifrato') ?: []);
    }

    /** File della chiave (per le prove di salute). */
    public function file_chiave(): string {
        return $this->filechiave;
    }

    /** Cartella delle voci (per le prove di salute). */
    public function cartella(): string {
        return $this->cartella;
    }

    /**
     * @return string|false|null contenuto; false se la voce non si decifra; null se non c'è
     */
    private function voce(string $nome): string|false|null {
        if ($nome === '' || !$this->attivo()) {
            return null;
        }
        $f = $this->file_voce($nome);
        if (!is_file($f) || !is_readable($f)) {
            return null;
        }
        return self::apri((string) file_get_contents($f), $this->chiave());
    }

    private static function apri(string $dati, string $chiave): string|false {
        $nonce = substr($dati, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        if (strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return false;
        }
        return sodium_crypto_secretbox_open(substr($dati, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $chiave);
    }

    private function file_voce(string $nome): string {
        return rtrim($this->cartella, '/') . '/' . hash('sha256', $nome) . '.cifrato';
    }

    /** Riferimento non reversibile a una voce per il registro: mai il nome in chiaro, che può essere un percorso. */
    private function impronta_nome(string $nome): string {
        return substr(hash('sha256', $nome), 0, 12);
    }

    private function testo_chiave(): ?string {
        $t = is_file($this->filechiave) && is_readable($this->filechiave) ? trim((string) file_get_contents($this->filechiave)) : '';
        return strlen($t) >= 32 ? $t : null;
    }

    private function chiave(): string {
        $t = $this->testo_chiave();
        if ($t === null) {
            throw new Eccezione('Chiave del deposito assente o troppo corta');
        }
        return sodium_crypto_generichash($t, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
