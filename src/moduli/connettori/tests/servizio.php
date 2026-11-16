<?php
// Realizzato con Claude (Anthropic) e altri strumenti di intelligenza artificiale.
// Copyright 2026 Gaetano Giarrusso. Licenza GPL v3 o successiva (https://www.gnu.org/licenses/gpl-3.0.html).
// Servizio HTTP minimo per le prove del trasporto cURL (php -S).
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/eco') {
    header('Content-Type: application/json');
    echo json_encode(['metodo' => $_SERVER['REQUEST_METHOD'], 'corpo' => file_get_contents('php://input'),
        'autorizzazione' => $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'tipo' => $_SERVER['CONTENT_TYPE'] ?? null]);
} elseif (preg_match('#^/stato/(\d+)$#', $p, $m)) {
    http_response_code((int) $m[1]);
    header('Retry-After: 0');
    echo 'stato';
} elseif ($p === '/rimanda') {
    header('Location: http://127.0.0.1:1/altrove', true, 302);
} elseif ($p === '/grande') {
    echo str_repeat('x', 300000);
} elseif ($p === '/lento') {
    sleep(3);
    echo 'tardi';
} else {
    http_response_code(404);
}
