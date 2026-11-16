<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione\Ricezione;

/**
 * Messaggio ricevuto in una casella di servizio: intestazioni principali decodificate, testo semplice, eventuale
 * rapporto di mancata consegna (RFC 3464) con destinatario e stato.
 */
final class Ricevuto {

    /**
     * @param array{destinatario:string,stato:string,azione:string}|null $rimbalzo
     */
    public function __construct(
        public readonly string $id,
        public readonly string $mittente,
        public readonly string $oggetto,
        public readonly string $data,
        public readonly string $messageid,
        public readonly string $inrisposta,
        public readonly string $testo,
        public readonly ?array $rimbalzo,
        public readonly int $dimensione,
        public readonly string $correlazione = '',
    ) {
    }

    /** Analizza un messaggio RFC 5322 completo. */
    public static function da_mime(string $id, string $grezzo): self {
        [$h, $corpo] = self::separa($grezzo);
        $tipo = $h['content-type'] ?? 'text/plain';
        $rimbalzo = null;
        $testo = '';
        if (preg_match('~^multipart/~i', $tipo) && preg_match('~boundary="?([^";\s]+)"?~i', $tipo, $b)) {
            foreach (self::parti($corpo, $b[1]) as $parte) {
                [$ph, $pc] = self::separa($parte);
                $pt = strtolower($ph['content-type'] ?? 'text/plain');
                if ($testo === '' && str_starts_with($pt, 'text/plain')) {
                    $testo = self::decodifica($pc, $ph);
                } elseif (str_starts_with($pt, 'message/delivery-status')) {
                    $rimbalzo = self::stato_consegna(self::decodifica($pc, $ph));
                } elseif ($testo === '' && str_starts_with($pt, 'multipart/') && preg_match('~boundary="?([^";\s]+)"?~i', $ph['content-type'], $bb)) {
                    foreach (self::parti($pc, $bb[1]) as $sotto) {
                        [$sh, $sc] = self::separa($sotto);
                        if (str_starts_with(strtolower($sh['content-type'] ?? 'text/plain'), 'text/plain')) {
                            $testo = self::decodifica($sc, $sh);
                            break;
                        }
                    }
                }
            }
        } else {
            $testo = self::decodifica($corpo, $h);
            if (str_starts_with(strtolower($tipo), 'text/html')) {
                $testo = trim(html_entity_decode(strip_tags($testo), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        $dec = fn(string $k) => isset($h[$k]) ? trim(iconv_mime_decode($h[$k], ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: $h[$k]) : '';
        return new self($id, $dec('from'), $dec('subject'), $dec('date'), trim($dec('message-id'), '<>'),
            trim($dec('in-reply-to'), '<> '), mb_substr($testo, 0, 100000), $rimbalzo, strlen($grezzo), $dec('x-pfi-correlazione'));
    }

    /** @return array{0:array<string,string>,1:string} */
    private static function separa(string $s): array {
        $s = preg_replace("/\r\n|\r/", "\n", $s);
        $pos = strpos($s, "\n\n");
        $testa = $pos === false ? $s : substr($s, 0, $pos);
        $corpo = $pos === false ? '' : substr($s, $pos + 2);
        $h = [];
        foreach (explode("\n", preg_replace("/\n[ \t]+/", ' ', $testa)) as $riga) {
            if (str_contains($riga, ':')) {
                [$k, $v] = explode(':', $riga, 2);
                $h[strtolower(trim($k))] ??= trim($v);
            }
        }
        return [$h, $corpo];
    }

    /** @return list<string> */
    private static function parti(string $corpo, string $confine): array {
        $pezzi = explode('--' . $confine, preg_replace("/\r\n|\r/", "\n", $corpo));
        array_shift($pezzi);
        $fuori = [];
        foreach ($pezzi as $p) {
            if (str_starts_with($p, '--')) {
                break;
            }
            $fuori[] = ltrim($p, "\n");
        }
        return $fuori;
    }

    private static function decodifica(string $c, array $h): string {
        $codifica = strtolower(trim($h['content-transfer-encoding'] ?? ''));
        $c = match ($codifica) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $c), false),
            'quoted-printable' => quoted_printable_decode($c),
            default => $c,
        };
        if (preg_match('~charset="?([A-Za-z0-9_-]+)~i', $h['content-type'] ?? '', $cs) && strtoupper($cs[1]) !== 'UTF-8') {
            $c = @mb_convert_encoding($c, 'UTF-8', $cs[1]) ?: $c;
        }
        return trim($c);
    }

    /** @return array{destinatario:string,stato:string,azione:string} */
    private static function stato_consegna(string $s): array {
        $v = fn(string $k) => preg_match('~^' . $k . ':\s*(?:[a-z0-9-]+;\s*)?(.+)$~mi', $s, $m) ? trim($m[1]) : '';
        return ['destinatario' => $v('Final-Recipient'), 'stato' => $v('Status'), 'azione' => strtolower($v('Action'))];
    }
}
