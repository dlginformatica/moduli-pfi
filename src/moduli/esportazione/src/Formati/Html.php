<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esportazione\Formati;

use Pfi\Esportazione\Base;

/**
 * Documento HTML con una tabella accessibile (didascalia, intestazioni con scope) e uno stile essenziale per la stampa.
 * Lo usa anche il formato PDF, che lo passa al motore di stampa.
 */
final class Html extends Base {

    public function nome(): string {
        return 'html';
    }

    public function estensione(): string {
        return 'html';
    }

    public function tipo(): string {
        return 'text/html';
    }

    private static function h(string $s): string {
        return nl2br(htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'), false);
    }

    protected function apri(): void {
        $t = self::h($this->titolo !== '' ? $this->titolo : 'Esportazione');
        $this->out("<!DOCTYPE html>\n<html lang=\"it\">\n<head>\n<meta charset=\"utf-8\">\n<title>{$t}</title>\n<style>"
            . 'body{font-family:system-ui,sans-serif;font-size:10pt;margin:1.5em}table{border-collapse:collapse;width:100%}'
            . 'th,td{border:1px solid #999;padding:.25em .5em;text-align:left;vertical-align:top}th{background:#eee}'
            . "td.n{text-align:right}caption{font-weight:bold;text-align:left;margin-bottom:.5em}thead{display:table-header-group}"
            . "</style>\n</head>\n<body>\n<table>\n<caption>{$t}</caption>\n<thead><tr>");
        foreach ($this->colonne as $c) {
            $this->out('<th scope="col">' . self::h($c) . '</th>');
        }
        $this->out("</tr></thead>\n<tbody>\n");
    }

    protected function scrivi_riga(array $valori): void {
        $s = '<tr>';
        foreach ($valori as $v) {
            $s .= (self::numerico($v) ? '<td class="n">' : '<td>') . self::h(self::testo($v)) . '</td>';
        }
        $this->out($s . "</tr>\n");
    }

    protected function termina(): void {
        $this->out("</tbody>\n</table>\n</body>\n</html>\n");
    }
}
