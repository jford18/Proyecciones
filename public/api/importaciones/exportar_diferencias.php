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

function exportRespondJsonError(array $payload, int $status = 200): never
{
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function exportCsvCell(mixed $value): string
{
    $text = (string) $value;
    return '"' . str_replace('"', '""', $text) . '"';
}

try {
    $params = array_merge($_GET, $_POST);
    $result = comparativoBuild($params);
    $meta = $result['meta'] ?? [];
    $rows = is_array($result['diferencias'] ?? null) ? $result['diferencias'] : [];

    if (ob_get_length()) {
        ob_clean();
    }

    $filename = sprintf(
        'comparativo_%s_%s_vs_%s_%s.csv',
        strtolower((string) ($meta['tab'] ?? 'tab')),
        strtolower((string) ($meta['tipo_a'] ?? 'a')),
        strtolower((string) ($meta['tipo_b'] ?? 'b')),
        date('Ymd_His')
    );

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        throw new RuntimeException('No se pudo abrir stream de salida CSV.');
    }

    $headers = ['CLAVE', 'DESCRIPCION', 'CAMPO', 'VALOR_A', 'VALOR_B', 'DELTA'];
    fwrite($out, implode(',', array_map('exportCsvCell', $headers)) . "\n");

    foreach ($rows as $row) {
        $line = [];
        foreach ($headers as $header) {
            $line[] = exportCsvCell($row[$header] ?? '');
        }
        fwrite($out, implode(',', $line) . "\n");
    }

    fclose($out);
    exit;
} catch (Throwable $e) {
    $traceId = uniqid('cmp_export_', true);
    error_log(sprintf('[COMPARATIVO][EXPORT][%s] %s', $traceId, $e->getMessage()));

    exportRespondJsonError([
        'ok' => false,
        'message' => 'No fue posible exportar diferencias.',
        'detail' => $e->getMessage(),
        'trace_id' => $traceId,
    ], 200);
}
