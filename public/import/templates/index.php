<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/../_api.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    importApiSendJson([
        'ok' => false,
        'message' => 'Método no permitido.',
        'details' => ['allowed' => ['GET']],
    ], 405);
}

try {
    $controller = importApiController();
    importApiSendJson($controller->templates());
} catch (Throwable $e) {
    importApiSendJson([
        'ok' => false,
        'message' => $e->getMessage(),
        'details' => ['endpoint' => 'templates', 'method' => 'GET'],
    ], 500);
}
