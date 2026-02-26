<?php

declare(strict_types=1);

use App\db\Db;

require __DIR__ . '/../../../vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'JSON inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$periodoMes = filter_var($payload['periodo_mes'] ?? null, FILTER_VALIDATE_INT);
$mes = filter_var($payload['mes'] ?? null, FILTER_VALIDATE_INT);
$codigo = trim((string) ($payload['codigo'] ?? ''));
$descripcion = trim((string) ($payload['descripcion'] ?? ''));
$valorRaw = $payload['valor_real'] ?? null;

if ($periodoMes === false || $periodoMes === null || !preg_match('/^\d{6}$/', (string) $periodoMes)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'periodo_mes es requerido con formato YYYYMM.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($mes === false || $mes === null || $mes < 1 || $mes > 12) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'mes debe estar entre 1 y 12.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($codigo == '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'codigo es requerido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$valorReal = null;
if ($valorRaw !== null && $valorRaw !== '') {
    if (!is_numeric($valorRaw)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'valor_real debe ser numérico o null.'], JSON_UNESCAPED_UNICODE);
        exit;
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

    echo json_encode([
        'ok' => true,
        'message' => 'Valor real guardado correctamente.',
        'data' => [
            'periodo_mes' => (int) $periodoMes,
            'codigo' => $codigo,
            'mes' => (int) $mes,
            'valor_real' => $valorReal,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'No fue posible guardar el valor real de ERI.',
        'detail' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
