<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Modelli;

use Pfi\Contratti\Eccezione;

/**
 * Modello di un tipo (chiave dello schema) per un canale e una lingua, con le sue parti (per la posta «oggetto»,
 * «html» e facoltativamente «testo»; per Telegram e notifica «testo»; per la stampa «html»). Le parti il cui nome
 * termina con «html» si rendono con l'escape HTML; le altre come testo; l'oggetto su una sola riga.
 */
final class Modello {

    /** @var array<string,list<array>> */
    private array $alberi = [];

    /**
     * @param array<string,string> $parti
     */
    public function __construct(
        public readonly string $chiave,
        public readonly string $canale,
        public readonly string $lingua,
        public readonly array $parti,
        public readonly int $versione = 0,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', $canale) || !preg_match('/^[a-z]{2}(_[a-z]{2})?$/', $lingua)) {
            throw new Eccezione('Canale o lingua del modello non validi.');
        }
        if (!$parti) {
            throw new Eccezione('Il modello non ha parti.');
        }
        foreach ($parti as $nome => $testo) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', (string) $nome)) {
                throw new Eccezione('Nome di parte del modello non valido.');
            }
            try {
                $this->alberi[$nome] = Motore::analizza((string) $testo);
            } catch (ErroreModello $e) {
                throw new ErroreModello("Parte «{$nome}»: " . preg_replace('/ \(riga \d+, colonna \d+\)$/', '', $e->getMessage()),
                    $e->riga, $e->colonna);
            }
        }
    }

    public static function html(string $nome): bool {
        return str_ends_with($nome, 'html');
    }

    /** @return array<string,string> parti rese */
    public function rendi(array $dati): array {
        $fuori = [];
        foreach ($this->alberi as $nome => $albero) {
            $t = Motore::rendi($albero, $dati, self::html($nome));
            if ($nome === 'oggetto') {
                $t = trim(preg_replace('/\s+/u', ' ', $t));
            }
            $fuori[$nome] = $t;
        }
        return $fuori;
    }

    /** @return list<string> nomi usati in tutte le parti */
    public function nomi(): array {
        $n = [];
        foreach ($this->alberi as $a) {
            array_push($n, ...Motore::nomi($a));
        }
        return array_values(array_unique($n));
    }

    /**
     * Problemi rispetto allo schema, per l'editor: segnaposti ignoti, valori senza escape in parti HTML.
     *
     * @return list<string>
     */
    public function verifica(Schema $schema): array {
        $problemi = [];
        foreach ($this->nomi() as $nome) {
            if (!$schema->conosce($nome)) {
                $problemi[] = "Segnaposto non previsto: {{{$nome}}}";
            }
        }
        foreach ($this->alberi as $parte => $albero) {
            if (self::html($parte) && self::ha_grezzi($albero)) {
                $problemi[] = "La parte «{$parte}» inserisce valori senza escape HTML ({{{…}}} o {{&…}}): solo per testo HTML già sicuro.";
            }
        }
        return $problemi;
    }

    private static function ha_grezzi(array $albero): bool {
        foreach ($albero as $n) {
            if ($n[0] === 'grezzo' || (in_array($n[0], ['sezione', 'inversa'], true) && self::ha_grezzi($n[2]))) {
                return true;
            }
        }
        return false;
    }
}
