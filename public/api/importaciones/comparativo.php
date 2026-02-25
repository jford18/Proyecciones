<?php

declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/comparativo_shared.php';

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

function comparativoJsonResponse(array $payload, int $status = 200): never
{
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $params = array_merge($_GET, $_POST);
    $result = comparativoBuild($params);
    comparativoJsonResponse($result, 200);
} catch (Throwable $e) {
    $traceId = uniqid('cmp_', true);
    error_log(sprintf('[COMPARATIVO][%s] %s', $traceId, $e->getMessage()));
    comparativoJsonResponse([
        'ok' => false,
        'message' => 'No fue posible generar el comparativo.',
        'detail' => $e->getMessage(),
        'trace_id' => $traceId,
    ], 500);
}
