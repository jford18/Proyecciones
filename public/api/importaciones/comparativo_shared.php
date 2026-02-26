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
    $stmt = $pdo->prepare('SELECT ID, TAB, TIPO, ARCHIVO_NOMBRE, HOJA_NOMBRE, SHEET_NAME, FILE_NAME, JSON_PATH, FECHA_CARGA FROM IMPORT_LOG WHERE ID = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function comparativoFetchLatestImportLog(PDO $pdo, string $tab, string $tipo): ?array
{
    $stmt = $pdo->prepare('SELECT ID, TAB, TIPO, ARCHIVO_NOMBRE, HOJA_NOMBRE, SHEET_NAME, FILE_NAME, JSON_PATH, FECHA_CARGA
        FROM IMPORT_LOG
        WHERE UPPER(TRIM(TAB)) = :TAB
          AND UPPER(TRIM(TIPO)) = :TIPO
          AND JSON_PATH IS NOT NULL
          AND TRIM(JSON_PATH) <> ""
        ORDER BY FECHA_CARGA DESC, ID DESC
        LIMIT 1');
    $stmt->execute([
        ':TAB' => strtoupper(trim($tab)),
        ':TIPO' => strtoupper(trim($tipo)),
    ]);
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
        throw new RuntimeException('JSON inválido en: ' . $jsonPath);
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
        if (substr_count($text, ',') > 1) {
            $text = str_replace(',', '', $text);
        } else {
            $parts = explode(',', $text);
            $text = (count($parts) === 2 && strlen($parts[1]) === 3) ? str_replace(',', '', $text) : str_replace(',', '.', $text);
        }
    } elseif ($hasDot) {
        if (substr_count($text, '.') > 1) {
            $text = str_replace('.', '', $text);
        } else {
            $parts = explode('.', $text);
            if (count($parts) === 2 && strlen($parts[1]) === 3) {
                $text = str_replace('.', '', $text);
            }
        }
    }

    $number = is_numeric($text) ? (float) $text : 0.0;
    return $negative ? -abs($number) : $number;
}

function comparativoNormalizeColumnName(string $column): string
{
    return strtoupper(trim($column));
}

function comparativoFindKey(array $row, int $index): string
{
    foreach (['ID_CUENTA', 'CODIGO'] as $candidate) {
        $value = trim((string) ($row[$candidate] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    $nivel = trim((string) ($row['NIVEL'] ?? ''));
    $nombre = trim((string) ($row['NOMBRE_CUENTA'] ?? $row['DESCRIPCION'] ?? $row['NOMBRE'] ?? $row['CUENTA'] ?? ''));
    if ($nivel !== '' || $nombre !== '') {
        return trim($nivel . '|' . $nombre, '|');
    }

    return '__ROW__' . $index;
}

function comparativoFindDescription(array $row): string
{
    foreach (['NOMBRE_CUENTA', 'DESCRIPCION', 'NOMBRE', 'CUENTA'] as $field) {
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
    $ignored = [
        'ID_CUENTA', 'CODIGO', 'CUENTA_ID', 'COD', 'DESCRIPCION', 'NOMBRE_CUENTA', 'CUENTA', 'NOMBRE',
        'TIPO', 'ANIO', 'PERIODO', 'TAB', 'NIVEL', '__ROW', 'ROWNUM', 'WARNINGS', 'WARNING', '_META',
    ];

    foreach ([$rowsA, $rowsB] as $rows) {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($row as $column => $value) {
                $name = comparativoNormalizeColumnName((string) $column);
                if ($name === '' || in_array($name, $ignored, true)) {
                    continue;
                }

                if (is_numeric($value) || preg_match('/[-(]?\d+[\d\s,.)-]*/', (string) $value) === 1) {
                    $columns[$name] = true;
                }
            }
        }
    }

    return array_values(array_keys($columns));
}

function comparativoNormalizeRowByColumns(array $row, array $numericColumns): array
{
    $normalized = [];
    foreach ($numericColumns as $column) {
        $normalized[$column] = comparativoNormalizeNumber($row[$column] ?? 0);
    }

    return $normalized;
}

function comparativoIndexRows(array $rows, array $numericColumns): array
{
    $indexed = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $key = comparativoFindKey($row, (int) $index);
        $indexed[$key] = [
            'id_cuenta' => trim((string) ($row['ID_CUENTA'] ?? '')),
            'codigo' => trim((string) ($row['CODIGO'] ?? '')),
            'nombre_cuenta' => comparativoFindDescription($row),
            'numeric' => comparativoNormalizeRowByColumns($row, $numericColumns),
        ];
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
        throw new InvalidArgumentException('Parámetros inválidos: tab, tipo_a y tipo_b son obligatorios.');
    }

    $pdo = comparativoBuildPdo();
    $logA = $idA > 0 ? comparativoFetchImportLogById($pdo, $idA) : comparativoFetchLatestImportLog($pdo, $tab, $tipoA);
    $logB = $idB > 0 ? comparativoFetchImportLogById($pdo, $idB) : comparativoFetchLatestImportLog($pdo, $tab, $tipoB);

    if ($logA === null) {
        throw new RuntimeException('No se encontró importación A para TAB=' . $tab . ' TIPO=' . $tipoA . '. Sugerencia: importa un archivo ' . $tipoA . ' en la pestaña ' . $tab . '.');
    }
    if ($logB === null) {
        throw new RuntimeException('No se encontró importación B para TAB=' . $tab . ' TIPO=' . $tipoB . '. Sugerencia: importa un archivo ' . $tipoB . ' en la pestaña ' . $tab . '.');
    }

    $rowsA = comparativoLoadRows((string) ($logA['JSON_PATH'] ?? ''));
    $rowsB = comparativoLoadRows((string) ($logB['JSON_PATH'] ?? ''));

    $numericColumns = comparativoCollectNumericColumns($rowsA, $rowsB);
    $indexedA = comparativoIndexRows($rowsA, $numericColumns);
    $indexedB = comparativoIndexRows($rowsB, $numericColumns);
    $allKeys = array_values(array_unique(array_merge(array_keys($indexedA), array_keys($indexedB))));

    $rows = [];
    $flatDifferences = [];
    $diffRows = 0;

    foreach ($allKeys as $key) {
        $rowA = $indexedA[$key] ?? null;
        $rowB = $indexedB[$key] ?? null;
        $valsA = $rowA['numeric'] ?? array_fill_keys($numericColumns, 0.0);
        $valsB = $rowB['numeric'] ?? array_fill_keys($numericColumns, 0.0);

        $rowOut = [
            'id_cuenta' => $rowA['id_cuenta'] ?? $rowB['id_cuenta'] ?? '',
            'codigo' => $rowA['codigo'] ?? $rowB['codigo'] ?? '',
            'cuenta' => $key,
            'nombre_cuenta' => $rowA['nombre_cuenta'] ?? $rowB['nombre_cuenta'] ?? '',
            'has_diff' => false,
        ];

        foreach ($numericColumns as $column) {
            $valueA = (float) ($valsA[$column] ?? 0.0);
            $valueB = (float) ($valsB[$column] ?? 0.0);
            $delta = $valueB - $valueA;

            $rowOut[$column . '_A'] = $valueA;
            $rowOut[$column . '_B'] = $valueB;
            $rowOut[$column . '_DELTA'] = $delta;

            $flatDifferences[] = [
                'CLAVE' => $key,
                'DESCRIPCION' => $rowOut['nombre_cuenta'],
                'CAMPO' => $column,
                'VALOR_A' => $valueA,
                'VALOR_B' => $valueB,
                'DELTA' => $delta,
            ];

            if (abs($delta) > 0.000001) {
                $rowOut['has_diff'] = true;
            }
        }

        if ($onlyDiff && !$rowOut['has_diff']) {
            continue;
        }

        if ($rowOut['has_diff']) {
            $diffRows++;
        }

        $rows[] = $rowOut;
    }

    if ($onlyDiff) {
        $flatDifferences = array_values(array_filter($flatDifferences, static fn(array $r): bool => abs((float) ($r['DELTA'] ?? 0.0)) > 0.000001));
    }

    return [
        'ok' => true,
        'tab' => $tab,
        'tipo_a' => $tipoA,
        'tipo_b' => $tipoB,
        'solo_diferencias' => $onlyDiff,
        'columns' => $numericColumns,
        'rows' => $rows,
        'meta' => [
            'total' => count($rows),
            'diferencias' => $diffRows,
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
            'import_log_id_a' => (int) ($logA['ID'] ?? 0),
            'import_log_id_b' => (int) ($logB['ID'] ?? 0),
        ],
        // Compatibilidad con UI actual.
        'resumen' => [
            'total_claves' => count($allKeys),
            'con_diferencias' => $diffRows,
        ],
        'diferencias' => $flatDifferences,
    ];
}
