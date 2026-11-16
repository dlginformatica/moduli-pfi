<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Contratti;

/**
 * Registro dell'applicazione: interfaccia minima comune a tutti i moduli, con i livelli di RFC 5424 e la stessa forma di
 * PSR-3 (messaggio con segnaposto {chiave} e contesto). L'implementazione completa è il modulo pfi/registro; senza di
 * esso si usa RegistroNullo.
 */
interface Registro {

    public const EMERGENZA = 'emergency';
    public const ALLARME = 'alert';
    public const CRITICO = 'critical';
    public const ERRORE = 'error';
    public const AVVISO = 'warning';
    public const NOTA = 'notice';
    public const INFORMAZIONE = 'info';
    public const DEBUG = 'debug';

    /** Livelli in ordine di gravità decrescente, con il codice di gravità di RFC 5424. */
    public const LIVELLI = [self::EMERGENZA => 0, self::ALLARME => 1, self::CRITICO => 2, self::ERRORE => 3,
        self::AVVISO => 4, self::NOTA => 5, self::INFORMAZIONE => 6, self::DEBUG => 7];

    /**
     * Registra un evento. Il contesto non deve contenere segreti: il modulo pfi/registro li maschera comunque.
     *
     * @param string $livello uno dei livelli di LIVELLI
     * @param string $messaggio testo con segnaposto {chiave}
     * @param array<string,mixed> $contesto valori dei segnaposto e dati strutturati; «modulo» e «correlazione» sono riservati
     */
    public function registra(string $livello, string $messaggio, array $contesto = []): void;
}
