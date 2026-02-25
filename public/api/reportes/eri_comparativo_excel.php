<?php

declare(strict_types=1);

require_once __DIR__ . '/eri_comparativo_excel_shared.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

$traceId = uniqid('eri_cmp_', true);

$respond = static function (array $payload, int $status = 200): never {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new InvalidArgumentException('Método no permitido.');
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file']) || (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('No se pudo leer el Excel ERI. Debes adjuntar un archivo.');
    }

    $tmpPath = (string) ($_FILES['file']['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('No se pudo leer el Excel ERI.');
    }

    $ext = pathinfo((string) ($_FILES['file']['name'] ?? 'archivo.xlsx'), PATHINFO_EXTENSION);
    $persistPath = sys_get_temp_dir() . '/eri_cmp_' . uniqid('', true) . ($ext !== '' ? '.' . $ext : '.xlsx');
    if (!move_uploaded_file($tmpPath, $persistPath)) {
        throw new RuntimeException('No se pudo leer el Excel ERI.');
    }

    $result = eriComparativoBuildResult($_POST, $persistPath);
    $result['meta']['file_tmp'] = $persistPath;
    $result['message'] = sprintf('Comparativo generado: %d diferencias.', (int) ($result['meta']['total_diferencias'] ?? 0));
    $respond($result, 200);
} catch (InvalidArgumentException $e) {
    $respond([
        'ok' => false,
        'message' => 'No fue posible generar el comparativo.',
        'detail' => $e->getMessage(),
        'trace_id' => $traceId,
    ], 400);
} catch (Throwable $e) {
    error_log(sprintf('[ERI_COMPARATIVO_EXCEL][%s] %s in %s:%d', $traceId, $e->getMessage(), $e->getFile(), $e->getLine()));
    $respond([
        'ok' => false,
        'message' => 'No fue posible generar el comparativo.',
        'detail' => $e->getMessage(),
        'trace_id' => $traceId,
    ], 500);
}
