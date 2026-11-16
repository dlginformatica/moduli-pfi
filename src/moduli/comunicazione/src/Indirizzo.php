<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione;

use Pfi\Contratti\Eccezione;

/**
 * Destinatario o mittente: indirizzo di posta, identificativo di chat di Telegram, numero di WhatsApp o utente del
 * portale, secondo il canale; il nome è facoltativo.
 */
final class Indirizzo {

    public function __construct(public readonly string $valore, public readonly string $nome = '') {
        if ($valore === '' || preg_match('/[\r\n\0]/', $valore . $nome)) {
            throw new Eccezione('Indirizzo vuoto o con caratteri di controllo.');
        }
    }

    /** Indirizzo di posta controllato (anche con caratteri non ASCII nella parte locale e nel dominio). */
    public static function posta(string $email, string $nome = ''): self {
        $email = trim($email);
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE)) {
            throw new Eccezione('Indirizzo di posta non valido.');
        }
        return new self($email, trim($nome));
    }

    /** Forma per il registro degli invii: impronta breve, non reversibile senza conoscere l'indirizzo. */
    public function pseudonimo(string $sale = ''): string {
        return substr(hash('sha256', $sale . '|' . strtolower($this->valore)), 0, 16);
    }

    public function dominio(): string {
        $p = strrchr($this->valore, '@');
        return $p === false ? '' : substr($p, 1);
    }
}
