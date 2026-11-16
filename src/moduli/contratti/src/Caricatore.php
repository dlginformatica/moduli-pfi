<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Contratti;

/**
 * Caricatore delle classi dei moduli installati sul server: legge da una cartella base le sottocartelle
 * <nome>/attuale/ (collegamento alla versione in uso), il loro modulo.json e registra il caricamento PSR-4 dello spazio dei
 * nomi del modulo dalla cartella src/. Un'applicazione che usa Composer non ne ha bisogno.
 */
final class Caricatore {

    /** @var array<string,string> spazio dei nomi => cartella src */
    private static array $mappa = [];

    /** @var array<string,Manifesto> nome del modulo => manifesto */
    private static array $moduli = [];

    private static bool $registrato = false;

    /**
     * Registra i moduli della cartella base; si può chiamare più volte (le cartelle successive non sostituiscono i moduli
     * già registrati).
     *
     * @return array<string,Manifesto> moduli registrati in questa chiamata
     */
    public static function registra(string $base): array {
        $nuovi = [];
        foreach (glob(rtrim($base, '/') . '/*/attuale/modulo.json') ?: [] as $file) {
            $m = Manifesto::da_file($file);
            if (isset(self::$moduli[$m->nome])) {
                continue;
            }
            self::aggiungi($m, dirname($file) . '/src');
            $nuovi[$m->nome] = $m;
        }
        return $nuovi;
    }

    /**
     * Registra un singolo modulo da una cartella che contiene modulo.json e src/ (prove, sviluppo).
     */
    public static function registra_cartella(string $cartella): Manifesto {
        $m = Manifesto::da_file(rtrim($cartella, '/') . '/modulo.json');
        if (!isset(self::$moduli[$m->nome])) {
            self::aggiungi($m, rtrim($cartella, '/') . '/src');
        }
        return self::$moduli[$m->nome];
    }

    /**
     * @return array<string,Manifesto>
     */
    public static function moduli(): array {
        return self::$moduli;
    }

    private static function aggiungi(Manifesto $m, string $src): void {
        self::$moduli[$m->nome] = $m;
        self::$mappa[$m->spazio] = $src;
        if (!self::$registrato) {
            spl_autoload_register([self::class, 'carica']);
            self::$registrato = true;
        }
    }

    /**
     * @internal funzione di caricamento registrata con spl_autoload_register
     */
    public static function carica(string $classe): void {
        foreach (self::$mappa as $spazio => $src) {
            if (str_starts_with($classe, $spazio)) {
                $file = $src . '/' . str_replace('\\', '/', substr($classe, strlen($spazio))) . '.php';
                if (is_file($file)) {
                    require_once($file);
                }
                return;
            }
        }
    }
}
