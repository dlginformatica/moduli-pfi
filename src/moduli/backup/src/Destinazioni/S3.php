<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Backup\Destinazioni;

use Pfi\Backup\Backup;
use Pfi\Backup\Destinazione;
use Pfi\Contratti\Eccezione;

/**
 * Archivio di oggetti compatibile con Amazon S3 (anche MinIO, Ceph e servizi analoghi) con indirizzi di tipo
 * percorso (https://endpoint/contenitore/chiave) e firma AWS Signature Version 4. I file si caricano in streaming con
 * PUT e x-amz-content-sha256 UNSIGNED-PAYLOAD (ammesso solo su HTTPS); dopo ogni caricamento si confronta l'ETag con
 * l'MD5 del file. Un singolo oggetto è limitato a 5 GiB (PUT senza caricamento in più parti) [DA VERIFICARE: limiti
 * del servizio scelto dalla conduzione dei sistemi].
 */
final class S3 implements Destinazione {

    public function __construct(
        private readonly string $endpoint,
        private readonly string $contenitore,
        private readonly string $regione,
        private readonly string $accesso,
        #[\SensitiveParameter] private readonly string $segreto,
        private readonly string $prefisso = 'pfi-backup',
        private readonly ?string $ca = null,
        private readonly bool $consentihttp = false,
    ) {
        $schema = parse_url($endpoint, PHP_URL_SCHEME);
        if (!in_array($schema, $consentihttp ? ['https', 'http'] : ['https'], true) || !preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $contenitore)) {
            throw new Eccezione('Indirizzo S3 (solo https) o nome del contenitore non validi.');
        }
    }

    public function nome(): string {
        return 's3 ' . parse_url($this->endpoint, PHP_URL_HOST) . '/' . $this->contenitore;
    }

    private static function codifica(string $s): string {
        return str_replace('%2F', '/', rawurlencode($s));
    }

    /**
     * @param array<string,string> $query
     * @return array{0:string,1:list<string>} indirizzo e intestazioni
     */
    private function firma(string $metodo, string $chiave, array $query = [], string $hash = 'UNSIGNED-PAYLOAD'): array {
        $host = parse_url($this->endpoint, PHP_URL_HOST) . (parse_url($this->endpoint, PHP_URL_PORT) ? ':' . parse_url($this->endpoint, PHP_URL_PORT) : '');
        $percorso = '/' . $this->contenitore . ($chiave !== '' ? '/' . self::codifica($chiave) : '');
        ksort($query);
        $qs = implode('&', array_map(fn($k, $v) => rawurlencode($k) . '=' . rawurlencode($v), array_keys($query), $query));
        $ora = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $data = $ora->format('Ymd\THis\Z');
        $giorno = $ora->format('Ymd');
        $canonica = "{$metodo}\n{$percorso}\n{$qs}\nhost:{$host}\nx-amz-content-sha256:{$hash}\nx-amz-date:{$data}\n\nhost;x-amz-content-sha256;x-amz-date\n{$hash}";
        $ambito = "{$giorno}/{$this->regione}/s3/aws4_request";
        $k = hash_hmac('sha256', $giorno, 'AWS4' . $this->segreto, true);
        foreach ([$this->regione, 's3', 'aws4_request'] as $p) {
            $k = hash_hmac('sha256', $p, $k, true);
        }
        $firma = hash_hmac('sha256', "AWS4-HMAC-SHA256\n{$data}\n{$ambito}\n" . hash('sha256', $canonica), $k);
        return [rtrim($this->endpoint, '/') . $percorso . ($qs !== '' ? '?' . $qs : ''), [
            "x-amz-content-sha256: {$hash}", "x-amz-date: {$data}",
            "Authorization: AWS4-HMAC-SHA256 Credential={$this->accesso}/{$ambito}, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature={$firma}"]];
    }

    /** @return array{0:int,1:string,2:array<string,string>} stato, corpo, intestazioni */
    private function chiama(string $metodo, string $chiave, array $query = [], ?string $carica = null, ?string $salva = null): array {
        [$url, $intest] = $this->firma($metodo, $chiave, $query, $metodo === 'PUT' ? 'UNSIGNED-PAYLOAD' : hash('sha256', ''));
        $h = curl_init($url);
        $risposte = [];
        $opz = [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => [...$intest, 'Expect:'], CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 0, CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 120, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function($c, $r) use (&$risposte) {
                if (str_contains($r, ':')) {
                    [$k, $v] = explode(':', $r, 2);
                    $risposte[strtolower(trim($k))] = trim($v);
                }
                return strlen($r);
            }];
        if ($this->ca) {
            $opz[CURLOPT_CAINFO] = $this->ca;
        }
        $in = $out = null;
        if ($carica !== null) {
            $in = fopen($carica, 'rb');
            $opz += [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $in, CURLOPT_INFILESIZE => filesize($carica)];
        }
        if ($salva !== null) {
            $out = fopen($salva, 'xb');
            $opz[CURLOPT_FILE] = $out;
        } else {
            $opz[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($h, $opz);
        $corpo = curl_exec($h);
        $stato = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $errore = curl_error($h);
        curl_close($h);
        $in && fclose($in);
        $out && fclose($out);
        if ($corpo === false) {
            throw new Eccezione("S3 {$metodo}: errore di rete ({$errore}).");
        }
        return [$stato, is_string($corpo) ? $corpo : '', $risposte];
    }

    public function invia(string $cartella, string $id): void {
        $file = Backup::file_di($cartella);
        usort($file, fn($a, $b) => ($a === 'SHA256SUMS') <=> ($b === 'SHA256SUMS'));
        foreach ($file as $f) {
            if (filesize("{$cartella}/{$f}") > 5 * 1024 ** 3) {
                throw new Eccezione("File {$f} oltre 5 GiB: serve il caricamento in più parti, non disponibile in questa versione.");
            }
            [$s, , $h] = $this->chiama('PUT', "{$this->prefisso}/{$id}/{$f}", [], "{$cartella}/{$f}");
            if ($s !== 200) {
                throw new Eccezione("S3: caricamento di {$f} non riuscito ({$s}).");
            }
            if (isset($h['etag']) && preg_match('/^"?([0-9a-f]{32})"?$/', $h['etag'], $m) && $m[1] !== md5_file("{$cartella}/{$f}")) {
                throw new Eccezione("S3: impronta di {$f} diversa dopo il caricamento.");
            }
        }
    }

    public function elenco(): array {
        $ids = [];
        $continua = null;
        do {
            $q = ['list-type' => '2', 'prefix' => $this->prefisso . '/', 'delimiter' => '/'] + ($continua ? ['continuation-token' => $continua] : []);
            [$s, $corpo] = $this->chiama('GET', '', $q);
            if ($s !== 200) {
                throw new Eccezione("S3: elenco non riuscito ({$s}).");
            }
            $x = @simplexml_load_string($corpo);
            if ($x === false) {
                throw new Eccezione('S3: risposta dell\'elenco non leggibile.');
            }
            foreach ($x->CommonPrefixes ?? [] as $p) {
                $id = basename(rtrim((string) $p->Prefix, '/'));
                if (Backup::valido($id)) {
                    $ids[] = $id;
                }
            }
            $continua = (string) ($x->NextContinuationToken ?? '') ?: null;
        } while ($continua);
        return $ids;
    }

    /** @return list<string> */
    private function oggetti(string $id): array {
        [$s, $corpo] = $this->chiama('GET', '', ['list-type' => '2', 'prefix' => "{$this->prefisso}/{$id}/"]);
        if ($s !== 200 || ($x = @simplexml_load_string($corpo)) === false) {
            throw new Eccezione("S3: elenco dei file del backup {$id} non riuscito ({$s}).");
        }
        $o = [];
        foreach ($x->Contents ?? [] as $c) {
            $o[] = (string) $c->Key;
        }
        return $o;
    }

    public function elimina(string $id): void {
        foreach (Backup::valido($id) ? $this->oggetti($id) : [] as $k) {
            [$s] = $this->chiama('DELETE', $k);
            if (!in_array($s, [200, 204], true)) {
                throw new Eccezione("S3: eliminazione non riuscita ({$s}).");
            }
        }
    }

    public function scarica(string $id, string $cartella): void {
        $o = Backup::valido($id) ? $this->oggetti($id) : [];
        if (!$o) {
            throw new Eccezione("Backup {$id} assente nella destinazione.");
        }
        mkdir($cartella, 0700, true);
        foreach ($o as $k) {
            $f = basename($k);
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $f)) {
                continue;
            }
            [$s] = $this->chiama('GET', $k, [], null, "{$cartella}/{$f}");
            if ($s !== 200) {
                throw new Eccezione("S3: scaricamento di {$f} non riuscito ({$s}).");
            }
        }
    }
}
