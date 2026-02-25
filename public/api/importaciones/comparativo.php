<?php

declare(strict_types=1);

use App\db\Db;

require __DIR__ . '/../../../../vendor/autoload.php';

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizeTabId(string $tab): string
{
    $normalized = strtolower(trim($tab));
    if ($normalized === 'eri') {
        return 'ingresos';
    }

    return $normalized;
}

function fetchImportLogById(PDO $pdo, int $id): ?array
{
    $sql = 'SELECT
    A.ID, A.TAB, A.TIPO, A.ARCHIVO_NOMBRE, A.HOJA_NOMBRE, A.JSON_PATH, A.FECHA_CREACION
FROM IMPORT_LOG A
WHERE A.ID = ?
LIMIT 1;';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function fetchLatestImportLog(PDO $pdo, string $tab, string $tipo): ?array
{
    $sql = 'SELECT
    A.ID, A.TAB, A.TIPO, A.ARCHIVO_NOMBRE, A.HOJA_NOMBRE, A.JSON_PATH, A.FECHA_CREACION
FROM IMPORT_LOG A
WHERE A.TAB = ? AND A.TIPO = ?
ORDER BY A.ID DESC
LIMIT 1;';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tab, $tipo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function resolveJsonAbsolutePath(string $jsonPath): string
{
    if ($jsonPath === '') {
        return '';
    }
    if (str_starts_with($jsonPath, '/')) {
        return $jsonPath;
    }

    return dirname(__DIR__, 4) . '/' . ltrim($jsonPath, '/');
}

function loadRowsFromJsonPath(string $jsonPath): array
{
    $absolutePath = resolveJsonAbsolutePath($jsonPath);
    if ($absolutePath === '' || !is_file($absolutePath)) {
        throw new RuntimeException('No se encontró el JSON de importación: ' . $jsonPath);
    }

    $raw = file_get_contents($absolutePath);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('JSON inválido en: ' . $jsonPath);
    }

    if (isset($decoded['rows']) && is_array($decoded['rows'])) {
        return $decoded['rows'];
    }

    return array_is_list($decoded) ? $decoded : [];
}

function isTechnicalColumn(string $column): bool
{
    return in_array(strtolower($column), ['__row', 'rownum', 'warnings', 'warning', '_meta'], true);
}

function normalizeCompareValue(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_int($value) || is_float($value)) {
        return sprintf('%.10F', (float) $value);
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }

    $numeric = preg_replace('/\s+/', '', $text) ?? '';
    $numeric = preg_replace('/[^0-9,\.\-]/', '', $numeric) ?? '';
    if ($numeric !== '' && preg_match('/^-?[0-9.,]+$/', $numeric) === 1) {
        $hasComma = str_contains($numeric, ',');
        $hasDot = str_contains($numeric, '.');
        if ($hasComma && $hasDot) {
            if (strrpos($numeric, ',') > strrpos($numeric, '.')) {
                $numeric = str_replace('.', '', $numeric);
                $numeric = str_replace(',', '.', $numeric);
            } else {
                $numeric = str_replace(',', '', $numeric);
            }
        } elseif ($hasComma) {
            $commaCount = substr_count($numeric, ',');
            $numeric = $commaCount > 1 ? str_replace(',', '', $numeric) : str_replace(',', '.', $numeric);
        } elseif ($hasDot) {
            $dotCount = substr_count($numeric, '.');
            if ($dotCount > 1) {
                $numeric = str_replace('.', '', $numeric);
            } else {
                $decimals = explode('.', $numeric);
                $decimalPart = $decimals[1] ?? '';
                if (strlen($decimalPart) === 3) {
                    $numeric = str_replace('.', '', $numeric);
                }
            }
        }

        if (is_numeric($numeric)) {
            return sprintf('%.10F', (float) $numeric);
        }
    }

    return mb_strtoupper($text, 'UTF-8');
}

function rowColumns(array $row): array
{
    return array_values(array_filter(array_keys($row), static fn (string $key): bool => !isTechnicalColumn($key)));
}

function determineRowKey(array $row): string
{
    $idCuenta = trim((string) ($row['ID_CUENTA'] ?? ''));
    $periodo = trim((string) ($row['PERIODO'] ?? ''));
    if ($idCuenta !== '') {
        return $periodo !== '' ? ($idCuenta . '|' . $periodo) : $idCuenta;
    }

    foreach ($row as $column => $value) {
        if (isTechnicalColumn((string) $column)) {
            continue;
        }
        if (trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }

    return '__EMPTY__' . md5(json_encode($row, JSON_UNESCAPED_UNICODE));
}

function indexRowsByKey(array $rows): array
{
    $indexed = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = determineRowKey($row);
        $indexed[$key] = $row;
    }

    return $indexed;
}

$config = require __DIR__ . '/../../../../src/config/config.php';
$pdo = Db::pdo($config);

