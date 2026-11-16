<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Registro;

use Pfi\Contratti\Eccezione;

/**
 * Forma di un evento secondo RFC 5424 (The Syslog Protocol), § 6:
 * PRI VERSION SP TIMESTAMP SP HOSTNAME SP APP-NAME SP PROCID SP MSGID SP STRUCTURED-DATA [SP BOM MSG].
 *
 * Gli elementi di dati strutturati sono «pfi@32473» (modulo, correlazione, livello) e «pfictx@32473» (contesto scalare).
 * 32473 è il numero di impresa riservato agli esempi di documentazione (RFC 5612): va sostituito con un numero
 * assegnato dalla IANA se i dati strutturati si usano fuori dall'organizzazione [DA VERIFICARE].
 */
final class Rfc5424 {

    public const PEN = 32473;

    /** Codici di struttura (facility) di RFC 5424 § 6.2.1 ammessi dalla configurazione. */
    public const STRUTTURE = ['user' => 1, 'daemon' => 3, 'auth' => 4, 'authpriv' => 10, 'local0' => 16, 'local1' => 17,
        'local2' => 18, 'local3' => 19, 'local4' => 20, 'local5' => 21, 'local6' => 22, 'local7' => 23];

    private string $host;
    private string $app;

    public function __construct(
        private readonly string $struttura = 'local0',
        ?string $host = null,
        string $app = 'pfi',
        private readonly ?int $pid = null,
        private readonly int $pen = self::PEN,
    ) {
        if (!isset(self::STRUTTURE[$struttura])) {
            throw new Eccezione("Struttura syslog sconosciuta: «{$struttura}»");
        }
        $this->host = self::campo($host ?? (gethostname() ?: '-'), 255);
        $this->app = self::campo($app, 48);
    }

    /** Campo di intestazione: solo ASCII stampabile senza spazi (PRINTUSASCII), «-» se vuoto. */
    public static function campo(string $v, int $max): string {
        $v = preg_replace('/[^\x21-\x7e]/', '_', $v);
        return $v === '' ? '-' : substr($v, 0, $max);
    }

    /** Valore di un parametro di dati strutturati: «"», «\» e «]» preceduti da «\» (§ 6.3.3). */
    public static function valore_sd(string $v): string {
        return strtr($v, ['\\' => '\\\\', '"' => '\\"', ']' => '\\]']);
    }

    /** Nome di un parametro (SD-NAME): fino a 32 caratteri ASCII stampabili esclusi «=», spazio, «]» e «"». */
    public static function nome_sd(string $v): string {
        $v = preg_replace('/[^\x21-\x7e]|[="\]]/', '_', $v);
        return substr($v === '' ? '_' : $v, 0, 32);
    }

    public function app(): string {
        return $this->app;
    }

    public function struttura(): string {
        return $this->struttura;
    }

    public function priorita(Voce $voce): int {
        return self::STRUTTURE[$this->struttura] * 8 + $voce->gravita();
    }

    public function formatta(Voce $voce): string {
        $tempo = $voce->quando->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $msgid = self::campo(str_replace('pfi/', '', $voce->modulo), 32);
        $sd = '[pfi@' . $this->pen . ' modulo="' . self::valore_sd($voce->modulo) . '" correlazione="'
            . self::valore_sd($voce->correlazione) . '" livello="' . $voce->livello . '"]';
        $parametri = '';
        foreach ($voce->contesto as $k => $v) {
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
            $parametri .= ' ' . self::nome_sd((string) $k) . '="' . self::valore_sd((string) $v) . '"';
        }
        if ($parametri !== '') {
            $sd .= '[pfictx@' . $this->pen . $parametri . ']';
        }
        $procid = $this->pid !== null ? (string) $this->pid : (string) getmypid();
        return '<' . $this->priorita($voce) . '>1 ' . $tempo . ' ' . $this->host . ' ' . $this->app . ' '
            . self::campo($procid, 128) . ' ' . $msgid . ' ' . $sd . ' ' . "\xEF\xBB\xBF" . $voce->messaggio;
    }
}
