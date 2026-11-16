<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione\Canali;

use Pfi\Comunicazione\Canale;
use Pfi\Comunicazione\ErroreInvio;
use Pfi\Comunicazione\Invio;
use Pfi\Comunicazione\Messaggio;
use Pfi\Connettori\Connettore;
use Pfi\Connettori\ErroreConnettore;
use Pfi\Connettori\Richiesta;
use Pfi\Contratti\EsitoSalute;

/**
 * WhatsApp Business Platform, Cloud API: POST /{versione}/{identificativo del numero}/messages. Fuori dalla finestra
 * di 24 ore di una conversazione aperta dall'utente si possono inviare solo modelli approvati: $extra['modello']
 * (nome), $extra['lingua'] (codice, predefinito «it») e $extra['parametri'] (valori dei segnaposto del corpo, in
 * ordine). Con $extra['testo_libero'] vero si invia il testo semplice (solo dentro la finestra). Webhook con verifica
 * della firma X-Hub-Signature-256 (HMAC-SHA256 con il segreto dell'app). Riferimento:
 * developers.facebook.com/docs/whatsapp/cloud-api [DA VERIFICARE: versione delle API in uso al momento della
 * configurazione; quella predefinita qui è indicativa].
 */
final class WhatsApp implements Canale {

    public function __construct(
        private readonly Connettore $connettore,
        #[\SensitiveParameter] private readonly string $gettone,
        private readonly string $numeroid,
        private readonly string $versione = 'v21.0',
    ) {
        if (!preg_match('/^\d{5,20}$/', $numeroid) || !preg_match('/^v\d{1,3}\.\d$/', $versione)) {
            throw new ErroreInvio('Identificativo del numero o versione delle API di WhatsApp non validi.', false);
        }
    }

    public function tipo(): string {
        return 'whatsapp';
    }

    public function nome(): string {
        return 'whatsapp';
    }

    private function url(string $parte): string {
        return "https://graph.facebook.com/{$this->versione}/{$this->numeroid}{$parte}";
    }

    /** Numero nel formato internazionale senza «+», spazi né trattini. */
    public static function numero(string $n): string {
        $n = preg_replace('/[\s().-]/', '', $n);
        $n = preg_replace('/^(\+|00)/', '', $n);
        if (!preg_match('/^[1-9]\d{6,14}$/', $n)) {
            throw new ErroreInvio('Numero WhatsApp non valido.', false);
        }
        return $n;
    }

    public function invia(Messaggio $m): Invio {
        $ultimo = new Invio();
        foreach ($m->destinatari as $d) {
            $corpo = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => self::numero($d->valore)];
            if (!empty($m->extra['testo_libero'])) {
                $corpo += ['type' => 'text', 'text' => ['preview_url' => false, 'body' => mb_substr($m->testo_semplice(), 0, 4096)]];
            } else {
                $nome = (string) ($m->extra['modello'] ?? '');
                if (!preg_match('/^[a-z0-9_]{1,512}$/', $nome)) {
                    throw new ErroreInvio('WhatsApp: manca il nome del modello approvato.', false);
                }
                $t = ['name' => $nome, 'language' => ['code' => (string) ($m->extra['lingua'] ?? 'it')]];
                $parametri = array_map(fn($v) => ['type' => 'text', 'text' => (string) $v], array_values($m->extra['parametri'] ?? []));
                if ($parametri) {
                    $t['components'] = [['type' => 'body', 'parameters' => $parametri]];
                }
                $corpo += ['type' => 'template', 'template' => $t];
            }
            try {
                $r = $this->connettore->invia(Richiesta::json('POST', $this->url('/messages'), $corpo,
                    ['Authorization' => 'Bearer ' . $this->gettone]));
            } catch (ErroreConnettore $e) {
                $err = json_decode($e->risposta?->corpo ?? '', true)['error'] ?? [];
                throw ErroreInvio::da_stato_http('whatsapp', $e->stato, mb_substr(trim(($err['code'] ?? '') . ' ' . ($err['message'] ?? '')), 0, 120));
            }
            $ultimo = new Invio((string) (json_decode($r->corpo, true)['messages'][0]['id'] ?? ''), "accettato {$r->stato}");
        }
        return $ultimo;
    }

    public function salute(): EsitoSalute {
        try {
            $r = $this->connettore->invia(new Richiesta('GET', $this->url('?fields=display_phone_number'),
                ['Authorization' => 'Bearer ' . $this->gettone]), [400, 401, 403]);
            return $r->riuscita() ? EsitoSalute::buono('Numero WhatsApp riconosciuto.')
                : EsitoSalute::guasto("WhatsApp: gettone o numero rifiutati ({$r->stato}).");
        } catch (ErroreConnettore $e) {
            return EsitoSalute::guasto('API di WhatsApp non raggiungibili.');
        }
    }

    /** Firma X-Hub-Signature-256 del corpo del webhook, in tempo costante. */
    public static function webhook_autentico(string $corpo, ?string $intestazione, #[\SensitiveParameter] string $segretoapp): bool {
        if ($intestazione === null || $segretoapp === '' || !str_starts_with($intestazione, 'sha256=')) {
            return false;
        }
        return hash_equals('sha256=' . hash_hmac('sha256', $corpo, $segretoapp), $intestazione);
    }
}
