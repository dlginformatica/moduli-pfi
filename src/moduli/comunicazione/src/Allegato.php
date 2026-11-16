<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Comunicazione;

use Pfi\Contratti\Eccezione;

/** Allegato di un messaggio: nome del file, tipo MIME e contenuto. */
final class Allegato {

    public function __construct(public readonly string $nome, public readonly string $tipo, public readonly string $contenuto) {
        if ($nome === '' || preg_match('~[\r\n\0/\\\\]~', $nome) || !preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~i', $tipo)) {
            throw new Eccezione('Allegato con nome o tipo non validi.');
        }
    }

    public static function da_file(string $percorso, ?string $nome = null, ?string $tipo = null): self {
        if (!is_file($percorso) || !is_readable($percorso)) {
            throw new Eccezione('File da allegare non leggibile.');
        }
        $tipo ??= (function_exists('mime_content_type') ? mime_content_type($percorso) : false) ?: 'application/octet-stream';
        return new self($nome ?? basename($percorso), $tipo, (string) file_get_contents($percorso));
    }
}
