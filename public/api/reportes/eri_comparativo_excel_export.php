<?php

declare(strict_types=1);

require_once __DIR__ . '/eri_comparativo_excel_shared.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

$traceId = uniqid('eri_cmp_exp_', true);

$jsonError = static function (string $message, string $detail, int $status = 400) use ($traceId): never {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'message' => $message,
        'detail' => $detail,
        'trace_id' => $traceId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        throw new InvalidArgumentException('Método no permitido.');
    }

    $tmpPath = (string) ($_GET['file_tmp'] ?? '');
    if ($tmpPath === '' || !is_file($tmpPath)) {
        throw new InvalidArgumentException('No se encontró el archivo temporal para exportar. Vuelve a comparar.');
    }

    $params = [
        'anio' => $_GET['anio'] ?? date('Y'),
        'tipo' => $_GET['tipo'] ?? 'PRESUPUESTO',
        'solo_diferencias' => $_GET['solo_diferencias'] ?? '1',
    ];

    $result = eriComparativoBuildResult($params, $tmpPath);
    $rows = (array) ($result['rows'] ?? []);

    if (ob_get_length()) {
        ob_clean();
    }

    $filename = sprintf('eri_comparativo_excel_%s_%s.csv', date('Ymd_His'), preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($params['tipo'] ?? '')));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        throw new RuntimeException('No se pudo generar el archivo CSV.');
    }

    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['CODIGO', 'NOMBRE', 'CAMPO', 'A', 'B', 'DIF', 'DIF_ABS', 'DIF_PCT']);
    foreach ($rows as $row) {
        fputcsv($out, [
            (string) ($row['CODIGO'] ?? ''),
            (string) ($row['NOMBRE'] ?? ''),
            (string) ($row['CAMPO'] ?? ''),
            $row['A'] ?? null,
            $row['B'] ?? null,
            $row['DIF'] ?? null,
            $row['DIF_ABS'] ?? null,
            $row['DIF_PCT'] ?? null,
        ]);
    }

    fclose($out);
    exit;
} catch (InvalidArgumentException $e) {
    $jsonError('No fue posible generar el comparativo.', $e->getMessage(), 400);
} catch (Throwable $e) {
    error_log(sprintf('[ERI_COMPARATIVO_EXCEL_EXPORT][%s] %s', $traceId, $e->getMessage()));
    $jsonError('No fue posible generar el comparativo.', $e->getMessage(), 500);
}
