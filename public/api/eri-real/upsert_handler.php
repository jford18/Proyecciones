<?php

declare(strict_types=1);

use App\db\Db;

require __DIR__ . '/../../../vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

function upsertError(string $error, int $status): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    upsertError('Método no permitido.', 405);
}

$contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
if ($contentType === '' || stripos($contentType, 'application/json') !== 0) {
    upsertError('Content-Type debe ser application/json.', 415);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    upsertError('JSON inválido.', 422);
}

$periodoMes = filter_var($payload['periodo_mes'] ?? null, FILTER_VALIDATE_INT);
$mes = filter_var($payload['mes'] ?? null, FILTER_VALIDATE_INT);
$codigo = trim((string) ($payload['codigo'] ?? ''));
$descripcion = trim((string) ($payload['descripcion'] ?? ''));
$valorRaw = $payload['valor_real'] ?? null;

if ($periodoMes === false || $periodoMes === null || !preg_match('/^\d{6}$/', (string) $periodoMes)) {
    upsertError('periodo_mes es requerido con formato YYYYMM.', 422);
}

if ($mes === false || $mes === null || $mes < 1 || $mes > 12) {
    upsertError('mes debe estar entre 1 y 12.', 422);
}

if ($codigo === '') {
    upsertError('codigo es requerido.', 422);
}

$valorReal = null;
if ($valorRaw !== null && $valorRaw !== '') {
    if (!is_numeric($valorRaw)) {
        upsertError('valor_real debe ser numérico o null.', 422);
    }
    $valorReal = (float) $valorRaw;
}

try {
    $config = require __DIR__ . '/../../../src/config/config.php';
    $pdo = Db::pdo($config);

    $sql = 'INSERT INTO ERI_REAL_VALOR (PERIODO_MES, MES, CODIGO, DESCRIPCION, VALOR_REAL)
            VALUES (:periodo_mes, :mes, :codigo, :descripcion, :valor_real)
            ON DUPLICATE KEY UPDATE
              DESCRIPCION = VALUES(DESCRIPCION),
              VALOR_REAL = VALUES(VALOR_REAL)';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':periodo_mes', (int) $periodoMes, PDO::PARAM_INT);
    $stmt->bindValue(':mes', (int) $mes, PDO::PARAM_INT);
    $stmt->bindValue(':codigo', $codigo, PDO::PARAM_STR);
    $stmt->bindValue(':descripcion', $descripcion, PDO::PARAM_STR);
    if ($valorReal === null) {
        $stmt->bindValue(':valor_real', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':valor_real', $valorReal);
    }
    $stmt->execute();

    http_response_code(200);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    upsertError('No fue posible guardar el valor real de ERI.', 500);
}
