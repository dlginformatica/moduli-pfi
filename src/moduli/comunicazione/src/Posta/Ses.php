<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione\Posta;

use Pfi\Comunicazione\ErroreInvio;
use Pfi\Comunicazione\Invio;
use Pfi\Comunicazione\Messaggio;
use Pfi\Comunicazione\Mime;
use Pfi\Connettori\Connettore;
use Pfi\Connettori\Richiesta;
use Pfi\Connettori\Risposta;

/**
 * Amazon SES con l'API v2: POST /v2/email/outbound-emails con Content.Raw.Data (messaggio MIME in base64), firma
 * AWS Signature Version 4 per il servizio «ses». Riferimento: docs.aws.amazon.com/ses/latest/APIReference-V2/
 * API_SendEmail.html, verificato il 14 novembre 2026.
 */
final class Ses extends Api {

    /** @param \Closure():\DateTimeImmutable|null $orologio */
    public function __construct(
        Connettore $c,
        private readonly string $regione,
        private readonly string $accesso,
        #[\SensitiveParameter] private readonly string $segreto,
        private readonly ?\Closure $orologio = null,
    ) {
        parent::__construct($c);
        if (!preg_match('/^[a-z]{2}(-[a-z]+)+-\d$/', $regione)) {
            throw new ErroreInvio('Regione AWS non valida.', false);
        }
    }

    protected function fornitore(): string {
        return 'ses';
    }

    private function host(): string {
        return "email.{$this->regione}.amazonaws.com";
    }

    protected function richiesta(Messaggio $m): Richiesta {
        [$mime] = Mime::componi($m);
        $corpo = json_encode(['Content' => ['Raw' => ['Data' => base64_encode($mime)]]], JSON_UNESCAPED_SLASHES);
        return $this->firmata('POST', '/v2/email/outbound-emails', $corpo);
    }

    protected function richiesta_prova(): Richiesta {
        return $this->firmata('GET', '/v2/email/account', '');
    }

    /** Richiesta firmata con AWS Signature Version 4 (intestazioni host, x-amz-date, x-amz-content-sha256). */
    public function firmata(string $metodo, string $percorso, string $corpo): Richiesta {
        $ora = ($this->orologio ? ($this->orologio)() : new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        $data = $ora->format('Ymd\THis\Z');
        $giorno = $ora->format('Ymd');
        $hash = hash('sha256', $corpo);
        $intestazioni = ['content-type' => 'application/json', 'host' => $this->host(), 'x-amz-content-sha256' => $hash, 'x-amz-date' => $data];
        $firmate = implode(';', array_keys($intestazioni));
        $canonica = implode("\n", [$metodo, $percorso, '', implode('', array_map(fn($k, $v) => "{$k}:{$v}\n",
            array_keys($intestazioni), $intestazioni)), $firmate, $hash]);
        $ambito = "{$giorno}/{$this->regione}/ses/aws4_request";
        $dafirmare = "AWS4-HMAC-SHA256\n{$data}\n{$ambito}\n" . hash('sha256', $canonica);
        $firma = hash_hmac('sha256', $dafirmare, self::chiave_firma($this->segreto, $giorno, $this->regione, 'ses'));
        return new Richiesta($metodo, 'https://' . $this->host() . $percorso, [
            'Content-Type' => 'application/json', 'X-Amz-Date' => $data, 'X-Amz-Content-Sha256' => $hash,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$this->accesso}/{$ambito}, SignedHeaders={$firmate}, Signature={$firma}",
        ], $metodo === 'GET' ? null : $corpo);
    }

    /** Chiave di firma di Signature Version 4: HMAC a catena di data, regione, servizio e «aws4_request». */
    public static function chiave_firma(#[\SensitiveParameter] string $segreto, string $giorno, string $regione, string $servizio): string {
        $k = hash_hmac('sha256', $giorno, 'AWS4' . $segreto, true);
        $k = hash_hmac('sha256', $regione, $k, true);
        $k = hash_hmac('sha256', $servizio, $k, true);
        return hash_hmac('sha256', 'aws4_request', $k, true);
    }

    protected function esito(Risposta $r, Messaggio $m): Invio {
        return new Invio((string) (self::json($r)['MessageId'] ?? ''), "accettato {$r->stato}");
    }
}
