<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esportazione\Formati;

use Pfi\Contratti\Eccezione;
use Pfi\Esportazione\Base;

/**
 * XML: <radice><riga><colonna>valore</colonna>…</riga></radice>; i nomi degli elementi derivano dalle colonne (lettere,
 * cifre, «_», «-», «.»; il resto diventa «_»; un nome che non inizia con una lettera riceve «c_» davanti).
 */
final class Xml extends Base {

    /** @var list<string> */
    private array $elementi = [];

    public function __construct(private readonly string $radice = 'dati', private readonly string $elemento = 'riga') {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $radice . $elemento)) {
            throw new Eccezione('Nomi degli elementi XML non validi.');
        }
    }

    public function nome(): string {
        return 'xml';
    }

    public function estensione(): string {
        return 'xml';
    }

    public function tipo(): string {
        return 'application/xml';
    }

    public static function nome_elemento(string $c): string {
        $n = preg_replace('/[^A-Za-z0-9_.-]/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $c) ?: $c);
        return preg_match('/^[A-Za-z_]/', $n) && !preg_match('/^xml/i', $n) ? $n : 'c_' . $n;
    }

    protected function apri(): void {
        $this->elementi = [];
        foreach ($this->colonne as $c) {
            $n = self::nome_elemento($c);
            while (in_array($n, $this->elementi, true)) {
                $n .= '_';
            }
            $this->elementi[] = $n;
        }
        $this->out("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<{$this->radice}" . ($this->titolo !== '' ? ' titolo="' . self::xml($this->titolo) . '"' : '') . ">\n");
    }

    protected function scrivi_riga(array $valori): void {
        $s = "  <{$this->elemento}>";
        foreach ($valori as $i => $v) {
            $s .= "<{$this->elementi[$i]}>" . self::xml(self::testo($v)) . "</{$this->elementi[$i]}>";
        }
        $this->out($s . "</{$this->elemento}>\n");
    }

    protected function termina(): void {
        $this->out("</{$this->radice}>\n");
    }
}
