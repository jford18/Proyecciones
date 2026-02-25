<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../src/db/Db.php';

use App\db\Db;

function comparativoNormalizeTab(string $tab): string
{
    return strtoupper(trim($tab));
}

function comparativoBuildPdo(): PDO
{
    $config = require __DIR__ . '/../../../src/config/config.php';
    return Db::pdo($config);
}

function comparativoFetchImportLogById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT ID, TAB, TIPO, ARCHIVO_NOMBRE, HOJA_NOMBRE, JSON_PATH, FECHA_CREACION FROM IMPORT_LOG WHERE ID = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function comparativoFetchLatestImportLog(PDO $pdo, string $tab, string $tipo): ?array
{
    $stmt = $pdo->prepare('SELECT ID, TAB, TIPO, ARCHIVO_NOMBRE, HOJA_NOMBRE, JSON_PATH, FECHA_CREACION
        FROM IMPORT_LOG
        WHERE TAB = ? AND TIPO = ? AND JSON_PATH IS NOT NULL AND JSON_PATH <> ""
        ORDER BY ID DESC
        LIMIT 1');
    $stmt->execute([$tab, $tipo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function comparativoResolveJsonPath(string $jsonPath): string
{
    $path = trim($jsonPath);
    if ($path === '') {
        return '';
    }
    if (str_starts_with($path, '/')) {
        return $path;
    }

    return dirname(__DIR__, 3) . '/' . ltrim($path, '/');
}

function comparativoLoadRows(string $jsonPath): array
{
    $absolutePath = comparativoResolveJsonPath($jsonPath);
    if ($absolutePath === '' || !is_file($absolutePath)) {
        throw new RuntimeException('No se encontró el JSON de importación: ' . $jsonPath);
    }

    $raw = file_get_contents($absolutePath);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        error_log('[COMPARATIVO] json_decode inválido path=' . $jsonPath . ' raw=' . substr((string) $raw, 0, 200));
        throw new RuntimeException('JSON inválido en: ' . $jsonPath);
    }

    if (isset($decoded['rows']) && is_array($decoded['rows'])) {
        return $decoded['rows'];
    }

    return array_is_list($decoded) ? $decoded : [];
}

function comparativoNormalizeNumber(mixed $value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    $text = trim((string) $value);
    if ($text === '' || $text === '-') {
        return 0.0;
    }

    $negative = false;
    if (preg_match('/^\(.*\)$/', $text) === 1) {
        $negative = true;
        $text = trim(substr($text, 1, -1));
    }

    $text = preg_replace('/\s+/', '', $text) ?? '';
    $text = preg_replace('/[^0-9,.-]/', '', $text) ?? '';
    if ($text === '' || $text === '-' || $text === ',' || $text === '.') {
        return 0.0;
    }

    $hasComma = str_contains($text, ',');
    $hasDot = str_contains($text, '.');

    if ($hasComma && $hasDot) {
        if (strrpos($text, ',') > strrpos($text, '.')) {
            $text = str_replace('.', '', $text);
            $text = str_replace(',', '.', $text);
        } else {
            $text = str_replace(',', '', $text);
        }
    } elseif ($hasComma) {
        $text = substr_count($text, ',') > 1 ? str_replace(',', '', $text) : str_replace(',', '.', $text);
    } elseif ($hasDot && substr_count($text, '.') > 1) {
        $text = str_replace('.', '', $text);
    }

    $number = is_numeric($text) ? (float) $text : 0.0;
    return $negative ? -abs($number) : $number;
}

function comparativoIsKeyColumn(string $column): bool
{
    return in_array(strtoupper($column), ['CODIGO', 'ID_CUENTA'], true);
}

function comparativoFindKey(array $row): string
{
    foreach (['CODIGO', 'ID_CUENTA'] as $candidate) {
        $value = trim((string) ($row[$candidate] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    foreach ($row as $key => $value) {
        if (in_array(strtoupper((string) $key), ['__ROW', 'ROWNUM', 'WARNINGS', 'WARNING', '_META'], true)) {
            continue;
        }
        $text = trim((string) $value);
        if ($text !== '') {
            return $text;
        }
    }

    return '__EMPTY__' . md5((string) json_encode($row, JSON_UNESCAPED_UNICODE));
}

function comparativoFindDescription(array $row): string
{
    foreach (['DESCRIPCION', 'NOMBRE_CUENTA', 'CUENTA'] as $field) {
        $value = trim((string) ($row[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function comparativoCollectNumericColumns(array $rowsA, array $rowsB): array
{
    $columns = [];

    foreach ([$rowsA, $rowsB] as $rows) {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $column => $value) {
                $name = strtoupper((string) $column);
                if ($name === '' || comparativoIsKeyColumn($name) || in_array($name, ['DESCRIPCION', 'NOMBRE_CUENTA', 'CUENTA', 'TIPO', 'ANIO', 'PERIODO', '__ROW', 'ROWNUM', 'WARNINGS', 'WARNING', '_META'], true)) {
                    continue;
                }

                if (is_numeric($value) || preg_match('/[\d]/', (string) $value) === 1 || in_array($name, ['TOTAL', 'TOTAL_RECALCULADO'], true)) {
                    $columns[$name] = true;
                }
            }
        }
    }

    return array_values(array_keys($columns));
}

function comparativoIndexRows(array $rows): array
{
    $indexed = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $indexed[comparativoFindKey($row)] = $row;
    }

    return $indexed;
}

function comparativoBuild(array $params): array
{
    $tab = comparativoNormalizeTab((string) ($params['tab'] ?? ''));
    $tipoA = strtoupper(trim((string) ($params['tipo_a'] ?? 'REAL')));
    $tipoB = strtoupper(trim((string) ($params['tipo_b'] ?? 'PRESUPUESTO')));
    $onlyDiff = ((int) ($params['solo_diferencias'] ?? 0)) === 1;
    $idA = (int) ($params['import_log_id_a'] ?? ($params['log_id_a'] ?? 0));
    $idB = (int) ($params['import_log_id_b'] ?? ($params['log_id_b'] ?? 0));

    if ($tab === '' || $tipoA === '' || $tipoB === '') {
        throw new RuntimeException('Parámetros inválidos: tab, tipo_a y tipo_b son obligatorios.');
    }

    $pdo = comparativoBuildPdo();
    $logA = $idA > 0 ? comparativoFetchImportLogById($pdo, $idA) : comparativoFetchLatestImportLog($pdo, $tab, $tipoA);
    $logB = $idB > 0 ? comparativoFetchImportLogById($pdo, $idB) : comparativoFetchLatestImportLog($pdo, $tab, $tipoB);

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
        'ok' => true,
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
