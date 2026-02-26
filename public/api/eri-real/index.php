<?php

declare(strict_types=1);

use App\db\Db;

require __DIR__ . '/../../../vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$periodoMes = filter_input(INPUT_GET, 'periodo_mes', FILTER_VALIDATE_INT);
if ($periodoMes === false || $periodoMes === null) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'periodo_mes es requerido y debe ser entero YYYYMM.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$periodoText = (string) $periodoMes;
if (!preg_match('/^\d{6}$/', $periodoText)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'periodo_mes debe tener formato YYYYMM.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $config = require __DIR__ . '/../../../src/config/config.php';
    $pdo = Db::pdo($config);

    $stmt = $pdo->prepare(
        'SELECT CODIGO, MES, VALOR_REAL
         FROM ERI_REAL_VALOR
         WHERE PERIODO_MES = :periodo_mes
         ORDER BY CODIGO, MES'
    );
    $stmt->execute(['periodo_mes' => $periodoMes]);

    $rows = array_map(static function (array $row): array {
        return [
            'codigo' => (string) ($row['CODIGO'] ?? ''),
            'mes' => (int) ($row['MES'] ?? 0),
            'valor_real' => isset($row['VALOR_REAL']) ? (float) $row['VALOR_REAL'] : null,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

    echo json_encode([
        'ok' => true,
        'periodo_mes' => $periodoMes,
        'data' => $rows,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'No fue posible consultar valores reales de ERI.',
        'detail' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
