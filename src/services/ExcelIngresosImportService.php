<?php

declare(strict_types=1);

namespace App\services;

use App\repositories\PresupuestoIngresosRepository;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelIngresosImportService
{
    private const SHEET_NAME = '1.- Ingresos';
    private const MONTH_KEYS = [
        'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic',
    ];
    private const HEADER_ALIASES = [
        'periodo' => ['PERIODO'],
        'codigo' => ['CODIGO'],
        'nombre_cuenta' => ['NOMBRE CUENTA', 'NOMBRE_CUENTA'],
        'ene' => ['ENE', 'ENERO'],
        'feb' => ['FEB', 'FEBRERO'],
        'mar' => ['MAR', 'MARZO'],
        'abr' => ['ABR', 'ABRIL'],
        'may' => ['MAY', 'MAYO'],
        'jun' => ['JUN', 'JUNIO'],
        'jul' => ['JUL', 'JULIO'],
        'ago' => ['AGO', 'AGOSTO'],
        'sep' => ['SEP', 'SEPTIEMBRE'],
        'oct' => ['OCT', 'OCTUBRE'],
        'nov' => ['NOV', 'NOVIEMBRE'],
        'dic' => ['DIC', 'DICIEMBRE'],
        'total' => ['TOTAL'],
    ];

    public function __construct(private PresupuestoIngresosRepository $repository)
    {
    }

    public function validate(string $fileTmpPath, string $tipo, ?int $anioRequest = null, ?string $originalFileName = null): array
    {
        [$sheet, $anio, $details, $rows, $counts, $fileName] = $this->parseIngresos($fileTmpPath, $anioRequest, $originalFileName);

        return [
            'ok' => true,
            'tab' => 'ingresos',
            'tipo' => $tipo,
            'sheet_name' => $sheet->getTitle(),
            'file_name' => $fileName,
            'anio' => $anio,
            'counts' => $counts,
            'details' => $details,
            'preview' => array_slice($rows, 0, 50),
        ];
    }

    public function execute(string $fileTmpPath, string $tipo, string $usuario, ?int $anioRequest = null, ?string $originalFileName = null): array
    {
        $sheetName = self::SHEET_NAME;
        $fileName = $originalFileName ?: basename($fileTmpPath);
        $counts = ['total_rows' => 0, 'imported_rows' => 0, 'updated_rows' => 0, 'omitted_rows' => 0, 'warning_rows' => 0, 'error_rows' => 0, 'skipped_formula_rows' => 0, 'imported_formula_rows' => 0];
        $details = [];
        $rows = [];
        $jsonPath = null;
        $insertedCount = 0;
        $updatedCount = 0;
        $errorCount = 0;

        try {
            [$sheet, $anio, $details, $rows, $counts, $fileName] = $this->parseIngresos($fileTmpPath, $anioRequest, $originalFileName);
            $sheetName = $sheet->getTitle();

            $upsert = $this->repository->upsertIngresosRows($tipo, $sheetName, $fileName, $usuario, $rows);
            $insertedCount = (int) ($upsert['inserted_count'] ?? 0);
            $updatedCount = (int) ($upsert['updated_count'] ?? 0);

            $counts['imported_rows'] = $insertedCount;
            $counts['updated_rows'] = $updatedCount;
            $counts['importable_rows'] = count($rows);
            $counts['omitted_rows'] = max(0, (int) ($counts['total_rows'] ?? 0) - count($rows));

            $jsonPath = $this->storeJsonEvidence($rows, $tipo, $anio, $sheetName, $fileName, $usuario, $counts, $details, $insertedCount, $updatedCount);
        } catch (\Throwable $e) {
            $errorCount = 1;
            $details[] = $this->detail(0, '-', 'ERROR', 'EXECUTE_ERROR', $e->getMessage());
            $this->repository->insertImportLog([
                'tab' => 'ingresos',
                'tipo' => $tipo,
                'archivo_nombre' => $fileName,
                'hoja_nombre' => $sheetName,
                'sheet_name' => $sheetName,
                'file_name' => $fileName,
                'counts' => $counts,
                'inserted_count' => 0,
                'updated_count' => 0,
                'warning_count' => $this->countBySeverity($details, 'WARNING'),
                'error_count' => $errorCount,
                'json_path' => $jsonPath,
                'usuario' => $usuario,
            ]);
            throw $e;
        }

        $warningCount = $this->countBySeverity($details, 'WARNING');

        $response = [
            'ok' => true,
            'tab' => 'ingresos',
            'tipo' => $tipo,
            'target_table' => 'PRESUPUESTO_INGRESOS',
            'file_name' => $fileName,
            'sheet_name' => $sheetName,
            'inserted_count' => $insertedCount,
            'updated_count' => $updatedCount,
            'skipped_count' => (int) ($counts['omitted_rows'] ?? 0),
            'warning_count' => $warningCount,
            'error_count' => $errorCount,
            'counts' => $counts,
            'details' => $details,
            'preview' => array_slice(array_map(static fn (array $row): array => [
                'periodo' => $row['anio'] ?? null,
                'codigo' => $row['codigo'] ?? null,
                'nombre_cuenta' => $row['nombre_cuenta'] ?? null,
                'total_recalculado' => $row['total'] ?? 0,
            ], $rows), 0, 50),
            'json_path' => $jsonPath,
            'user' => $usuario,
            'timestamp' => date('c'),
        ];

        $this->repository->insertImportLog($response + ['usuario' => $usuario]);

        return $response;
    }

    private function parseIngresos(string $fileTmpPath, ?int $anioRequest = null, ?string $originalFileName = null): array
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($fileTmpPath);
        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);
        if (!$sheet instanceof Worksheet) {
            $sheet = $spreadsheet->getSheetCount() > 0 ? $spreadsheet->getSheet(0) : null;
        }
        if (!$sheet instanceof Worksheet) {
            throw new \RuntimeException('No existe la hoja requerida: 1.- Ingresos');
        }

        $fileName = $originalFileName ?: basename($fileTmpPath);
        $lastYear = $anioRequest;
        $anioDetectado = $anioRequest;

        $details = [];
        $rows = [];
        $skippedFormulaRows = 0;
        $importedFormulaRows = 0;
        $highestRow = (int) $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $headerInfo = $this->findHeaderRowAndMap($sheet, $highestRow, $highestColumn);
        if ($headerInfo === null) {
            throw new \RuntimeException('No se encontró encabezado con PERIODO y CODIGO');
        }

        $headerRow = $headerInfo['row'];
        $columnMap = $headerInfo['map'];
        $totalRows = 0;

        for ($rowNum = $headerRow + 1; $rowNum <= $highestRow; $rowNum++) {
            $totalRows++;

            $isEmptyRow = $this->isCompletelyEmptyRow($sheet, $rowNum, $columnMap);
            if ($isEmptyRow) {
                $details[] = $this->detail($rowNum, '-', 'WARNING', 'EMPTY_ROW', 'Fila completamente vacía; se omite.');
                continue;
            }

            $periodoColumn = $columnMap['periodo'];
            $periodoCell = $sheet->getCell($periodoColumn . $rowNum);
            $anioEnFila = $this->parsePeriodoYear($periodoCell->getValue());
            if ($anioEnFila !== null) {
                // Propagación de ANIO: si PERIODO viene vacío en filas siguientes se reutiliza el último año válido.
                $lastYear = $anioEnFila;
                $anioDetectado = $anioEnFila;
            }

            $codigo = $this->normalizeCodigo($sheet->getCell($columnMap['codigo'] . $rowNum)->getFormattedValue());
            $nombre = $this->normalizeText((string) $sheet->getCell($columnMap['nombre_cuenta'] . $rowNum)->getFormattedValue());

            if ($codigo === '') {
                $details[] = $this->detail($rowNum, $columnMap['codigo'], 'WARNING', 'EMPTY_CODIGO', 'Fila omitida por CODIGO vacío.');
                continue;
            }

            if ($lastYear === null) {
                $details[] = $this->detail($rowNum, $periodoColumn, 'WARNING', 'ANIO_REQUIRED', 'ANIO requerido');
                continue;
            }

            $item = ['anio' => $lastYear, 'codigo' => $codigo, 'nombre_cuenta' => $nombre];
            $sum = 0.0;
            $rowHadFormula = false;
            $rowSkippedFormula = false;

            foreach (self::MONTH_KEYS as $key) {
                $column = $columnMap[$key] ?? null;
                if ($column === null) {
                    $item[$key] = 0.0;
                    continue;
                }
                $parsed = $this->readNumeric($sheet->getCell($column . $rowNum), $rowNum, $column);
                $item[$key] = $parsed['value'];
                $sum += $parsed['value'];
                $rowHadFormula = $rowHadFormula || $parsed['is_formula'];
                $rowSkippedFormula = $rowSkippedFormula || $parsed['formula_fallback_zero'];

                if ($parsed['detail'] !== null) {
                    $details[] = $parsed['detail'];
                }
            }

            if ($rowHadFormula) {
                if ($rowSkippedFormula) {
                    $skippedFormulaRows++;
                } else {
                    $importedFormulaRows++;
                }
            }

            // TOTAL siempre se recalcula en backend para evitar discrepancias por formato/fórmulas del Excel.
            $item['total'] = round($sum, 2);
            if (isset($columnMap['total'])) {
                $excelTotal = $this->readNumeric($sheet->getCell($columnMap['total'] . $rowNum), $rowNum, $columnMap['total'], false);
                if ($excelTotal['is_numeric'] && abs($excelTotal['value'] - $item['total']) > 0.01) {
                    $details[] = $this->detail($rowNum, $columnMap['total'], 'WARNING', 'TOTAL_MISMATCH', 'TOTAL Excel difiere del TOTAL recalculado.', (string) $excelTotal['raw_value']);
                }
            }

            $rows[] = $item;
        }

        $counts = [
            'total_rows' => $totalRows,
            'imported_rows' => 0,
            'updated_rows' => 0,
            'importable_rows' => count($rows),
            'omitted_rows' => max(0, $totalRows - count($rows)),
            'warning_rows' => $this->countBySeverity($details, 'WARNING'),
            'error_rows' => $this->countBySeverity($details, 'ERROR'),
            'skipped_formula_rows' => $skippedFormulaRows,
            'imported_formula_rows' => $importedFormulaRows,
        ];

        return [$sheet, $anioDetectado, $details, $rows, $counts, $fileName];
    }

    private function readNumeric(Cell $cell, int $rowNum, string $column, bool $registerWarnings = true): array
    {
        // Lectura robusta: intenta valor calculado de fórmula; si no existe, WARNING y 0.
        $rawValue = $cell->getValue();
        $isFormula = is_string($rawValue) && str_starts_with(trim($rawValue), '=');
        $formulaFallbackZero = false;

        if ($isFormula) {
            try {
                $calculated = $cell->getCalculatedValue();
                $parsed = $this->parseNumeric($calculated);
                if ($parsed !== null) {
                    return ['value' => $parsed, 'detail' => null, 'is_formula' => true, 'formula_fallback_zero' => false, 'is_numeric' => true, 'raw_value' => $rawValue];
                }
            } catch (\Throwable) {
            }

            $formulaFallbackZero = true;
            $detail = $registerWarnings
                ? $this->detail($rowNum, $column, 'WARNING', 'FORMULA_NO_VALUE', 'Fórmula sin valor calculado; se usará 0.', is_scalar($rawValue) ? (string) $rawValue : null)
                : null;

            return ['value' => 0.0, 'detail' => $detail, 'is_formula' => true, 'formula_fallback_zero' => true, 'is_numeric' => false, 'raw_value' => $rawValue];
        }

        $parsed = $this->parseNumeric($rawValue);
        if ($parsed !== null) {
            return ['value' => $parsed, 'detail' => null, 'is_formula' => false, 'formula_fallback_zero' => false, 'is_numeric' => true, 'raw_value' => $rawValue];
        }

        $parsedFormatted = $this->parseNumeric($cell->getFormattedValue());
        if ($parsedFormatted !== null) {
            return ['value' => $parsedFormatted, 'detail' => null, 'is_formula' => false, 'formula_fallback_zero' => false, 'is_numeric' => true, 'raw_value' => $rawValue];
        }

        $detail = null;
        if ($registerWarnings) {
            $detail = $this->detail($rowNum, $column, 'WARNING', 'NON_NUMERIC_VALUE', 'Valor no numérico; se usará 0.', is_scalar($rawValue) ? (string) $rawValue : null);
        }

        return ['value' => 0.0, 'detail' => $detail, 'is_formula' => false, 'formula_fallback_zero' => $formulaFallbackZero, 'is_numeric' => false, 'raw_value' => $rawValue];
    }

    private function findHeaderRowAndMap(Worksheet $sheet, int $highestRow, string $highestColumn): ?array
    {
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
        for ($rowNum = 1; $rowNum <= $highestRow; $rowNum++) {
            $map = [];
            for ($columnIndex = 1; $columnIndex <= $highestColumnIndex; $columnIndex++) {
                $column = Coordinate::stringFromColumnIndex($columnIndex);
                $header = $this->normalizeHeader((string) $sheet->getCell($column . $rowNum)->getFormattedValue());
                if ($header === '') {
                    continue;
                }
                foreach (self::HEADER_ALIASES as $key => $aliases) {
                    foreach ($aliases as $alias) {
                        if ($header === $this->normalizeHeader($alias) && !isset($map[$key])) {
                            $map[$key] = $column;
                            break;
                        }
                    }
                }
            }

            if (isset($map['periodo'], $map['codigo'])) {
                return ['row' => $rowNum, 'map' => $map];
            }
        }

        return null;
    }

    private function isCompletelyEmptyRow(Worksheet $sheet, int $rowNum, array $columnMap): bool
    {
        foreach ($columnMap as $column) {
            $value = trim((string) $sheet->getCell($column . $rowNum)->getFormattedValue());
            if ($value !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeCodigo(mixed $value): string
    {
        $text = $this->normalizeText((string) $value);
        if ($text === '') {
            return '';
        }

        if (is_numeric($text)) {
            return (string) (int) round((float) $text);
        }

        return $text;
    }

    private function normalizeHeader(string $value): string
    {
        $text = strtoupper($this->normalizeText($value));
        return str_replace(['.', ':'], '', $text);
    }

    private function parseNumeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        // Parseo de formato ES: miles con punto y decimal con coma.
        $text = str_replace([' ', '$'], '', $text);
        if (preg_match('/^-?\d{1,3}(\.\d{3})+,\d+$/', $text) === 1) {
            $text = str_replace('.', '', $text);
            $text = str_replace(',', '.', $text);
        } elseif (str_contains($text, ',') && !str_contains($text, '.')) {
            $text = str_replace(',', '.', $text);
        } else {
            $text = str_replace(',', '', $text);
        }

        return is_numeric($text) ? (float) $text : null;
    }

    private function parsePeriodoYear(mixed $periodoValue): ?int
    {
        if (is_int($periodoValue) || is_float($periodoValue)) {
            $year = (int) $periodoValue;
            return ($year >= 1900 && $year <= 2500) ? $year : null;
        }

        $periodoCell = $this->normalizeText((string) $periodoValue);
        if (preg_match('/^\d{4}$/', $periodoCell) !== 1) {
            return null;
        }

        return (int) $periodoCell;
    }

    private function storeJsonEvidence(array $rows, string $tipo, ?int $anio, string $sheetName, string $fileName, string $usuario, array $counts, array $details, int $insertedCount, int $updatedCount): string
    {
        $relativePath = 'var/import_store/ingresos.json';
        $absolutePath = dirname(__DIR__, 2) . '/' . $relativePath;
        if (!is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }

        $payload = [
            'tab' => 'ingresos',
            'tipo' => $tipo,
            'anio' => $anio,
            'sheet_name' => $sheetName,
            'file_name' => $fileName,
            'usuario' => $usuario,
            'inserted_count' => $insertedCount,
            'updated_count' => $updatedCount,
            'counts' => $counts,
            'details' => $details,
            'rows' => $rows,
            'saved_at' => date('c'),
        ];

        file_put_contents($absolutePath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $relativePath;
    }

    private function detail(int $rowNum, string $column, string $severity, string $code, string $message, ?string $rawValue = null): array
    {
        return [
            'row_num' => $rowNum,
            'column' => $column,
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'raw_value' => $rawValue,
        ];
    }

    private function countBySeverity(array $details, string $severity): int
    {
        return count(array_filter($details, static fn (array $detail): bool => ($detail['severity'] ?? '') === $severity));
    }

    private function normalizeText(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
