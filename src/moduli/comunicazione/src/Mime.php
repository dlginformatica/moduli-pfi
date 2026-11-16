<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione;

use Pfi\Contratti\Eccezione;

/**
 * Composizione di un messaggio di posta secondo RFC 5322 e MIME (RFC 2045-2047, RFC 2231): intestazioni con parole
 * codificate in UTF-8, corpo testo e HTML in quoted-printable dentro multipart/alternative, allegati in base64 dentro
 * multipart/mixed, righe terminate da CRLF. Il risultato serve a SMTP e alle API che accettano il messaggio intero
 * (Microsoft Graph, Gmail API, Amazon SES, Mailgun).
 */
final class Mime {

    /** @return array{0:string,1:string} messaggio completo e Message-ID (senza parentesi angolari) */
    public static function componi(Messaggio $m, ?\DateTimeInterface $data = null, ?string $confine = null): array {
        if ($m->mittente === null) {
            throw new Eccezione('Messaggio di posta senza mittente.');
        }
        if ($m->html === '' && $m->testo === '') {
            throw new Eccezione('Messaggio di posta senza corpo.');
        }
        $dominio = $m->mittente->dominio() ?: 'localhost';
        $id = bin2hex(random_bytes(12)) . '@' . $dominio;
        $confine ??= bin2hex(random_bytes(12));
        $h = [];
        $h[] = 'Date: ' . ($data ?? new \DateTimeImmutable())->format(DATE_RFC2822);
        $h[] = 'From: ' . self::indirizzo($m->mittente);
        $h[] = 'To: ' . implode(', ', array_map([self::class, 'indirizzo'], $m->destinatari));
        if ($m->cc) {
            $h[] = 'Cc: ' . implode(', ', array_map([self::class, 'indirizzo'], $m->cc));
        }
        if ($m->rispondia) {
            $h[] = 'Reply-To: ' . self::indirizzo($m->rispondia);
        }
        $h[] = 'Subject: ' . self::parola($m->oggetto, 9);
        $h[] = "Message-ID: <{$id}>";
        $h[] = 'MIME-Version: 1.0';
        foreach ($m->intestazioni as $k => $v) {
            $h[] = $k . ': ' . self::parola($v, strlen($k) + 2);
        }
        $alternativa = [];
        $testo = $m->testo_semplice();
        if ($testo !== '') {
            $alternativa[] = "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
                . self::qp($testo);
        }
        if ($m->html !== '') {
            $alternativa[] = "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
                . self::qp($m->html);
        }
        $corpo = count($alternativa) === 1 ? $alternativa[0]
            : "Content-Type: multipart/alternative; boundary=\"a{$confine}\"\r\n\r\n" . self::parti($alternativa, "a{$confine}");
        if ($m->allegati) {
            $parti = [$corpo];
            foreach ($m->allegati as $a) {
                $nome = self::nome_file($a->nome);
                $parti[] = "Content-Type: {$a->tipo}; name={$nome['semplice']}\r\nContent-Transfer-Encoding: base64\r\n"
                    . "Content-Disposition: attachment; {$nome['parametro']}\r\n\r\n" . chunk_split(base64_encode($a->contenuto), 76, "\r\n");
            }
            $corpo = "Content-Type: multipart/mixed; boundary=\"m{$confine}\"\r\n\r\n" . self::parti($parti, "m{$confine}");
        }
        return [implode("\r\n", $h) . "\r\n" . $corpo, $id];
    }

    private static function parti(array $parti, string $confine): string {
        $s = '';
        foreach ($parti as $p) {
            $s .= "--{$confine}\r\n" . $p . (str_ends_with($p, "\r\n") ? '' : "\r\n");
        }
        return $s . "--{$confine}--\r\n";
    }

    public static function indirizzo(Indirizzo $i): string {
        $email = self::email_ascii($i->valore);
        if ($i->nome === '') {
            return $email;
        }
        $nome = preg_match('/^[A-Za-z0-9 !#$%&\'*+\/=?^_`{|}~.-]+$/', $i->nome) ? '"' . $i->nome . '"' : self::parola($i->nome, 0);
        return $nome . ' <' . $email . '>';
    }

    /** Dominio internazionalizzato in forma ASCII (punycode) se l'estensione intl è disponibile. */
    private static function email_ascii(string $email): string {
        [$locale, $dominio] = explode('@', $email, 2) + [1 => ''];
        if (preg_match('/[^\x20-\x7e]/', $dominio) && function_exists('idn_to_ascii')) {
            $dominio = idn_to_ascii($dominio, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $dominio;
        }
        return $locale . '@' . $dominio;
    }

    /** Parola codificata RFC 2047 in base64 UTF-8 solo se serve, divisa in pezzi entro 75 caratteri. */
    public static function parola(string $s, int $gia): string {
        if (!preg_match('/[^\x20-\x7e]/', $s) && strlen($s) + $gia <= 78) {
            return $s;
        }
        $pezzi = [];
        $corrente = '';
        foreach (mb_str_split($s, 1, 'UTF-8') as $c) {
            if (strlen(base64_encode($corrente . $c)) > 45) {
                $pezzi[] = $corrente;
                $corrente = '';
            }
            $corrente .= $c;
        }
        $pezzi[] = $corrente;
        return implode("\r\n ", array_map(fn($p) => '=?UTF-8?B?' . base64_encode($p) . '?=', $pezzi));
    }

    /** @return array{semplice:string,parametro:string} */
    private static function nome_file(string $nome): array {
        if (!preg_match('/[^\x20-\x7e]|["\\\\]/', $nome)) {
            return ['semplice' => '"' . $nome . '"', 'parametro' => 'filename="' . $nome . '"'];
        }
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $nome);
        return ['semplice' => '"' . $ascii . '"', 'parametro' => "filename*=UTF-8''" . rawurlencode($nome)];
    }

    /** Quoted-printable con righe CRLF (il punto a inizio riga lo protegge il client SMTP). */
    public static function qp(string $s): string {
        $s = preg_replace("/\r\n|\r|\n/", "\r\n", $s);
        $q = quoted_printable_encode($s);
        return $q . (str_ends_with($q, "\r\n") ? '' : "\r\n");
    }
}