$tab = normalizeTabId((string) ($_GET['tab'] ?? 'eri'));
$tipoA = strtoupper(trim((string) ($_GET['tipo_a'] ?? 'REAL')));
$tipoB = strtoupper(trim((string) ($_GET['tipo_b'] ?? 'PRESUPUESTO')));
$logIdA = (int) ($_GET['log_id_a'] ?? 0);
$logIdB = (int) ($_GET['log_id_b'] ?? 0);
$onlyDiff = (int) ($_GET['solo_diferencias'] ?? 1) === 1;

if ($tab === '' || $tipoA === '' || $tipoB === '') {
    jsonResponse(['ok' => false, 'message' => 'Parámetros inválidos: tab, tipo_a y tipo_b son obligatorios.'], 422);
}

try {
    $logA = $logIdA > 0 ? fetchImportLogById($pdo, $logIdA) : fetchLatestImportLog($pdo, $tab, $tipoA);
    if ($logA === null) {
        jsonResponse(['ok' => false, 'message' => 'No hay logs para TAB=' . strtoupper($tab) . ' y TIPO=' . $tipoA . '.'], 404);
    }

    $logB = $logIdB > 0 ? fetchImportLogById($pdo, $logIdB) : fetchLatestImportLog($pdo, $tab, $tipoB);
    if ($logB === null) {
        jsonResponse(['ok' => false, 'message' => 'No hay logs para TAB=' . strtoupper($tab) . ' y TIPO=' . $tipoB . '.'], 404);
    }

    $rowsA = loadRowsFromJsonPath((string) ($logA['JSON_PATH'] ?? ''));
    $rowsB = loadRowsFromJsonPath((string) ($logB['JSON_PATH'] ?? ''));
    $indexedA = indexRowsByKey($rowsA);
    $indexedB = indexRowsByKey($rowsB);
    $allKeys = array_values(array_unique(array_merge(array_keys($indexedA), array_keys($indexedB))));

    $columnsMap = [];
    foreach ([$rowsA, $rowsB] as $dataset) {
        foreach ($dataset as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (rowColumns($row) as $column) {
                $columnsMap[$column] = true;
            }
        }
    }
    $columns = array_values(array_keys($columnsMap));

    $resultRows = [];
    $summary = [
        'iguales' => 0,
        'cambios' => 0,
        'nuevo_en_a' => 0,
        'falta_en_a' => 0,
        'total_a' => count($indexedA),
        'total_b' => count($indexedB),
        'total_resultado' => 0,
    ];

    foreach ($allKeys as $key) {
        $rowA = $indexedA[$key] ?? null;
        $rowB = $indexedB[$key] ?? null;
        $status = 'IGUAL';
        $differentColumns = [];

        if ($rowA !== null && $rowB === null) {
            $status = 'NUEVO_EN_A';
            $summary['nuevo_en_a']++;
        } elseif ($rowA === null && $rowB !== null) {
            $status = 'FALTA_EN_A';
            $summary['falta_en_a']++;
        } else {
            foreach ($columns as $column) {
                $valueA = $rowA[$column] ?? null;
                $valueB = $rowB[$column] ?? null;
                if (normalizeCompareValue($valueA) !== normalizeCompareValue($valueB)) {
                    $differentColumns[] = $column;
                }
            }

            if ($differentColumns !== []) {
                $status = 'CAMBIO';
                $summary['cambios']++;
            } else {
                $summary['iguales']++;
            }
        }

        if ($onlyDiff && $status === 'IGUAL') {
            continue;
        }

        $resultRows[] = [
            'key' => $key,
            'estado' => $status,
            'columnas_diferentes' => $differentColumns,
            'a' => $rowA,
            'b' => $rowB,
        ];
    }

    $summary['total_resultado'] = count($resultRows);

    // COMPARATIVO: consulta IMPORT_LOG, compara dos logs (A/B) por TAB+TIPO o ID explícito, usa KEY por ID_CUENTA(+PERIODO) y filtra solo_diferencias al construir filas.
    jsonResponse([
        'ok' => true,
        'tab' => $tab,
        'tipo_a' => $tipoA,
        'tipo_b' => $tipoB,
        'log_a' => [
            'id' => (int) ($logA['ID'] ?? 0),
            'archivo_nombre' => (string) ($logA['ARCHIVO_NOMBRE'] ?? ''),
            'hoja_nombre' => (string) ($logA['HOJA_NOMBRE'] ?? ''),
            'json_path' => (string) ($logA['JSON_PATH'] ?? ''),
            'fecha_creacion' => (string) ($logA['FECHA_CREACION'] ?? ''),
        ],
        'log_b' => [
            'id' => (int) ($logB['ID'] ?? 0),
            'archivo_nombre' => (string) ($logB['ARCHIVO_NOMBRE'] ?? ''),
            'hoja_nombre' => (string) ($logB['HOJA_NOMBRE'] ?? ''),
            'json_path' => (string) ($logB['JSON_PATH'] ?? ''),
            'fecha_creacion' => (string) ($logB['FECHA_CREACION'] ?? ''),
        ],
        'resumen' => $summary,
        'columnas' => $columns,
        'filas' => $resultRows,
    ]);
} catch (Throwable $e) {
    jsonResponse(['ok' => false, 'message' => $e->getMessage()], 500);
}

