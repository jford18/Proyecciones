<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

require __DIR__ . '/comparativo_shared.php';

$traceId = uniqid('cmp_', true);

function comparativoJsonResponse(array $payload, int $status = 200): never
{
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function comparativoJsonError(Throwable $e, string $traceId, int $status = 500): never
{
    error_log(sprintf('[COMPARATIVO][%s] %s in %s:%d', $traceId, $e->getMessage(), $e->getFile(), $e->getLine()));
    comparativoJsonResponse([
        'ok' => false,
        'message' => 'No fue posible generar el comparativo.',
        'detail' => $e->getMessage(),
        'trace_id' => $traceId,
    ], $status);
}

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e) use ($traceId): void {
    comparativoJsonError($e, $traceId, 500);
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
    comparativoJsonError($exception, $traceId, 500);
});

function comparativoResolveImportLogDateColumn(PDO $pdo): ?string
{
    $preferred = ['FECHA_CREACION', 'CREATED_AT', 'FECHA', 'FECHA_IMPORTACION'];

    $stmt = $pdo->prepare('SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND UPPER(TABLE_NAME) = UPPER(?)
          AND UPPER(COLUMN_NAME) IN ("FECHA_CREACION", "CREATED_AT", "FECHA", "FECHA_IMPORTACION")');
    $stmt->execute(['IMPORT_LOG']);

    $available = [];
    while (($column = $stmt->fetchColumn()) !== false) {
        $available[strtoupper((string) $column)] = (string) $column;
    }

    foreach ($preferred as $name) {
        if (isset($available[$name])) {
            return $available[$name];
        }
    }

    return null;
}

function comparativoBuildImportLogSelect(?string $dateColumn): string
{
    $select = 'ID, TAB, TIPO, ARCHIVO_NOMBRE, HOJA_NOMBRE, JSON_PATH';
    if ($dateColumn !== null) {
        $safeColumn = preg_replace('/[^A-Za-z0-9_]/', '', $dateColumn) ?? '';
        if ($safeColumn !== '') {
            $select .= ', `' . $safeColumn . '` AS FECHA_CREACION';
        }
    }

    return $select;
}

function comparativoFetchImportLogByIdSafe(PDO $pdo, int $id, ?string $dateColumn): ?array
{
    $select = comparativoBuildImportLogSelect($dateColumn);
    $stmt = $pdo->prepare("SELECT {$select} FROM IMPORT_LOG WHERE ID = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function comparativoFetchLatestImportLogSafe(PDO $pdo, string $tab, string $tipo, ?string $dateColumn): ?array
{
    $select = comparativoBuildImportLogSelect($dateColumn);
    $stmt = $pdo->prepare("SELECT {$select}
        FROM IMPORT_LOG
        WHERE TAB = ? AND TIPO = ? AND JSON_PATH IS NOT NULL AND JSON_PATH <> \"\"
        ORDER BY ID DESC
        LIMIT 1");
    $stmt->execute([$tab, $tipo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function comparativoBuildSafe(array $params): array
{
    $tab = comparativoNormalizeTab((string) ($params['tab'] ?? ''));
    $tipoA = strtoupper(trim((string) ($params['tipo_a'] ?? 'REAL')));
    $tipoB = strtoupper(trim((string) ($params['tipo_b'] ?? 'PRESUPUESTO')));
    $onlyDiff = ((int) ($params['solo_diferencias'] ?? 0)) === 1;
    $idA = (int) ($params['import_log_id_a'] ?? ($params['log_id_a'] ?? 0));
    $idB = (int) ($params['import_log_id_b'] ?? ($params['log_id_b'] ?? 0));

    if ($tab === '') {
        throw new InvalidArgumentException('Falta parámetro tab');
    }
    if ($tipoA === '' || $tipoB === '') {
        throw new InvalidArgumentException('Parámetros inválidos: tipo_a y tipo_b son obligatorios.');
    }

    $pdo = comparativoBuildPdo();
    $dateColumn = comparativoResolveImportLogDateColumn($pdo);

    $logA = $idA > 0
        ? comparativoFetchImportLogByIdSafe($pdo, $idA, $dateColumn)
        : comparativoFetchLatestImportLogSafe($pdo, $tab, $tipoA, $dateColumn);

    $logB = $idB > 0
        ? comparativoFetchImportLogByIdSafe($pdo, $idB, $dateColumn)
        : comparativoFetchLatestImportLogSafe($pdo, $tab, $tipoB, $dateColumn);

    if ($logA === null) {
        throw new RuntimeException('No se encontró importación A para TAB=' . strtoupper($tab) . ' TIPO=' . $tipoA);
    }
    if ($logB === null) {
        throw new RuntimeException('No se encontró importación B para TAB=' . strtoupper($tab) . ' TIPO=' . $tipoB);
    }

    $idA = (int) ($logA['ID'] ?? 0);
    $idB = (int) ($logB['ID'] ?? 0);
    error_log(sprintf('[COMPARATIVO] tab=%s tipo_a=%s tipo_b=%s idA=%d idB=%d', strtoupper($tab), $tipoA, $tipoB, $idA, $idB));

    $rowsA = comparativoLoadRows((string) ($logA['JSON_PATH'] ?? ''));
    $rowsB = comparativoLoadRows((string) ($logB['JSON_PATH'] ?? ''));

    $indexedA = comparativoIndexRows($rowsA);
    $indexedB = comparativoIndexRows($rowsB);
    $allKeys = array_values(array_unique(array_merge(array_keys($indexedA), array_keys($indexedB))));
    $numericColumns = comparativoCollectNumericColumns($rowsA, $rowsB);

    $differences = [];
    foreach ($allKeys as $key) {
        $rowA = $indexedA[$key] ?? [];
        $rowB = $indexedB[$key] ?? [];
        $description = comparativoFindDescription($rowA) ?: comparativoFindDescription($rowB);

        foreach ($numericColumns as $column) {
            $valueA = comparativoNormalizeNumber($rowA[$column] ?? 0);
            $valueB = comparativoNormalizeNumber($rowB[$column] ?? 0);
            $delta = $valueA - $valueB;

            if ($onlyDiff && abs($delta) < 0.000001) {
                continue;
            }

            $differences[] = [
                'CLAVE' => $key,
                'DESCRIPCION' => $description,
                'CAMPO' => $column,
                'VALOR_A' => $valueA,
                'VALOR_B' => $valueB,
                'DELTA' => $delta,
            ];
        }
    }

    $conDiferencias = count(array_filter($differences, static fn(array $row): bool => abs((float) ($row['DELTA'] ?? 0)) > 0.000001));

    return [
        'meta' => [
            'tab' => strtoupper($tab),
            'tipo_a' => $tipoA,
            'tipo_b' => $tipoB,
            'import_log_id_a' => $idA,
            'import_log_id_b' => $idB,
        ],
        'resumen' => [
            'total_claves' => count($allKeys),
            'con_diferencias' => $conDiferencias,
        ],
        'diferencias' => $differences,
    ];
}

try {
    $params = array_merge($_GET, $_POST);
    $result = comparativoBuildSafe($params);
    comparativoJsonResponse([
        'ok' => true,
        'data' => $result,
    ], 200);
} catch (InvalidArgumentException $e) {
    comparativoJsonResponse([
        'ok' => false,
        'message' => 'Solicitud inválida.',
        'detail' => $e->getMessage(),
        'trace_id' => $traceId,
    ], 400);
} catch (Throwable $e) {
    comparativoJsonError($e, $traceId, 500);
}
