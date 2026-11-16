<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).

declare(strict_types=1);

namespace Pfi\Esportazione\Formati;

use Pfi\Esportazione\ArchivioZip;

/**
 * Documento Word (Office Open XML, DOCX) con il titolo e una tabella a pagina A4 orizzontale; la riga d'intestazione
 * si ripete su ogni pagina.
 */
final class Docx extends ArchivioZip {

    public function nome(): string {
        return 'docx';
    }

    public function estensione(): string {
        return 'docx';
    }

    public function tipo(): string {
        return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    private static function testo_w(string $s, bool $grassetto): string {
        $r = [];
        foreach (explode("\n", str_replace("\r", '', $s)) as $i => $pezzo) {
            $r[] = ($i ? '<w:r><w:br/></w:r>' : '') . '<w:r>' . ($grassetto ? '<w:rPr><w:b/></w:rPr>' : '')
                . '<w:t xml:space="preserve">' . self::xml($pezzo) . '</w:t></w:r>';
        }
        return implode('', $r);
    }

    protected function riga_xml(array $valori, bool $intestazione): string {
        $s = '<w:tr>' . ($intestazione ? '<w:trPr><w:tblHeader/></w:trPr>' : '');
        foreach ($valori as $v) {
            $destra = !$intestazione && self::numerico($v) ? '<w:pPr><w:jc w:val="right"/></w:pPr>' : '';
            $s .= '<w:tc><w:p>' . $destra . self::testo_w(self::testo($v), $intestazione) . '</w:p></w:tc>';
        }
        return $s . "</w:tr>\n";
    }

    protected function parti(): array {
        $bordo = fn($n) => "<w:{$n} w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"999999\"/>";
        $titolo = $this->titolo !== '' ? '<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr>' . self::testo_w($this->titolo, true) . '</w:p>' : '';
        return [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                . '</Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $titolo
                . '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>' . $bordo('top') . $bordo('left') . $bordo('bottom')
                . $bordo('right') . $bordo('insideH') . $bordo('insideV') . "</w:tblBorders></w:tblPr>\n%RIGHE%</w:tbl><w:p/>"
                . '<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>'
                . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/>'
                . '</w:sectPr></w:body></w:document>',
        ];
    }
}
