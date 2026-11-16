<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esportazione\Formati;

use Pfi\Esportazione\ArchivioZip;

/** Documento di testo OpenDocument (ODT) con il titolo e una tabella con bordi; la riga d'intestazione si ripete. */
final class Odt extends ArchivioZip {

    public function nome(): string {
        return 'odt';
    }

    public function estensione(): string {
        return 'odt';
    }

    public function tipo(): string {
        return 'application/vnd.oasis.opendocument.text';
    }

    private static function paragrafi(string $s, string $stile): string {
        return implode('', array_map(fn($r) => "<text:p text:style-name=\"{$stile}\">" . self::xml($r) . '</text:p>',
            explode("\n", str_replace("\r", '', $s))));
    }

    protected function riga_xml(array $valori, bool $intestazione): string {
        $s = '<table:table-row>';
        foreach ($valori as $v) {
            $s .= '<table:table-cell table:style-name="cella" office:value-type="string">'
                . self::paragrafi(self::testo($v), $intestazione ? 'intest' : (self::numerico($v) ? 'num' : 'Standard')) . '</table:table-cell>';
        }
        $s .= "</table:table-row>\n";
        return $intestazione ? "<table:table-header-rows>{$s}</table:table-header-rows>\n" : $s;
    }

    protected function non_compressi(): array {
        return ['mimetype'];
    }

    protected function parti(): array {
        $ns = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
            . 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" '
            . 'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"';
        $titolo = $this->titolo !== '' ? self::paragrafi($this->titolo, 'titolo') : '';
        $n = count($this->colonne);
        return [
            'mimetype' => 'application/vnd.oasis.opendocument.text',
            'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">'
                . '<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.text"/>'
                . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/></manifest:manifest>',
            'content.xml' => '<?xml version="1.0" encoding="UTF-8"?>' . "<office:document-content {$ns} office:version=\"1.2\">"
                . '<office:automatic-styles>'
                . '<style:style style:name="cella" style:family="table-cell"><style:table-cell-properties fo:border="0.5pt solid #999999" fo:padding="0.05cm"/></style:style>'
                . '<style:style style:name="intest" style:family="paragraph"><style:text-properties fo:font-weight="bold"/></style:style>'
                . '<style:style style:name="num" style:family="paragraph"><style:paragraph-properties fo:text-align="end"/></style:style>'
                . '<style:style style:name="titolo" style:family="paragraph"><style:text-properties fo:font-size="16pt" fo:font-weight="bold"/></style:style>'
                . '</office:automatic-styles><office:body><office:text>' . $titolo
                . "<table:table table:name=\"Tabella1\"><table:table-column table:number-columns-repeated=\"{$n}\"/>\n%RIGHE%</table:table>"
                . '</office:text></office:body></office:document-content>',
        ];
    }
}
