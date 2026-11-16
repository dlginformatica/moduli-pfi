<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esportazione\Formati;

use Pfi\Esportazione\ArchivioZip;

/** Foglio di calcolo OpenDocument (ODS, ISO/IEC 26300): una tabella, numeri come valori numerici. */
final class Ods extends ArchivioZip {

    public function nome(): string {
        return 'ods';
    }

    public function estensione(): string {
        return 'ods';
    }

    public function tipo(): string {
        return 'application/vnd.oasis.opendocument.spreadsheet';
    }

    protected function riga_xml(array $valori, bool $intestazione): string {
        $s = '<table:table-row>';
        foreach ($valori as $v) {
            if (!$intestazione && self::numerico($v)) {
                $t = self::testo($v);
                $s .= '<table:table-cell office:value-type="float" office:value="' . $t . '"><text:p>' . $t . '</text:p></table:table-cell>';
            } else {
                $s .= '<table:table-cell office:value-type="string"' . ($intestazione ? ' table:style-name="intest"' : '') . '>'
                    . implode('', array_map(fn($r) => '<text:p>' . self::xml($r) . '</text:p>', explode("\n", self::testo($v))))
                    . '</table:table-cell>';
            }
        }
        return $s . "</table:table-row>\n";
    }

    protected function non_compressi(): array {
        return ['mimetype'];
    }

    protected function parti(): array {
        $ns = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
            . 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" '
            . 'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"';
        $nome = self::xml($this->titolo !== '' ? $this->titolo : 'Esportazione');
        return [
            'mimetype' => 'application/vnd.oasis.opendocument.spreadsheet',
            'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">'
                . '<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/>'
                . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/></manifest:manifest>',
            'content.xml' => '<?xml version="1.0" encoding="UTF-8"?>' . "<office:document-content {$ns} office:version=\"1.2\">"
                . '<office:automatic-styles><style:style style:name="intest" style:family="table-cell">'
                . '<style:text-properties fo:font-weight="bold"/></style:style></office:automatic-styles>'
                . "<office:body><office:spreadsheet><table:table table:name=\"{$nome}\">\n%RIGHE%</table:table>"
                . '</office:spreadsheet></office:body></office:document-content>',
        ];
    }
}
