<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Esempio d'uso di pfi/esportazione fuori da Moodle: righe da un generatore, formati testuali, formato configurato,
// formati in archivio scritti su file, PDF con un impaginatore, errori.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Pfi\Contratti\Eccezione;
use Pfi\Esportazione\Esportatore;
use Pfi\Esportazione\Formati\Delimitato;
use Pfi\Esportazione\Formati\LarghezzaFissa;
use Pfi\Esportazione\Impaginatore;

$colonne = ['Dipendente', 'Corso', 'Ore', 'Completato', 'Data'];
// Un generatore, come una lettura dal database: le righe si scrivono una alla volta.
$righe = function(): Generator {  // la terza riga mostra la protezione dalle formule nel CSV
    yield ['Rossi, Mario', 'Sicurezza "base"', 4.5, true, new DateTimeImmutable('2026-11-10')];
    yield ['Bianchi Anna', 'Excel avanzato', 12, false, null];
    yield ['=HYPERLINK("http://example.org")', 'Prova di formula', -3, false, new DateTimeImmutable('2026-11-12')];
};

$e = new Esportatore();
echo 'Formati disponibili: ', implode(', ', $e->nomi()), "\n";

// 1. Formati testuali scritti in un flusso (qui in memoria; in una pagina si usa php://output).
foreach (['csv', 'md', 'json'] as $nome) {
    $flusso = fopen('php://memory', 'w+');
    $n = $e->esporta($righe(), $colonne, $nome, $flusso, 'Presenze ai corsi');
    rewind($flusso);
    $testo = stream_get_contents($flusso);
    echo "\n--- {$nome}: {$n} righe, tipo ", $e->formato($nome)->tipo(), "\n",
        str_starts_with($testo, "\xEF\xBB\xBF") ? "(inizia con il BOM UTF-8)\n" : '', ltrim($testo, "\xEF\xBB\xBF"), "\n";
}

// 2. Formati configurati con un nome: CSV per un gestionale che legge Windows-1252 e testo a larghezza fissa.
$e->formato_con_nome('csv_gestionale', fn() => new Delimitato(';', '"', 'Windows-1252', false, "\r\n"));
$e->formato_con_nome('fisso', fn() => new LarghezzaFissa([17, 19, 5, 4, 26], ['s', 's', 'd', 'd', 's']));
$e->in_file($righe(), $colonne, 'csv_gestionale', __DIR__ . '/gestionale.csv');
echo "--- csv_gestionale: «Città» in Windows-1252 occupa ",
    strlen(mb_convert_encoding('Città', 'Windows-1252', 'UTF-8')), " byte; file di ", filesize(__DIR__ . '/gestionale.csv'), " byte\n";
$flusso = fopen('php://memory', 'w+');
$e->esporta($righe(), $colonne, 'fisso', $flusso);
rewind($flusso);
echo "--- fisso:\n", stream_get_contents($flusso), "\n";

// 3. Formati in archivio scritti su file (scrittura in un temporaneo e spostamento finale).
foreach (['xlsx', 'ods', 'docx', 'odt'] as $nome) {
    $file = __DIR__ . "/presenze.{$nome}";
    $e->in_file($righe(), $colonne, $nome, $file, 'Presenze ai corsi');
    $zip = new ZipArchive();
    $zip->open($file);
    $parti = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $parti[] = $zip->getNameIndex($i);
    }
    echo "{$nome}: archivio con ", count($parti), " parti, la prima è «{$parti[0]}»\n";
}

// 4. PDF: l'esportazione prepara l'HTML e lo passa a un impaginatore (pfi/stampa ne fornisce uno reale).
$finto = new class implements Impaginatore {
    public string $html = '';
    public function pdf(string $html): string { $this->html = $html; return "%PDF-1.7 finto\n"; }
};
$conpdf = new Esportatore($finto);
$flusso = fopen('php://memory', 'w+');
$conpdf->esporta($righe(), $colonne, 'pdf', $flusso, 'Presenze ai corsi');
echo "\npdf: l'impaginatore ha ricevuto una tabella HTML con ", substr_count($finto->html, '<tr'), " righe <tr>\n";

// 5. Errori.
foreach ([fn() => $e->formato('xls'), fn() => $e->esporta([['solo un valore']], $colonne, 'csv', fopen('php://memory', 'w'))] as $f) {
    try {
        $f();
    } catch (Eccezione $x) {
        echo "Errore: {$x->getMessage()}\n";
    }
}
