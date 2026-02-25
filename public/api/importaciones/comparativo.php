<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

require __DIR__ . '/comparativo_shared.php';

$traceId = uniqid('cmp_', true);

function comparativoRespond(array $payload): never
{
    if (ob_get_length()) {
        ob_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function comparativoError(string $traceId, Throwable $e, string $message = 'No fue posible generar el comparativo.'): never
{
    error_log(sprintf('[COMPARATIVO][%s] %s in %s:%d', $traceId, $e->getMessage(), $e->getFile(), $e->getLine()));
    comparativoRespond([
        'ok' => false,
        'message' => $message,
        'detail' => $e->getMessage(),
        'trace_id' => $traceId,
    ]);
}

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e) use ($traceId): void {
    comparativoError($traceId, $e);
});

register_shutdown_function(static function () use ($traceId): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'] ?? 0, $fatal, true)) {
        return;
    }

    $exception = new ErrorException((string) ($error['message'] ?? 'Fatal error'), 0, (int) ($error['type'] ?? E_ERROR), (string) ($error['file'] ?? ''), (int) ($error['line'] ?? 0));
    comparativoError($traceId, $exception);
});

try {
    $params = array_merge($_GET, $_POST);
    $result = comparativoBuild($params);

    comparativoRespond([
        'ok' => true,
        'tab' => $result['tab'] ?? '',
        'tipo_a' => $result['tipo_a'] ?? '',
        'tipo_b' => $result['tipo_b'] ?? '',
        'solo_diferencias' => (bool) ($result['solo_diferencias'] ?? false),
        'columns' => $result['columns'] ?? [],
        'rows' => $result['rows'] ?? [],
        'meta' => $result['meta'] ?? [],
        // Compatibilidad con UI actual.
        'data' => [
            'meta' => [
                'tab' => $result['tab'] ?? '',
                'tipo_a' => $result['tipo_a'] ?? '',
                'tipo_b' => $result['tipo_b'] ?? '',
                'solo_diferencias' => (bool) ($result['solo_diferencias'] ?? false),
                'import_log_id_a' => $result['meta']['import_log_id_a'] ?? 0,
                'import_log_id_b' => $result['meta']['import_log_id_b'] ?? 0,
                'import_a' => $result['meta']['import_a'] ?? [],
                'import_b' => $result['meta']['import_b'] ?? [],
            ],
            'rows' => $result['rows'] ?? [],
            'diferencias' => $result['diferencias'] ?? [],
            'resumen' => $result['resumen'] ?? [],
        ],
    ]);
} catch (InvalidArgumentException $e) {
    comparativoError($traceId, $e, 'Solicitud inválida.');
} catch (RuntimeException $e) {
    comparativoError($traceId, $e);
} catch (Throwable $e) {
    comparativoError($traceId, $e);
}
