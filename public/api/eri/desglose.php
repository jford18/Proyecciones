<?php

declare(strict_types=1);

use App\db\Db;
use App\services\EriService;

require __DIR__ . '/../../../vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

$traceId = uniqid('eri_desglose_', true);

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $config = require __DIR__ . '/../../../src/config/config.php';
    $pdo = Db::pdo($config);
    $service = new EriService($pdo);

    $periodo = (int) ($_GET['periodo'] ?? date('Y'));
    $tipo = strtoupper(trim((string) ($_GET['tipo'] ?? 'PRESUPUESTO')));

    if ($periodo < 1900 || $periodo > 3000) {
        throw new InvalidArgumentException('El período es inválido.');
    }

    if ($tipo === '') {
        $tipo = 'PRESUPUESTO';
    }

    $payload = $service->buildResultadoAntesDesglose($periodo);
    $payload['tipo'] = $tipo;

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'No fue posible obtener el desglose ERI.',
        'detail' => $e->getMessage(),
        'trace_id' => $traceId,
    ], JSON_UNESCAPED_UNICODE);
} finally {
    restore_error_handler();
}
