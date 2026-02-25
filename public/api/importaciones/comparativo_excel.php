<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

require_once __DIR__ . '/comparativo_shared.php';
$autoload = __DIR__ . '/../../../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

function cmpExcelJsonResponse(array $payload, int $status = 200): never
{
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cmpExcelNormalizeText(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($normalized === false) {
        $normalized = $value;
    }

    return strtoupper(trim((string) preg_replace('/\s+/', ' ', $normalized)));
}

function cmpExcelResolveDateColumn(PDO $pdo): ?string
{
    $stmt = $pdo->query('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND UPPER(TABLE_NAME)=\'IMPORT_LOG\'');
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
        $columns[strtoupper((string) $column)] = (string) $column;
    }

    foreach (['FECHA_CREACION', 'CREATED_AT', 'FECHA_IMPORTACION', 'FECHA'] as $candidate) {
        if (isset($columns[$candidate])) {
            return $columns[$candidate];
        }
    }

    return null;
}

function cmpExcelFetchLatestImportLog(PDO $pdo, string $tab, string $tipo, ?string $dateColumn): ?array
{
    $select = 'ID, TAB, TIPO, JSON_PATH';
    $orderBy = 'ID DESC';
    if ($dateColumn !== null) {
        $safe = preg_replace('/[^A-Za-z0-9_]/', '', $dateColumn);
        if ($safe !== null && $safe !== '') {
            $select .= ', `' . $safe . '` AS FECHA_CREACION';
            $orderBy = 'FECHA_CREACION DESC, ID DESC';
        }
    }

    $stmt = $pdo->prepare("SELECT {$select} FROM IMPORT_LOG WHERE TAB = ? AND TIPO = ? AND JSON_PATH IS NOT NULL AND JSON_PATH <> '' ORDER BY {$orderBy} LIMIT 1");
    $stmt->execute([$tab, $tipo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function cmpExcelMonthMap(): array
{
    return [
        'ENERO' => 'ENERO', 'FEBRERO' => 'FEBRERO', 'MARZO' => 'MARZO', 'ABRIL' => 'ABRIL',
        'MAYO' => 'MAYO', 'JUNIO' => 'JUNIO', 'JULIO' => 'JULIO', 'AGOSTO' => 'AGOSTO',
        'SEPTIEMBRE' => 'SEPTIEMBRE', 'SETIEMBRE' => 'SEPTIEMBRE', 'OCTUBRE' => 'OCTUBRE',
        'NOVIEMBRE' => 'NOVIEMBRE', 'DICIEMBRE' => 'DICIEMBRE',
    ];
}

function cmpExcelFindHeaderRow(Worksheet $sheet): array
{
    $monthMap = cmpExcelMonthMap();
    $maxRow = min(80, max(1, $sheet->getHighestDataRow()));
    $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

    for ($row = 1; $row <= $maxRow; $row++) {
        $cuentaCol = null;
        $monthCols = [];
        for ($col = 1; $col <= $maxCol; $col++) {
            $raw = (string) $sheet->getCellByColumnAndRow($col, $row)->getFormattedValue();
            $cell = cmpExcelNormalizeText($raw);
            if ($cell === '') {
                continue;
            }
            if (in_array($cell, ['CUENTA', 'CODIGO', 'ID_CUENTA'], true)) {
                $cuentaCol = $col;
                continue;
            }
            if (isset($monthMap[$cell])) {
                $monthCols[$monthMap[$cell]] = $col;
            }
        }

        if ($cuentaCol !== null && $monthCols !== []) {
            return ['header_row' => $row, 'cuenta_col' => $cuentaCol, 'month_cols' => $monthCols];
        }
    }

    throw new RuntimeException('No se encontró cabecera válida (CUENTA + meses) en la plantilla ERI.');
}

function cmpExcelLoadTemplateRows(string $tab): array
{
    $path = dirname(__DIR__, 3) . '/templates/DB_presupuesto_ERI PLANTILLA.xlsx';
    if (!is_file($path)) {
        throw new RuntimeException('No se encontró plantilla ERI en /templates/DB_presupuesto_ERI PLANTILLA.xlsx');
    }
    if (!class_exists(IOFactory::class)) {
        throw new RuntimeException('PhpSpreadsheet no está disponible para leer la plantilla.');
    }

    $spreadsheet = IOFactory::load($path);
    $sheet = $spreadsheet->getSheetByName($tab) ?? $spreadsheet->getSheet(0);
    if ($sheet === null) {
        throw new RuntimeException('No se pudo abrir la hoja de la plantilla ERI.');
    }

    $header = cmpExcelFindHeaderRow($sheet);
    $cuentaCol = (int) $header['cuenta_col'];
    $monthCols = (array) $header['month_cols'];

    $rows = [];
    for ($r = ((int) $header['header_row']) + 1, $max = $sheet->getHighestDataRow(); $r <= $max; $r++) {
        $cuenta = trim((string) $sheet->getCellByColumnAndRow($cuentaCol, $r)->getFormattedValue());
        if ($cuenta === '') {
            continue;
        }

        $rows[$cuenta] = [];
        foreach ($monthCols as $month => $col) {
            $rows[$cuenta][$month] = comparativoNormalizeNumber($sheet->getCellByColumnAndRow((int) $col, $r)->getFormattedValue());
        }
    }

    return ['rows' => $rows, 'months' => array_values(array_keys($monthCols)), 'template_path' => $path];
}

function cmpExcelLoadImportRows(string $jsonPath, array $months): array
{
    $monthSet = array_fill_keys($months, true);
    $aliases = cmpExcelMonthMap();
    $rows = comparativoLoadRows($jsonPath);
    $result = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $cuenta = '';
        foreach (['CODIGO', 'ID_CUENTA', 'CUENTA'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                $cuenta = $value;
                break;
            }
        }
        if ($cuenta === '') {
            continue;
        }

        $result[$cuenta] ??= array_fill_keys($months, 0.0);
        foreach ($row as $column => $value) {
            $month = $aliases[cmpExcelNormalizeText((string) $column)] ?? null;
            if ($month === null || !isset($monthSet[$month])) {
                continue;
            }
            $result[$cuenta][$month] = comparativoNormalizeNumber($value);
        }
    }

    return $result;
}

function cmpExcelBuild(array $params): array
{
    $tab = comparativoNormalizeTab((string) ($params['tab'] ?? ''));
    if ($tab === '') {
        throw new InvalidArgumentException('Falta parámetro tab');
    }

    $tipoB = strtoupper(trim((string) ($params['tipo_b'] ?? 'PRESUPUESTO')));
    if ($tipoB === '') {
        throw new InvalidArgumentException('Falta parámetro tipo_b');
    }

    $onlyDiff = ((int) ($params['solo_diferencias'] ?? 0)) === 1;
    $excel = cmpExcelLoadTemplateRows($tab);
    $excelRows = (array) ($excel['rows'] ?? []);
    $months = (array) ($excel['months'] ?? []);

    $pdo = comparativoBuildPdo();
    $logB = cmpExcelFetchLatestImportLog($pdo, $tab, $tipoB, cmpExcelResolveDateColumn($pdo));
    if ($logB === null) {
        throw new RuntimeException('No se encontró importación B para TAB=' . $tab . ' TIPO=' . $tipoB);
    }

    $importRows = cmpExcelLoadImportRows((string) ($logB['JSON_PATH'] ?? ''), $months);
    $accounts = array_values(array_unique(array_merge(array_keys($excelRows), array_keys($importRows))));

    $differences = [];
    foreach ($accounts as $cuenta) {
        foreach ($months as $mes) {
            $valorExcel = comparativoNormalizeNumber($excelRows[$cuenta][$mes] ?? 0);
            $valorImport = comparativoNormalizeNumber($importRows[$cuenta][$mes] ?? 0);
            $delta = $valorImport - $valorExcel;
            if ($onlyDiff && abs($delta) < 0.000001) {
                continue;
            }
            $differences[] = [
                'cuenta' => $cuenta,
                'mes' => $mes,
                'valor_excel' => $valorExcel,
                'valor_import' => $valorImport,
                'delta' => $delta,
            ];
        }
    }

    return [
        'resumen' => [
            'total_items' => count($accounts) * count($months),
            'total_diferencias' => count($differences),
            'tipo_b' => $tipoB,
            'tab' => $tab,
        ],
        'meta' => [
            'tab' => $tab,
            'tipo_a' => 'EXCEL',
            'tipo_b' => $tipoB,
            'import_log_id_b' => (int) ($logB['ID'] ?? 0),
            'template_path' => (string) ($excel['template_path'] ?? ''),
            'months' => $months,
        ],
        'diferencias' => $differences,
    ];
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    header('Content-Type: application/json; charset=utf-8');
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ob_start();
    $traceId = uniqid('cmp_excel_', true);

    set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    try {
        $payload = cmpExcelBuild(array_merge($_GET, $_POST));
        cmpExcelJsonResponse(['ok' => true, 'data' => $payload], 200);
    } catch (InvalidArgumentException $e) {
        cmpExcelJsonResponse(['ok' => false, 'message' => 'Solicitud inválida.', 'detail' => $e->getMessage(), 'trace_id' => $traceId], 400);
    } catch (Throwable $e) {
        error_log(sprintf('[COMPARATIVO_EXCEL][%s] %s', $traceId, $e->getMessage()));
        cmpExcelJsonResponse(['ok' => false, 'message' => 'No fue posible generar el comparativo con Excel.', 'detail' => $e->getMessage(), 'trace_id' => $traceId], 500);
    }
}
