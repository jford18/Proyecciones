<?php

declare(strict_types=1);

namespace App\services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

class ExcelTemplateImportService
{
    private const SEVERITY_ERROR = 'ERROR';
    private const SEVERITY_WARNING = 'WARNING';
    private const SEVERITY_SKIP = 'SKIP';

    private const MAX_PARSE_ROWS = 5000;

    private const MONTH_COLUMNS = [
        4 => 'Enero',
        5 => 'Febrero',
        6 => 'Marzo',
        7 => 'Abril',
        8 => 'Mayo',
        9 => 'Junio',
        10 => 'Julio',
        11 => 'Agosto',
        12 => 'Septiembre',
        13 => 'Octubre',
        14 => 'Noviembre',
        15 => 'Diciembre',
    ];

    public function validate(string $path, array $template): array
    {
        $details = [];
        $isIngresos = (($template['id'] ?? '') === 'ingresos');
        try {
            [$sheetFormulas, $sheetValues] = $this->loadTemplateSheets($path, $template['sheet_name'], $isIngresos);
        } catch (\Throwable $e) {
            $details[] = $this->buildDetail(1, 'A:P', self::SEVERITY_ERROR, 'INVALID_EXCEL', $e->getMessage());

            $summary = [
                'total_rows' => 0,
                'importables' => 0,
                'skipped_formula_rows' => 0,
                'imported_formula_rows' => 0,
                'warning_rows' => 0,
                'error_rows' => 1,
            ];

            return [
                'sheet_name' => $template['sheet_name'],
                'template_id' => $template['id'],
                'preview' => [],
                'summary' => $summary,
                'counts' => $summary + ['importable_rows' => 0],
                'details' => $details,
                'errors' => $details,
                'processed_rows' => 0,
                'highest_row' => 0,
                'max_rows' => self::MAX_PARSE_ROWS,
                'rows_limit_exceeded' => false,
            ];
        }

        $headerValidation = $this->validateHeader($sheetFormulas);
        if ($headerValidation !== []) {
            $details = array_merge($details, $headerValidation);
        }

        $parsed = $this->parseRows($sheetValues, $sheetFormulas, $isIngresos);
        $details = array_merge($details, $parsed['details']);

        $errorRows = 0;
        $warningRows = 0;
        foreach ($details as $detail) {
            if (($detail['severity'] ?? '') === self::SEVERITY_ERROR) {
                $errorRows++;
                continue;
            }
            if (($detail['severity'] ?? '') === self::SEVERITY_WARNING) {
                $warningRows++;
            }
        }

        $summary = [
            'total_rows' => $parsed['total_rows'],
            'importables' => count($parsed['importable_rows']),
            'skipped_formula_rows' => $parsed['skipped_formula_rows'],
            'imported_formula_rows' => $parsed['imported_formula_rows'],
            'warning_rows' => $warningRows,
            'error_rows' => $errorRows,
        ];

        return [
            'sheet_name' => $sheetFormulas->getTitle(),
            'template_id' => $template['id'],
            'preview' => array_slice($parsed['importable_rows'], 0, 20),
            'summary' => $summary,
            'counts' => $summary + ['importable_rows' => $summary['importables']],
            'details' => $details,
            'errors' => $details,
            'processed_rows' => (int) ($parsed['processed_rows'] ?? 0),
            'highest_row' => (int) ($parsed['highest_row'] ?? 0),
            'max_rows' => (int) ($parsed['max_rows'] ?? self::MAX_PARSE_ROWS),
            'rows_limit_exceeded' => (bool) ($parsed['rows_limit_exceeded'] ?? false),
        ];
    }

