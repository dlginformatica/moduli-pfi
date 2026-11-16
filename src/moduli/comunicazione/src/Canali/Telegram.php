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
 * Telegram Bot API: sendMessage con il testo semplice (al massimo 4096 caratteri) verso l'identificativo di chat del
 * destinatario; getMe per la prova. Ricezione con webhook: il segreto indicato in setWebhook torna nell'intestazione
 * X-Telegram-Bot-Api-Secret-Token. Riferimento: core.telegram.org/bots/api. Il token è nel percorso dell'indirizzo:
 * il connettore lo toglie dal registro.
 */
final class Telegram implements Canale {

    public function __construct(private readonly Connettore $connettore, #[\SensitiveParameter] private readonly string $token) {
        if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token)) {
            throw new ErroreInvio('Token di Telegram non valido.', false);
        }
    }

    public function tipo(): string {
        return 'telegram';
    }

    public function nome(): string {
        return 'telegram';
    }

    private function url(string $metodo): string {
        return "https://api.telegram.org/bot{$this->token}/{$metodo}";
    }

    public function invia(Messaggio $m): Invio {
        $testo = $m->testo_semplice();
        if ($testo === '') {
            throw new ErroreInvio('Messaggio Telegram senza testo.', false);
        }
        $testo = mb_substr(($m->oggetto !== '' ? $m->oggetto . "\n\n" : '') . $testo, 0, 4096);
        $ultimo = new Invio();
        foreach ($m->destinatari as $d) {
            if (!preg_match('/^-?\d{1,20}$|^@[A-Za-z0-9_]{5,32}$/', $d->valore)) {
                throw new ErroreInvio('Identificativo di chat Telegram non valido.', false);
            }
            try {
                $r = $this->connettore->invia(Richiesta::json('POST', $this->url('sendMessage'),
                    ['chat_id' => $d->valore, 'text' => $testo, 'link_preview_options' => ['is_disabled' => true]]));
            } catch (ErroreConnettore $e) {
                $descr = json_decode($e->risposta?->corpo ?? '', true)['description'] ?? '';
                throw ErroreInvio::da_stato_http('telegram', $e->stato, mb_substr((string) $descr, 0, 120));
            }
            $ultimo = new Invio((string) (json_decode($r->corpo, true)['result']['message_id'] ?? ''), "accettato {$r->stato}");
        }
        return $ultimo;
    }

    public function salute(): EsitoSalute {
        try {
            $r = $this->connettore->invia(new Richiesta('GET', $this->url('getMe')), [401, 404]);
            $d = json_decode($r->corpo, true);
            return ($d['ok'] ?? false) ? EsitoSalute::buono('Bot Telegram riconosciuto.', ['bot' => (string) ($d['result']['username'] ?? '')])
                : EsitoSalute::guasto('Token di Telegram rifiutato.');
        } catch (ErroreConnettore $e) {
            return EsitoSalute::guasto('API di Telegram non raggiungibile.');
        }
    }

    /** Controlla il segreto del webhook in tempo costante. */
    public static function webhook_autentico(?string $intestazione, #[\SensitiveParameter] string $segreto): bool {
        return $intestazione !== null && $segreto !== '' && hash_equals($segreto, $intestazione);
    }

    /**
     * Messaggio di testo ricevuto da un aggiornamento del webhook, o null per gli altri tipi di aggiornamento.
     *
     * @return array{chat:string,da:string,testo:string,id:string}|null
     */
    public static function ricevuto(string $json): ?array {
        $d = json_decode($json, true);
        $msg = $d['message'] ?? null;
        if (!is_array($msg) || !isset($msg['chat']['id'], $msg['text'])) {
            return null;
        }
        return ['chat' => (string) $msg['chat']['id'], 'da' => (string) ($msg['from']['id'] ?? ''), 'testo' => (string) $msg['text'],
            'id' => (string) ($msg['message_id'] ?? '')];
    }
}
