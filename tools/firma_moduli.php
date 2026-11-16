#!/usr/bin/env php
<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
//
// Chiavi e pacchetti firmati dei moduli universali (release 9.0, D-162). Da usare fuori dal portale, dove si conserva
// la chiave privata (sul PC in vm\segreti, mai nel repository né nel kit).
//
//   php tools/firma_moduli.php chiavi <cartella>
//       crea <cartella>/moduli-firma.chiave (privata, 0600) e <cartella>/moduli-firma.pub; rifiuta se esistono già.
//       La chiave pubblica va nel parametro pfi_moduli_chiave_pubblica del kit.
//   php tools/firma_moduli.php pacchetto <src/moduli/nome> <destinazione> <file della chiave privata>
//       crea <destinazione>/<nome>-<versione>.tar.gz e il file .firma da caricare nel pannello «Moduli universali».
//
// La chiave privata non si stampa mai; si legge solo dal file indicato.

declare(strict_types=1);

$radice = dirname(__DIR__) . '/src/moduli';
require_once "{$radice}/contratti/avvio.php";
foreach (glob("{$radice}/*", GLOB_ONLYDIR) as $c) {
    \Pfi\Contratti\Caricatore::registra_cartella($c);
}

$a = $argv;
try {
    switch ($a[1] ?? '') {
        case 'chiavi':
            $dir = rtrim($a[2] ?? '', '/');
            if ($dir === '' || !is_dir($dir)) {
                throw new RuntimeException('Cartella delle chiavi inesistente.');
            }
            $priv = "{$dir}/moduli-firma.chiave";
            $pub = "{$dir}/moduli-firma.pub";
            if (file_exists($priv) || file_exists($pub)) {
                throw new RuntimeException('Le chiavi esistono già: non si sovrascrivono.');
            }
            [$pubblica, $privata] = \Pfi\Gestore\Pacchetto::genera_chiavi();
            $vecchia = umask(0077);
            file_put_contents($priv, $privata . "\n");
            umask($vecchia);
            chmod($priv, 0600);
            file_put_contents($pub, $pubblica . "\n");
            echo "Chiave privata: {$priv} (0600, da conservare fuori dal portale)\nChiave pubblica: {$pub}\n";
            break;
        case 'pacchetto':
            [$cartella, $dest, $filepriv] = [$a[2] ?? '', $a[3] ?? '', $a[4] ?? ''];
            if (!is_file("{$cartella}/modulo.json") || !is_dir($dest) || !is_readable($filepriv)) {
                throw new RuntimeException('Cartella del modulo, destinazione o file della chiave privata non validi.');
            }
            $archivio = \Pfi\Gestore\Pacchetto::crea($cartella, $dest, trim((string) file_get_contents($filepriv)));
            echo "Pacchetto: {$archivio}\nFirma: {$archivio}.firma\n";
            break;
        default:
            fwrite(STDERR, "Uso: firma_moduli.php chiavi <cartella> | pacchetto <cartella del modulo> <destinazione> <chiave privata>\n");
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'firma_moduli: ' . $e->getMessage() . "\n");
    exit(1);
}
