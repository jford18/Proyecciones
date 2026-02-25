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

    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function comparativoCsvResponse(string $csv, string $filename = 'diferencias.csv'): never
{
    if (ob_get_length()) {
        ob_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    http_response_code(200);
    echo $csv;
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

function getLastImport(PDO $pdo, string $tab, string $tipo): array
{
    $stmt = $pdo->prepare('SELECT
            ID, TAB, TIPO, ARCHIVO_NOMBRE, HOJA_NOMBRE, SHEET_NAME, FILE_NAME, JSON_PATH, FECHA_CARGA
        FROM IMPORT_LOG
        WHERE UPPER(TRIM(TAB)) = :TAB
          AND UPPER(TRIM(TIPO)) = :TIPO
        ORDER BY FECHA_CARGA DESC, ID DESC
        LIMIT 1');
    $stmt->execute([
        ':TAB' => strtoupper(trim($tab)),
        ':TIPO' => strtoupper(trim($tipo)),
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('No se encontró importación para TAB=' . strtoupper($tab) . ' TIPO=' . strtoupper($tipo) . ' en IMPORT_LOG');
    }

    $jsonPath = trim((string) ($row['JSON_PATH'] ?? ''));
    if ($jsonPath === '') {
        throw new RuntimeException('La importación ID=' . (int) $row['ID'] . ' no tiene JSON_PATH configurado.');
    }

    $absolutePath = comparativoResolveJsonPath($jsonPath);
    if ($absolutePath === '' || !is_file($absolutePath)) {
        throw new RuntimeException('El archivo JSON_PATH no existe físicamente para ID=' . (int) $row['ID'] . ': ' . $jsonPath);
    }

    $row['__ABS_JSON_PATH'] = $absolutePath;
    return $row;
}

function comparativoExtractRows(mixed $decoded): array
{
    if (!is_array($decoded)) {
        return [];
    }

    if (array_is_list($decoded)) {
        return $decoded;
    }

    if (isset($decoded['preview']) && is_array($decoded['preview'])) {
        return $decoded['preview'];
    }

    if (isset($decoded['rows']) && is_array($decoded['rows'])) {
        return $decoded['rows'];
    }

    return [];
}

function comparativoLoadRowsFromImport(array $log): array
{
    $jsonPath = (string) ($log['JSON_PATH'] ?? '');
    $absolutePath = (string) ($log['__ABS_JSON_PATH'] ?? comparativoResolveJsonPath($jsonPath));
    $raw = file_get_contents($absolutePath);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('JSON inválido en: ' . $jsonPath);
    }

    return comparativoExtractRows($decoded);
}

function comparativoFindRowKey(array $row, int $index): string
{
    foreach (['CODIGO', 'ID_CUENTA', 'CUENTA_ID', 'COD'] as $candidate) {
        $value = trim((string) ($row[$candidate] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    $nombre = trim((string) ($row['NOMBRE'] ?? $row['DESCRIPCION'] ?? $row['CUENTA'] ?? ''));
    if ($nombre !== '') {
        return $nombre . '#' . $index;
    }

    return '__ROW__' . $index;
}

function comparativoFindRowName(array $row): string
{
    foreach (['NOMBRE', 'DESCRIPCION', 'NOMBRE_CUENTA', 'CUENTA'] as $field) {
        $value = trim((string) ($row[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function comparativoMonthMap(): array
{
    return [
        'ENERO' => 'M01', 'MES_01' => 'M01', '1' => 'M01', '01' => 'M01', 'M01' => 'M01',
        'FEBRERO' => 'M02', 'MES_02' => 'M02', '2' => 'M02', '02' => 'M02', 'M02' => 'M02',
        'MARZO' => 'M03', 'MES_03' => 'M03', '3' => 'M03', '03' => 'M03', 'M03' => 'M03',
        'ABRIL' => 'M04', 'MES_04' => 'M04', '4' => 'M04', '04' => 'M04', 'M04' => 'M04',
        'MAYO' => 'M05', 'MES_05' => 'M05', '5' => 'M05', '05' => 'M05', 'M05' => 'M05',
        'JUNIO' => 'M06', 'MES_06' => 'M06', '6' => 'M06', '06' => 'M06', 'M06' => 'M06',
        'JULIO' => 'M07', 'MES_07' => 'M07', '7' => 'M07', '07' => 'M07', 'M07' => 'M07',
        'AGOSTO' => 'M08', 'MES_08' => 'M08', '8' => 'M08', '08' => 'M08', 'M08' => 'M08',
        'SEPTIEMBRE' => 'M09', 'SETIEMBRE' => 'M09', 'MES_09' => 'M09', '9' => 'M09', '09' => 'M09', 'M09' => 'M09',
        'OCTUBRE' => 'M10', 'MES_10' => 'M10', '10' => 'M10', 'M10' => 'M10',
        'NOVIEMBRE' => 'M11', 'MES_11' => 'M11', '11' => 'M11', 'M11' => 'M11',
        'DICIEMBRE' => 'M12', 'MES_12' => 'M12', '12' => 'M12', 'M12' => 'M12',
    ];
}

function comparativoNormalizeMonthKey(string $column): ?string
{
    $key = strtoupper(trim($column));
    $key = str_replace([' ', '-'], '_', $key);

    $map = comparativoMonthMap();
    if (isset($map[$key])) {
        return $map[$key];
    }

    if (preg_match('/^MES_?(\d{1,2})$/', $key, $matches) === 1) {
        return sprintf('M%02d', max(1, min(12, (int) $matches[1])));
    }

    return null;
}

function comparativoNormalizeMonthlyValues(array $row): array
{
    $months = [];
    for ($i = 1; $i <= 12; $i++) {
        $months[sprintf('M%02d', $i)] = 0.0;
    }

    foreach ($row as $column => $value) {
        $monthKey = comparativoNormalizeMonthKey((string) $column);
        if ($monthKey === null) {
            continue;
        }
        $months[$monthKey] = comparativoNormalizeNumber($value);
    }

    $months['TOTAL'] = array_sum($months);
    return $months;
}

function comparativoIndexByKey(array $rows): array
{
    $indexed = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $key = comparativoFindRowKey($row, (int) $index);
        $indexed[$key] = [
            'key' => $key,
            'nombre' => comparativoFindRowName($row),
            'months' => comparativoNormalizeMonthlyValues($row),
        ];
    }

    return $indexed;
}

function comparativoBuildRows(array $rowsA, array $rowsB, bool $onlyDiff): array
{
    $indexedA = comparativoIndexByKey($rowsA);
    $indexedB = comparativoIndexByKey($rowsB);
    $allKeys = array_values(array_unique(array_merge(array_keys($indexedA), array_keys($indexedB))));

    $result = [];
    $diffCount = 0;
    foreach ($allKeys as $key) {
        $a = $indexedA[$key]['months'] ?? comparativoNormalizeMonthlyValues([]);
        $b = $indexedB[$key]['months'] ?? comparativoNormalizeMonthlyValues([]);

        $diff = [];
        $hasDiff = false;
        foreach (array_keys($a) as $monthKey) {
            $delta = (float) ($b[$monthKey] ?? 0.0) - (float) ($a[$monthKey] ?? 0.0);
            $diff[$monthKey] = $delta;
            if (abs($delta) > 0.000001) {
                $hasDiff = true;
            }
        }

        if ($onlyDiff && !$hasDiff) {
            continue;
        }

        if ($hasDiff) {
            $diffCount++;
        }

        $result[] = [
            'key' => $key,
            'nombre' => (string) ($indexedA[$key]['nombre'] ?? $indexedB[$key]['nombre'] ?? ''),
            'a' => $a,
            'b' => $b,
            'diff' => $diff,
            'has_diff' => $hasDiff,
        ];
    }

    return [$result, $diffCount, count($allKeys)];
}

function comparativoRowsToLegacyDiff(array $rows): array
{
    $flat = [];
    $months = [];
    for ($i = 1; $i <= 12; $i++) {
        $months[] = sprintf('M%02d', $i);
    }
    $months[] = 'TOTAL';

    foreach ($rows as $row) {
        foreach ($months as $month) {
            $flat[] = [
                'CLAVE' => $row['key'] ?? '',
                'DESCRIPCION' => $row['nombre'] ?? '',
                'CAMPO' => $month,
                'VALOR_A' => (float) (($row['a'][$month] ?? 0)),
                'VALOR_B' => (float) (($row['b'][$month] ?? 0)),
                'DELTA' => (float) (($row['diff'][$month] ?? 0)),
            ];
        }
    }

    return $flat;
}

function comparativoBuildCsv(array $rows): string
{
    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, ['KEY', 'NOMBRE', 'MES', 'VALOR_A', 'VALOR_B', 'DIFERENCIA']);

    $months = [];
    for ($i = 1; $i <= 12; $i++) {
        $months[] = sprintf('M%02d', $i);
    }

    foreach ($rows as $row) {
        if (!($row['has_diff'] ?? false)) {
            continue;
        }

        foreach ($months as $month) {
            $delta = (float) ($row['diff'][$month] ?? 0.0);
            if (abs($delta) <= 0.000001) {
                continue;
            }

            fputcsv($stream, [
                (string) ($row['key'] ?? ''),
                (string) ($row['nombre'] ?? ''),
                $month,
                (float) ($row['a'][$month] ?? 0.0),
                (float) ($row['b'][$month] ?? 0.0),
                $delta,
            ]);
        }
    }

    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);

    return (string) $csv;
}

function comparativoBuildSafe(array $params): array
{
    $tab = strtoupper(trim((string) ($params['tab'] ?? '')));
    $tipoA = strtoupper(trim((string) ($params['tipo_a'] ?? '')));
    $tipoB = strtoupper(trim((string) ($params['tipo_b'] ?? '')));
    $onlyDiff = ((int) ($params['solo_diferencias'] ?? 0)) === 1;
    $export = ((int) ($params['export'] ?? 0)) === 1;

    if ($tab === '' || $tipoA === '' || $tipoB === '') {
        throw new InvalidArgumentException('Parámetros inválidos: tab, tipo_a y tipo_b son obligatorios.');
    }

    $pdo = comparativoBuildPdo();
    $logA = getLastImport($pdo, $tab, $tipoA);
    $logB = getLastImport($pdo, $tab, $tipoB);

    $rowsA = comparativoLoadRowsFromImport($logA);
    $rowsB = comparativoLoadRowsFromImport($logB);
    [$rows, $diffCount, $totalRows] = comparativoBuildRows($rowsA, $rowsB, $onlyDiff);

    return [
        'export' => $export,
        'rows_for_csv' => $rows,
        'payload' => [
            'ok' => true,
            'data' => [
                'meta' => [
                    'tab' => $tab,
                    'tipo_a' => $tipoA,
                    'tipo_b' => $tipoB,
                    'solo_diferencias' => $onlyDiff,
                    'import_a' => [
                        'id' => (int) ($logA['ID'] ?? 0),
                        'tab' => (string) ($logA['TAB'] ?? ''),
                        'tipo' => (string) ($logA['TIPO'] ?? ''),
                        'json_path' => (string) ($logA['JSON_PATH'] ?? ''),
                        'fecha_carga' => (string) ($logA['FECHA_CARGA'] ?? ''),
                    ],
                    'import_b' => [
                        'id' => (int) ($logB['ID'] ?? 0),
                        'tab' => (string) ($logB['TAB'] ?? ''),
                        'tipo' => (string) ($logB['TIPO'] ?? ''),
                        'json_path' => (string) ($logB['JSON_PATH'] ?? ''),
                        'fecha_carga' => (string) ($logB['FECHA_CARGA'] ?? ''),
                    ],
                    // Compatibilidad con UI actual.
                    'import_log_id_a' => (int) ($logA['ID'] ?? 0),
                    'import_log_id_b' => (int) ($logB['ID'] ?? 0),
                ],
                'rows' => $rows,
                'diferencias' => comparativoRowsToLegacyDiff($rows),
                'summary' => [
                    'filas_a' => count($rowsA),
                    'filas_b' => count($rowsB),
                    'filas_total' => $totalRows,
                    'filas_con_diff' => $diffCount,
                ],
                // Compatibilidad con UI actual.
                'resumen' => [
                    'total_claves' => $totalRows,
                    'con_diferencias' => $diffCount,
                ],
            ],
        ],
    ];
}

try {
    $params = array_merge($_GET, $_POST);
    $result = comparativoBuildSafe($params);

    if (($result['export'] ?? false) === true) {
        comparativoCsvResponse(comparativoBuildCsv((array) ($result['rows_for_csv'] ?? [])));
    }

    comparativoJsonResponse((array) ($result['payload'] ?? ['ok' => false]), 200);
} catch (InvalidArgumentException $e) {
    comparativoJsonResponse([
        'ok' => false,
        'message' => 'Solicitud inválida.',
        'detail' => $e->getMessage(),
        'trace_id' => $traceId,
    ], 400);
} catch (RuntimeException $e) {
    comparativoJsonResponse([
        'ok' => false,
        'message' => 'No fue posible generar el comparativo.',
        'detail' => $e->getMessage(),
        'trace_id' => $traceId,
    ], 404);
} catch (Throwable $e) {
    comparativoJsonError($e, $traceId, 500);
}
