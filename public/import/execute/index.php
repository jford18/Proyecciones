<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/../_api.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    importApiSendJson([
        'ok' => false,
        'message' => 'Método no permitido.',
        'details' => ['allowed' => ['POST']],
    ], 405);
}

try {
    $controller = importApiController();
    importApiSendJson($controller->execute($_POST, $_FILES, importApiUser()));
} catch (Throwable $e) {
    importApiSendJson([
        'ok' => false,
        'message' => $e->getMessage(),
        'details' => ['endpoint' => 'execute', 'method' => 'POST'],
    ], 500);
}
