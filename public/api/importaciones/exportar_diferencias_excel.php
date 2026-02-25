<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

require_once __DIR__ . '/comparativo_excel.php';

function exportExcelJsonResponse(array $payload, int $status = 500): never
{
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function exportExcelCsvCell(mixed $value): string
{
    return '"' . str_replace('"', '""', (string) $value) . '"';
}

$traceId = uniqid('cmp_excel_export_', true);

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $params = array_merge($_GET, $_POST);
    $result = cmpExcelBuild($params);
    $meta = (array) ($result['meta'] ?? []);
    $rows = is_array($result['diferencias'] ?? null) ? $result['diferencias'] : [];

    if (ob_get_length()) {
        ob_clean();
    }

    $filename = sprintf(
        'comparativo_excel_%s_%s_%s.csv',
        strtolower((string) ($meta['tab'] ?? 'tab')),
        strtolower((string) ($meta['tipo_b'] ?? 'tipo')),
        date('Ymd_His')
    );

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        throw new RuntimeException('No se pudo abrir stream de salida CSV.');
    }

    $headers = ['CUENTA', 'MES', 'VALOR_EXCEL', 'VALOR_IMPORT', 'DELTA'];
    fwrite($out, implode(',', array_map('exportExcelCsvCell', $headers)) . "\n");

    foreach ($rows as $row) {
        $line = [
            $row['cuenta'] ?? '',
            $row['mes'] ?? '',
            $row['valor_excel'] ?? 0,
            $row['valor_import'] ?? 0,
            $row['delta'] ?? 0,
        ];
        fwrite($out, implode(',', array_map('exportExcelCsvCell', $line)) . "\n");
    }

    fclose($out);
    exit;
} catch (InvalidArgumentException $e) {
    exportExcelJsonResponse([
        'ok' => false,
        'message' => 'Solicitud inválida.',
        'detail' => $e->getMessage(),
        'trace_id' => $traceId,
    ], 400);
} catch (Throwable $e) {
    error_log(sprintf('[COMPARATIVO_EXCEL][EXPORT][%s] %s', $traceId, $e->getMessage()));
    exportExcelJsonResponse([
        'ok' => false,
        'message' => 'No fue posible exportar diferencias Excel.',
        'detail' => $e->getMessage(),
        'trace_id' => $traceId,
    ], 500);
}
