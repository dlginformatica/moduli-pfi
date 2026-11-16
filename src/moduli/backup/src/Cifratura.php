<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Backup;

use Pfi\Contratti\Eccezione;

/**
 * Cifratura dei file del backup con libsodium (XChaCha20-Poly1305 a flusso, crypto_secretstream): intestazione
 * «PFIC1», 24 byte di intestazione del flusso, poi blocchi di 64 KiB autenticati; l'ultimo porta l'etichetta finale,
 * così un file troncato non si decifra. La chiave è un file di 32 byte casuali (o di testo da cui si ricava con
 * BLAKE2b) conservato fuori dal portale e fuori dal backup.
 */
final class Cifratura {

    private const MAGIA = 'PFIC1';
    private const BLOCCO = 65536;

    private string $chiave;

    public function __construct(#[\SensitiveParameter] string $chiave) {
        if (strlen($chiave) < 32) {
            throw new Eccezione('Chiave di cifratura del backup troppo corta.');
        }
        $this->chiave = strlen($chiave) === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES ? $chiave
            : sodium_crypto_generichash($chiave, '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
    }

    public static function da_file(string $file): self {
        $st = @stat($file);
        if ($st === false || !is_readable($file)) {
            throw new Eccezione('Chiave di cifratura del backup non leggibile.');
        }
        if (($st['mode'] & 0077) !== 0) {
            throw new Eccezione('La chiave di cifratura del backup deve essere leggibile solo dal proprietario (0600).');
        }
        return new self((string) file_get_contents($file));
    }

    /** Crea un file di chiave nuovo (0600). */
    public static function genera(string $file): void {
        $vecchia = umask(0077);
        try {
            if (file_exists($file) || file_put_contents($file, random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)) === false) {
                throw new Eccezione('Chiave del backup non creata (il file esiste già o la cartella non è scrivibile).');
            }
        } finally {
            umask($vecchia);
        }
    }

    public function cifra(string $da, string $a): void {
        $in = fopen($da, 'rb');
        $out = fopen($a, 'xb');
        if (!$in || !$out) {
            throw new Eccezione('File da cifrare non leggibile o destinazione già esistente.');
        }
        [$stato, $testa] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->chiave);
        fwrite($out, self::MAGIA . $testa);
        do {
            $blocco = (string) fread($in, self::BLOCCO);
            $fine = feof($in);
            fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push($stato, $blocco, '',
                $fine ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE));
        } while (!$fine);
        fclose($in);
        fclose($out);
    }

    public function decifra(string $da, string $a): void {
        $in = fopen($da, 'rb');
        if (!$in || fread($in, 5) !== self::MAGIA) {
            throw new Eccezione('File non cifrato con la cifratura del backup.');
        }
        $testa = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $stato = sodium_crypto_secretstream_xchacha20poly1305_init_pull($testa, $this->chiave);
        $out = fopen($a, 'xb');
        $finito = false;
        try {
            while (!feof($in)) {
                $c = (string) fread($in, self::BLOCCO + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
                if ($c === '') {
                    break;
                }
                $r = sodium_crypto_secretstream_xchacha20poly1305_pull($stato, $c);
                if ($r === false) {
                    throw new Eccezione('Decifratura non riuscita: chiave errata o file alterato.');
                }
                fwrite($out, $r[0]);
                if ($r[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $finito = true;
                    break;
                }
            }
            if (!$finito) {
                throw new Eccezione('File cifrato troncato.');
            }
        } catch (Eccezione $e) {
            fclose($out);
            @unlink($a);
            throw $e;
        } finally {
            fclose($in);
        }
        fclose($out);
    }
}
