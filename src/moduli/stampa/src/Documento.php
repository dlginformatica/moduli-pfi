<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Stampa;

/**
 * Documento HTML completo da impaginare: corpo reso dal modello, regole di pagina e una politica di sicurezza dei
 * contenuti che vieta ogni risorsa esterna (sono ammesse solo immagini e caratteri incorporati come data:), così un
 * modello modificato nel portale non fa uscire richieste dal server durante la stampa.
 */
final class Documento {

    public const CSP = "default-src 'none'; img-src data:; font-src data:; style-src 'unsafe-inline'";

    public static function html(string $corpo, Pagina $pagina, string $titolo = '', string $lingua = 'it', string $stile = ''): string {
        $t = htmlspecialchars($titolo, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        return "<!DOCTYPE html>\n<html lang=\"" . htmlspecialchars($lingua) . "\">\n<head>\n<meta charset=\"utf-8\">\n"
            . '<meta http-equiv="Content-Security-Policy" content="' . self::CSP . "\">\n<title>{$t}</title>\n<style>"
            . $pagina->css()
            . 'body{font-family:"DejaVu Sans",Arial,sans-serif;font-size:10pt;line-height:1.35;margin:0}'
            . 'table{border-collapse:collapse;width:100%}th,td{border:1px solid #888;padding:3px 5px;vertical-align:top}'
            . 'thead{display:table-header-group}tr{break-inside:avoid}.salto{break-after:page}img{max-width:100%}'
            . str_replace('</', '<\/', $stile) . "</style>\n</head>\n<body>\n{$corpo}\n</body>\n</html>\n";
    }
}
