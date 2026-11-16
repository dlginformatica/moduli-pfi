<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione;

use Pfi\Contratti\Eccezione;

/**
 * Messaggio pronto per un canale: le parti vengono di solito da un modello reso (pfi/modelli). Per la posta servono
 * mittente, oggetto e almeno uno fra html e testo; per Telegram e notifica il testo; per WhatsApp il nome del modello
 * approvato e i suoi parametri in $extra.
 */
final class Messaggio {

    /**
     * @param list<Indirizzo> $destinatari
     * @param list<Indirizzo> $cc
     * @param list<Indirizzo> $ccn
     * @param list<Allegato> $allegati
     * @param array<string,string> $intestazioni intestazioni aggiuntive (solo X-…, List-Unsubscribe, Auto-Submitted)
     * @param array<string,mixed> $extra dati propri del canale
     */
    public function __construct(
        public readonly array $destinatari,
        public readonly string $oggetto = '',
        public readonly string $html = '',
        public readonly string $testo = '',
        public readonly ?Indirizzo $mittente = null,
        public readonly array $cc = [],
        public readonly array $ccn = [],
        public readonly array $allegati = [],
        public readonly ?Indirizzo $rispondia = null,
        public readonly array $intestazioni = [],
        public readonly array $extra = [],
    ) {
        if (!$destinatari) {
            throw new Eccezione('Messaggio senza destinatari.');
        }
        foreach ([...$destinatari, ...$cc, ...$ccn] as $d) {
            if (!$d instanceof Indirizzo) {
                throw new Eccezione('Destinatario non valido.');
            }
        }
        foreach ($intestazioni as $k => $v) {
            if (!preg_match('/^(X-[A-Za-z0-9-]{1,60}|List-Unsubscribe|List-Unsubscribe-Post|Auto-Submitted)$/', (string) $k)
                || preg_match('/[\r\n\0]/', (string) $v)) {
                throw new Eccezione('Intestazione aggiuntiva non ammessa.');
            }
        }
    }

    /** Testo semplice: quello dato o, in mancanza, ricavato dall'HTML. */
    public function testo_semplice(): string {
        if ($this->testo !== '' || $this->html === '') {
            return $this->testo;
        }
        $t = preg_replace(['~<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>~i', '~<li\b[^>]*>~i', '~<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>~is'],
            ["\n", '- ', '$2 ($1)'], $this->html);
        $t = html_entity_decode(strip_tags((string) $t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t]+/u", ' ', $t);
        return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $t));
    }

    /** Stesso messaggio con altri destinatari (per l'invio uno per destinatario). */
    public function per(Indirizzo ...$destinatari): self {
        return new self(array_values($destinatari), $this->oggetto, $this->html, $this->testo, $this->mittente, [], [],
            $this->allegati, $this->rispondia, $this->intestazioni, $this->extra);
    }

    /** Forma serializzabile per la coda degli invii (allegati in base64). */
    public function a_array(): array {
        $i = fn(?Indirizzo $x) => $x === null ? null : [$x->valore, $x->nome];
        return ['destinatari' => array_map($i, $this->destinatari), 'oggetto' => $this->oggetto, 'html' => $this->html,
            'testo' => $this->testo, 'mittente' => $i($this->mittente), 'cc' => array_map($i, $this->cc), 'ccn' => array_map($i, $this->ccn),
            'allegati' => array_map(fn($a) => [$a->nome, $a->tipo, base64_encode($a->contenuto)], $this->allegati),
            'rispondia' => $i($this->rispondia), 'intestazioni' => $this->intestazioni, 'extra' => $this->extra];
    }

    public static function da_array(array $d): self {
        $i = fn(?array $x) => $x === null ? null : new Indirizzo((string) $x[0], (string) $x[1]);
        return new self(array_map($i, $d['destinatari'] ?? []), (string) ($d['oggetto'] ?? ''), (string) ($d['html'] ?? ''),
            (string) ($d['testo'] ?? ''), $i($d['mittente'] ?? null), array_map($i, $d['cc'] ?? []), array_map($i, $d['ccn'] ?? []),
            array_map(fn($a) => new Allegato($a[0], $a[1], (string) base64_decode($a[2], true)), $d['allegati'] ?? []),
            $i($d['rispondia'] ?? null), $d['intestazioni'] ?? [], $d['extra'] ?? []);
    }

    public function con_mittente(Indirizzo $mittente): self {
        return new self($this->destinatari, $this->oggetto, $this->html, $this->testo, $mittente, $this->cc, $this->ccn,
            $this->allegati, $this->rispondia, $this->intestazioni, $this->extra);
    }
}
