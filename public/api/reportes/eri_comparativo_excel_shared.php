<?php

declare(strict_types=1);

use App\db\Db;
use App\services\EriService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

require_once __DIR__ . '/../../../vendor/autoload.php';

const ERI_COMPARATIVO_MONTHS = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];

function eriComparativoNormalizeNumber(mixed $value): float
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
        if ((strrpos($text, ',') ?: 0) > (strrpos($text, '.') ?: 0)) {
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

function eriComparativoNormalizeText(string $value): string
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

function eriComparativoMonthAliases(): array
{
    return [
        'ENERO' => 'ENERO',
        'FEBRERO' => 'FEBRERO',
        'MARZO' => 'MARZO',
        'ABRIL' => 'ABRIL',
        'MAYO' => 'MAYO',
        'JUNIO' => 'JUNIO',
        'JULIO' => 'JULIO',
        'AGOSTO' => 'AGOSTO',
        'SEPTIEMBRE' => 'SEPTIEMBRE',
        'SETIEMBRE' => 'SEPTIEMBRE',
        'OCTUBRE' => 'OCTUBRE',
        'NOVIEMBRE' => 'NOVIEMBRE',
        'DICIEMBRE' => 'DICIEMBRE',
    ];
}

function eriComparativoFindExcelHeaders(Worksheet $sheet): array
{
    $aliases = eriComparativoMonthAliases();
    $maxRow = min(80, max(1, $sheet->getHighestDataRow()));
    $maxCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

    for ($row = 1; $row <= $maxRow; $row++) {
        $codeCol = null;
        $nameCol = null;
        $monthCols = [];

        for ($col = 1; $col <= $maxCol; $col++) {
            $raw = (string) $sheet->getCellByColumnAndRow($col, $row)->getFormattedValue();
            $label = eriComparativoNormalizeText($raw);
            if ($label === '') {
                continue;
            }

            if (in_array($label, ['CODIGO', 'CUENTA', 'ID_CUENTA'], true)) {
                $codeCol = $col;
                continue;
            }

            if (in_array($label, ['NOMBRE', 'DESCRIPCION', 'NOMBRE_CUENTA'], true)) {
                $nameCol = $col;
                continue;
            }

            if (isset($aliases[$label]) && !isset($monthCols[$aliases[$label]])) {
                $monthCols[$aliases[$label]] = $col;
            }
        }

        if ($codeCol !== null && $nameCol !== null && count($monthCols) >= 12) {
            return [
                'header_row' => $row,
                'code_col' => $codeCol,
                'name_col' => $nameCol,
                'month_cols' => $monthCols,
            ];
        }
    }

    throw new RuntimeException('No se pudo leer el Excel ERI. No se encontró una cabecera válida (CODIGO, NOMBRE y meses).');
}

function eriComparativoReadExcelRows(string $filePath): array
{
    if (!is_file($filePath)) {
        throw new RuntimeException('No se pudo leer el Excel ERI.');
    }

    $spreadsheet = IOFactory::load($filePath);
    $sheet = $spreadsheet->getSheetByName('ERI');
    if ($sheet === null) {
        throw new RuntimeException('No se pudo leer el Excel ERI. La hoja "ERI" no existe.');
    }

    $header = eriComparativoFindExcelHeaders($sheet);
    $codeCol = (int) $header['code_col'];
    $nameCol = (int) $header['name_col'];
    $monthCols = (array) $header['month_cols'];

    $rows = [];
    for ($r = ((int) $header['header_row']) + 1, $max = $sheet->getHighestDataRow(); $r <= $max; $r++) {
        $code = trim((string) $sheet->getCellByColumnAndRow($codeCol, $r)->getFormattedValue());
        if ($code === '') {
            continue;
        }

        $name = trim((string) $sheet->getCellByColumnAndRow($nameCol, $r)->getFormattedValue());
        $rows[$code] ??= ['NOMBRE' => $name, 'MESES' => []];
        if ($rows[$code]['NOMBRE'] === '' && $name !== '') {
            $rows[$code]['NOMBRE'] = $name;
        }

        foreach (ERI_COMPARATIVO_MONTHS as $month) {
            $col = (int) ($monthCols[$month] ?? 0);
            $value = $col > 0 ? $sheet->getCellByColumnAndRow($col, $r)->getFormattedValue() : 0;
            $rows[$code]['MESES'][$month] = eriComparativoNormalizeNumber($value);
        }
    }

    return $rows;
}

function eriComparativoGetSistemaRows(int $anio, string $tipo): array
{
    $config = require __DIR__ . '/../../../src/config/config.php';
    $pdo = Db::pdo($config);
    $service = new EriService($pdo);
    $payload = $service->build($anio, 0.15, 0.25);
    $sourceRows = (array) ($payload['rows'] ?? []);

    $rows = [];
    foreach ($sourceRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = trim((string) ($row['CODE'] ?? ''));
        if ($code === '') {
            continue;
        }

        $rows[$code] = [
            'NOMBRE' => trim((string) ($row['DESCRIPCION'] ?? '')),
            'MESES' => [],
        ];
        foreach (ERI_COMPARATIVO_MONTHS as $month) {
            $rows[$code]['MESES'][$month] = (float) ($row[$month] ?? 0.0);
        }
    }

    $hasData = false;
    foreach ($rows as $item) {
        foreach ((array) ($item['MESES'] ?? []) as $value) {
            if (abs((float) $value) > 0.000001) {
                $hasData = true;
                break 2;
            }
        }
    }

    if (!$hasData) {
        throw new RuntimeException('No se pudo obtener ERI del sistema. No hay datos para el año/tipo solicitado.');
    }

    return $rows;
}

function eriComparativoBuildRows(array $excelRows, array $sistemaRows, bool $soloDiferencias): array
{
    $codes = array_values(array_unique(array_merge(array_keys($excelRows), array_keys($sistemaRows))));
    sort($codes);

    $rows = [];
    foreach ($codes as $code) {
        $inA = isset($excelRows[$code]);
        $inB = isset($sistemaRows[$code]);
        $nombre = (string) ($excelRows[$code]['NOMBRE'] ?? $sistemaRows[$code]['NOMBRE'] ?? '');

        foreach (ERI_COMPARATIVO_MONTHS as $month) {
            $valueA = $inA ? (float) ($excelRows[$code]['MESES'][$month] ?? 0.0) : null;
            $valueB = $inB ? (float) ($sistemaRows[$code]['MESES'][$month] ?? 0.0) : null;

            $aComp = $valueA ?? 0.0;
            $bComp = $valueB ?? 0.0;
            $dif = $aComp - $bComp;
            $difAbs = abs($dif);
            $difPct = $bComp == 0.0 ? null : ($dif / $bComp) * 100;

            $isDiff = !$inA || !$inB || $difAbs > 0.0001;
            if ($soloDiferencias && !$isDiff) {
                continue;
            }

            $rows[] = [
                'CODIGO' => $code,
                'NOMBRE' => $nombre,
                'CAMPO' => $month . '_VALOR',
                'A' => $valueA,
                'B' => $valueB,
                'DIF' => $dif,
                'DIF_ABS' => $difAbs,
                'DIF_PCT' => $difPct,
            ];
        }
    }

    return $rows;
}

function eriComparativoBuildResult(array $params, string $filePath): array
{
    $anio = (int) ($params['anio'] ?? date('Y'));
    $tipo = strtoupper(trim((string) ($params['tipo'] ?? 'PRESUPUESTO')));
    $soloDiferencias = ((int) ($params['solo_diferencias'] ?? 0)) === 1;

    $excelRows = eriComparativoReadExcelRows($filePath);
    $sistemaRows = eriComparativoGetSistemaRows($anio, $tipo);
    $rows = eriComparativoBuildRows($excelRows, $sistemaRows, $soloDiferencias);

    return [
        'ok' => true,
        'message' => 'OK',
        'meta' => [
            'tab' => 'ERI',
            'modo' => 'excel_vs_sistema',
            'anio' => $anio,
            'tipo' => $tipo,
            'solo_diferencias' => $soloDiferencias,
            'total_diferencias' => count($rows),
        ],
        'rows' => $rows,
    ];
}
