<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Gestore;

use Pfi\Contratti\Eccezione;
use Pfi\Contratti\Manifesto;

/**
 * Pacchetto firmato di un modulo universale (D-162): archivio `<nome>-<versione>.tar.gz` con la cartella del modulo
 * (modulo.json, src/, LEGGIMI.md, CHANGELOG.md…) e file `.firma` in JSON con nome, versione, impronta SHA-256
 * dell'archivio e firma Ed25519 dell'impronta (libsodium). La chiave pubblica di verifica sta sul server; quella
 * privata resta a chi pubblica i moduli e non entra mai nel portale.
 */
final class Pacchetto {

    /** @return array{0:string,1:string} chiave pubblica e privata, in base64 */
    public static function genera_chiavi(): array {
        $c = sodium_crypto_sign_keypair();
        return [base64_encode(sodium_crypto_sign_publickey($c)), base64_encode(sodium_crypto_sign_secretkey($c))];
    }

    /** Crea il pacchetto di un modulo da una cartella; restituisce il percorso dell'archivio (la firma accanto). */
    public static function crea(string $cartella, string $destinazione, #[\SensitiveParameter] string $privata): string {
        $m = Manifesto::da_file(rtrim($cartella, '/') . '/modulo.json');
        $nome = str_replace('pfi/', '', $m->nome);
        $archivio = rtrim($destinazione, '/') . "/{$nome}-{$m->versione}.tar.gz";
        $p = proc_open(['tar', '--owner=0', '--group=0', '--numeric-owner', '--exclude=.phpunit.cache', '--exclude=.git', '-C', $cartella,
            '-czf', $archivio, '.'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $t);
        if (proc_close($p) !== 0) {
            throw new Eccezione('Archivio del modulo non creato.');
        }
        $impronta = hash_file('sha256', $archivio);
        $firma = sodium_crypto_sign_detached($impronta, base64_decode($privata, true) ?: throw new Eccezione('Chiave privata non valida.'));
        file_put_contents($archivio . '.firma', json_encode(['nome' => $m->nome, 'versione' => (string) $m->versione,
            'sha256' => $impronta, 'firma' => base64_encode($firma)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return $archivio;
    }

    /**
     * Verifica firma e impronta, estrae in una cartella temporanea e controlla che il manifesto corrisponda alla firma.
     *
     * @return array{0:Manifesto,1:string} manifesto e cartella estratta (da spostare o eliminare)
     */
    public static function verifica(string $archivio, string $pubblica, string $temporanea): array {
        $f = json_decode((string) @file_get_contents($archivio . '.firma'), true);
        if (!is_array($f) || !isset($f['nome'], $f['versione'], $f['sha256'], $f['firma'])) {
            throw new Eccezione('Firma del pacchetto assente o non leggibile.');
        }
        $impronta = hash_file('sha256', $archivio);
        $chiave = base64_decode($pubblica, true);
        $firma = base64_decode((string) $f['firma'], true);
        if ($impronta === false || !hash_equals((string) $f['sha256'], $impronta) || $chiave === false || $firma === false
            || strlen($chiave) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !sodium_crypto_sign_verify_detached($firma, $impronta, $chiave)) {
            throw new Eccezione('Firma del pacchetto non valida: il modulo non si installa.');
        }
        $elenco = (string) shell_exec('tar -tzf ' . escapeshellarg($archivio) . ' 2>/dev/null');
        foreach (array_filter(explode("\n", $elenco)) as $voce) {
            if (str_starts_with($voce, '/') || preg_match('~(^|/)\.\.(/|$)~', $voce)) {
                throw new Eccezione('Pacchetto con percorsi non ammessi.');
            }
        }
        if (!mkdir($temporanea, 0755, true)) {
            throw new Eccezione('Cartella temporanea del pacchetto non creata.');
        }
        $p = proc_open(['tar', '--no-same-owner', '--no-same-permissions', '-C', $temporanea, '-xzf', $archivio],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $t);
        if (proc_close($p) !== 0) {
            throw new Eccezione('Pacchetto non estraibile.');
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temporanea, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $voce) {
            if ($voce->isLink()) {
                throw new Eccezione('Pacchetto con collegamenti simbolici: non ammesso.');
            }
        }
        $m = Manifesto::da_file("{$temporanea}/modulo.json");
        if ($m->nome !== $f['nome'] || (string) $m->versione !== $f['versione']) {
            throw new Eccezione('Il manifesto del pacchetto non corrisponde alla firma.');
        }
        return [$m, $temporanea];
    }
}
