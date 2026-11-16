<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
// Archivio S3 finto per le prove (php -S): oggetti in una cartella, controllo della forma della firma.
$dir = getenv('PFI_S3_DIR');
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('#^AWS4-HMAC-SHA256 Credential=AKPROVA/\d{8}/eu-south-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$#', $auth)) {
    http_response_code(403);
    exit;
}
$p = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
[, $cont, $chiave] = explode('/', $p, 3) + [2 => ''];
$f = $dir . '/' . str_replace('/', '%', $chiave);
switch ($_SERVER['REQUEST_METHOD']) {
    case 'PUT':
        file_put_contents($f, file_get_contents('php://input'));
        header('ETag: "' . md5_file($f) . '"');
        break;
    case 'GET':
        if ($chiave !== '') {
            is_file($f) ? readfile($f) : http_response_code(404);
            break;
        }
        $prefisso = $_GET['prefix'] ?? '';
        $chiavi = array_map(fn($x) => str_replace('%', '/', basename($x)), glob($dir . '/*') ?: []);
        $chiavi = array_filter($chiavi, fn($k) => str_starts_with($k, $prefisso));
        echo '<?xml version="1.0"?><ListBucketResult>';
        if (isset($_GET['delimiter'])) {
            $pre = array_unique(array_map(fn($k) => $prefisso . explode('/', substr($k, strlen($prefisso)))[0] . '/', $chiavi));
            foreach ($pre as $x) {
                echo '<CommonPrefixes><Prefix>' . htmlspecialchars($x) . '</Prefix></CommonPrefixes>';
            }
        } else {
            foreach ($chiavi as $k) {
                echo '<Contents><Key>' . htmlspecialchars($k) . '</Key></Contents>';
            }
        }
        echo '</ListBucketResult>';
        break;
    case 'DELETE':
        @unlink($f);
        http_response_code(204);
        break;
}