    public function execute(string $path, array $template, string $user): array
    {
        $validation = $this->validate($path, $template);
        if (($validation['counts']['error_rows'] ?? 0) > 0) {
            throw new \RuntimeException('La validación contiene errores estructurales. Corrige el archivo antes de importar.');
        }
        $isIngresos = (($template['id'] ?? '') === 'ingresos');
        [$sheetFormulas, $sheetValues] = $this->loadTemplateSheets($path, $template['sheet_name'], $isIngresos);
        $rows = $this->parseRows($sheetValues, $sheetFormulas, $isIngresos)['importable_rows'];

        $storePath = dirname(__DIR__, 2) . '/var/import_store/' . $template['id'] . '.json';
        $targetTable = 'var/import_store/' . $template['id'] . '.json';
        error_log('[IMPORT_EXECUTE][' . strtoupper((string) $template['id']) . '] rows_to_save=' . count($rows) . ' target=' . $targetTable);
        $existing = [];
        if (is_file($storePath)) {
            $existing = json_decode((string) file_get_contents($storePath), true) ?: [];
        }

        $index = [];
        foreach ($existing as $i => $row) {
            $index[$row['periodo'] . '|' . $row['codigo']] = $i;
        }

        $inserted = 0;
        $updated = 0;
        foreach ($rows as $row) {
            $key = $row['periodo'] . '|' . $row['codigo'];
            if (isset($index[$key])) {
                $existing[$index[$key]] = $row;
                $updated++;
                continue;
            }
            $index[$key] = count($existing);
            $existing[] = $row;
            $inserted++;
        }

        if (!is_dir(dirname($storePath))) {
            mkdir(dirname($storePath), 0777, true);
        }
        $written = file_put_contents($storePath, json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if ($written === false) {
            throw new \RuntimeException('Error de escritura al guardar la importación.');
        }

        if (count($rows) > 0 && ($inserted + $updated) === 0) {
            throw new \RuntimeException('No se guardaron filas importables. Verifica el mapeo y la escritura de datos.');
        }

        error_log('[IMPORT_EXECUTE][' . strtoupper((string) $template['id']) . '] inserted_count=' . $inserted . ' updated_count=' . $updated);

        return [
            'sheet_name' => $template['sheet_name'],
            'highest_row' => (int) ($validation['highest_row'] ?? 0),
            'max_rows' => (int) ($validation['max_rows'] ?? self::MAX_PARSE_ROWS),
            'processed_rows' => (int) ($validation['processed_rows'] ?? 0),
            'rows_limit_exceeded' => (bool) ($validation['rows_limit_exceeded'] ?? false),
            'template_id' => $template['id'],
            'user' => $user,
            'timestamp' => date('c'),
            'target_table' => $targetTable,
            'counts' => [
                'total_rows' => $validation['counts']['total_rows'],
                'imported_rows' => $inserted,
                'updated_rows' => $updated,
                'skipped_formula_rows' => $validation['counts']['skipped_formula_rows'],
                'imported_formula_rows' => $validation['counts']['imported_formula_rows'] ?? 0,
                'warning_rows' => $validation['counts']['warning_rows'] ?? 0,
                'error_rows' => $validation['counts']['error_rows'],
                'omitted_rows' => $validation['counts']['total_rows'] - ($inserted + $updated),
                'processed_rows' => (int) ($validation['processed_rows'] ?? 0),
                'highest_row' => (int) ($validation['highest_row'] ?? 0),
                'max_rows' => (int) ($validation['max_rows'] ?? self::MAX_PARSE_ROWS),
            ],
            'details' => $validation['details'] ?? [],
            'errors' => $validation['errors'],
            'preview' => $validation['preview'],
        ];
    }

    private function loadTemplateSheets(string $path, string $sheetName, bool $dualMode): array
    {
        $sheetFormulas = $this->loadTemplateSheetByMode($path, $sheetName, false);
        if (!$dualMode) {
            return [$sheetFormulas, $sheetFormulas];
        }

        $sheetValues = $this->loadTemplateSheetByMode($path, $sheetName, true);

        return [$sheetFormulas, $sheetValues];
    }

    private function loadTemplateSheetByMode(string $path, string $sheetName, bool $dataOnly): Worksheet
    {
        $reader = IOFactory::createReader('Xlsx');
        if ($reader instanceof Xlsx) {
            $reader->setReadDataOnly($dataOnly);
        }
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (!$sheet instanceof Worksheet) {
            throw new \RuntimeException("No existe la hoja requerida: {$sheetName}");
        }

        return $sheet;
    }

    private function readHeader(Worksheet $sheet): array
    {
        $header = [];
        for ($col = 1; $col <= 16; $col++) {
            $header[] = trim((string) $sheet->getCell([$col, 1])->getFormattedValue());
        }

        return $header;
    }

    private function validateHeader(Worksheet $sheet): array
    {
        $details = [];
        $header = $this->readHeader($sheet);
        $expected = ImportTemplateCatalog::FIXED_HEADER;

        foreach ($expected as $index => $columnName) {
            if (!isset($header[$index]) || $header[$index] !== $columnName) {
                $details[] = $this->buildDetail(
                    1,
                    $this->columnLetter($index + 1),
                    self::SEVERITY_ERROR,
                    'HEADER_MISSING',
                    "Header inválido en columna {$columnName}.",
                    $header[$index] ?? null
                );
            }
        }

        $seen = [];
        foreach ($header as $index => $name) {
            if ($name === '') {
                $details[] = $this->buildDetail(1, $this->columnLetter($index + 1), self::SEVERITY_ERROR, 'HEADER_CORRUPT', 'Header vacío o corrupto.');
                continue;
            }
            $normalized = mb_strtolower($name);
            if (isset($seen[$normalized])) {
                $details[] = $this->buildDetail(
                    1,
                    $this->columnLetter($index + 1),
                    self::SEVERITY_ERROR,
                    'HEADER_DUPLICATE',
                    "Columna duplicada en header: {$name}."
                );
            }
            $seen[$normalized] = true;
        }

        return $details;
    }

    private function parseRows(Worksheet $valueSheet, ?Worksheet $formulaSheet = null, bool $useFormulaWarnings = false): array
    {
        $highestRow = (int) $valueSheet->getHighestRow();
        $maxRows = min($highestRow, self::MAX_PARSE_ROWS);
        $importable = [];
        $details = [];
        $skippedFormulaRows = 0;
        $importedFormulaRows = 0;
        $totalRows = 0;
        $formulaSourceSheet = $formulaSheet ?? $valueSheet;

        for ($row = 2; $row <= $maxRows; $row++) {
            $totalRows++;
            $codigo = $this->normalizeCode((string) $valueSheet->getCell([2, $row])->getFormattedValue());
            $nombreCuenta = trim((string) $valueSheet->getCell([3, $row])->getFormattedValue());
            $periodo = (int) trim((string) $valueSheet->getCell([1, $row])->getFormattedValue());

            $hasFormula = false;
            $monthValues = [];
            foreach (array_keys(self::MONTH_COLUMNS) as $col) {
                $rawFormula = $formulaSourceSheet->getCell([$col, $row])->getValue();
                if ($useFormulaWarnings && is_string($rawFormula) && str_starts_with(trim($rawFormula), '=')) {
                    $hasFormula = true;
                }

                $raw = $valueSheet->getCell([$col, $row])->getValue();

                if (!$this->isBlank($raw) && !$this->isNumericValue($raw)) {
                    $details[] = $this->buildDetail(
                        $row,
                        $this->columnLetter($col),
                        self::SEVERITY_WARNING,
                        'NON_NUMERIC_VALUE',
                        'Texto no numérico en mes; se tomará como 0.',
                        is_scalar($raw) ? (string) $raw : null
                    );
                }

                $monthValues[$col] = $this->toFloat($raw);
            }

            $rawTotalFormula = $formulaSourceSheet->getCell([16, $row])->getValue();
            if ($useFormulaWarnings && is_string($rawTotalFormula) && str_starts_with(trim($rawTotalFormula), '=')) {
                $hasFormula = true;
            }

            if ($codigo === '') {
                $details[] = $this->buildDetail($row, 'B', self::SEVERITY_WARNING, 'EMPTY_CODE', 'Fila sin CODIGO (posible encabezado/grupo).');
                continue;
            }

            $hasNumeric = false;
            foreach ($monthValues as $value) {
                if ($value !== 0.0) {
                    $hasNumeric = true;
                    break;
                }
            }
            if (!$hasNumeric) {
                if ($hasFormula && $useFormulaWarnings) {
                    $skippedFormulaRows++;
                    $details[] = $this->buildDetail($row, 'D:O', self::SEVERITY_SKIP, 'NO_CALCULATED_VALUES', 'Fila con fórmulas pero sin valores calculados disponibles; re-guardar el Excel y reintentar.');
                } else {
                    $details[] = $this->buildDetail($row, 'D:O', self::SEVERITY_WARNING, 'NO_NUMERIC_MONTHS', 'No hay valores numéricos en meses.');
                }
                continue;
            }

            if ($hasFormula && $useFormulaWarnings) {
                $importedFormulaRows++;
                $details[] = $this->buildDetail($row, 'D:P', self::SEVERITY_WARNING, 'FORMULA_ROW_IMPORTED', 'Fila contiene fórmulas. Se importará usando valores calculados del Excel.');
            }

            if ($periodo < 1900 || $periodo > 3000) {
                $details[] = $this->buildDetail($row, 'A', self::SEVERITY_WARNING, 'INVALID_PERIOD', 'PERIODO inválido (debe ser YYYY).');
                continue;
            }

            $item = [
                'periodo' => (string) $periodo,
                'codigo' => $codigo,
                'nombre_cuenta' => $nombreCuenta,
            ];
            $sum = 0.0;
            foreach (self::MONTH_COLUMNS as $col => $monthName) {
                $value = $monthValues[$col] ?? 0.0;
                $item[mb_strtolower($monthName)] = $value;
                $sum += $value;
            }
            $item['total'] = $sum;
            $importable[] = $item;
        }

        return [
            'total_rows' => $totalRows,
            'importable_rows' => $importable,
            'details' => $details,
            'skipped_formula_rows' => $skippedFormulaRows,
            'imported_formula_rows' => $importedFormulaRows,
            'processed_rows' => $totalRows,
            'highest_row' => $highestRow,
            'max_rows' => $maxRows,
            'rows_limit_exceeded' => $highestRow > self::MAX_PARSE_ROWS,
        ];
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    private function isNumericValue(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return false;
        }

        $normalized = str_replace([' ', '$'], '', $normalized);
        if (preg_match('/^-?\d{1,3}(\.\d{3})+,\d+$/', $normalized) === 1) {
            return true;
        }
        if (str_contains($normalized, ',') && !str_contains($normalized, '.')) {
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(',', '', $normalized);
        }

        return is_numeric($normalized);
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letter = chr(65 + $remainder) . $letter;
            $index = intdiv($index - 1, 26);
        }

        return $letter;
    }

    private function buildDetail(int $rowNum, string $column, string $severity, string $code, string $message, ?string $rawValue = null): array
    {
        return [
            'row_num' => $rowNum,
            'column' => $column !== '' ? $column : null,
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'raw_value' => $rawValue,
        ];
    }

    private function normalizeCode(string $value): string
    {
        $value = trim($value);
        if (str_ends_with($value, '.0')) {
            return substr($value, 0, -2);
        }

        return $value;
    }

    private function toFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        $clean = trim((string) $value);
        $clean = str_replace([' ', '$'], '', $clean);

        if (preg_match('/^-?\d{1,3}(\.\d{3})+,\d+$/', $clean) === 1) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } elseif (str_contains($clean, ',') && !str_contains($clean, '.')) {
            $clean = str_replace(',', '.', $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }

        return is_numeric($clean) ? (float) $clean : 0.0;
    }
}
