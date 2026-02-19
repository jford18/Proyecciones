<?php

declare(strict_types=1);

namespace App\services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelTemplateImportService
{
    private const SEVERITY_ERROR = 'ERROR';
    private const SEVERITY_WARNING = 'WARNING';
    private const SEVERITY_SKIP = 'SKIP';

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
        try {
            $sheet = $this->loadTemplateSheet($path, $template['sheet_name']);
        } catch (\Throwable $e) {
            $details[] = $this->buildDetail(1, 'A:P', self::SEVERITY_ERROR, 'INVALID_EXCEL', $e->getMessage());

            $summary = [
                'total_rows' => 0,
                'importables' => 0,
                'skipped_formula_rows' => 0,
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
            ];
        }

        $headerValidation = $this->validateHeader($sheet);
        if ($headerValidation !== []) {
            $details = array_merge($details, $headerValidation);
        }

        $parsed = $this->parseRows($sheet);
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
            'warning_rows' => $warningRows,
            'error_rows' => $errorRows,
        ];

        return [
            'sheet_name' => $sheet->getTitle(),
            'template_id' => $template['id'],
            'preview' => array_slice($parsed['importable_rows'], 0, 20),
            'summary' => $summary,
            'counts' => $summary + ['importable_rows' => $summary['importables']],
            'details' => $details,
            'errors' => $details,
        ];
    }

    public function execute(string $path, array $template, string $user): array
    {
        $validation = $this->validate($path, $template);
        if (($validation['counts']['error_rows'] ?? 0) > 0) {
            throw new \RuntimeException('La validación contiene errores estructurales. Corrige el archivo antes de importar.');
        }
        $rows = $this->parseRows($this->loadTemplateSheet($path, $template['sheet_name']))['importable_rows'];

        $storePath = dirname(__DIR__, 2) . '/var/import_store/' . $template['id'] . '.json';
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
        file_put_contents($storePath, json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return [
            'sheet_name' => $template['sheet_name'],
            'template_id' => $template['id'],
            'user' => $user,
            'timestamp' => date('c'),
            'counts' => [
                'total_rows' => $validation['counts']['total_rows'],
                'imported_rows' => $inserted,
                'updated_rows' => $updated,
                'skipped_formula_rows' => $validation['counts']['skipped_formula_rows'],
                'warning_rows' => $validation['counts']['warning_rows'] ?? 0,
                'error_rows' => $validation['counts']['error_rows'],
                'omitted_rows' => $validation['counts']['total_rows'] - ($inserted + $updated),
            ],
            'details' => $validation['details'] ?? [],
            'errors' => $validation['errors'],
            'preview' => $validation['preview'],
        ];
    }

    private function loadTemplateSheet(string $path, string $sheetName): Worksheet
    {
        $spreadsheet = IOFactory::load($path);
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

    private function parseRows(Worksheet $sheet): array
    {
        $highest = $sheet->getHighestDataRow();
        $importable = [];
        $details = [];
        $skippedFormulaRows = 0;
        $totalRows = 0;

        for ($row = 2; $row <= $highest; $row++) {
            $totalRows++;
            $codigo = $this->normalizeCode((string) $sheet->getCell([2, $row])->getFormattedValue());
            $nombreCuenta = trim((string) $sheet->getCell([3, $row])->getFormattedValue());
            $periodo = (int) trim((string) $sheet->getCell([1, $row])->getFormattedValue());

            $hasFormula = false;
            $monthValues = [];
            foreach (array_keys(self::MONTH_COLUMNS) as $col) {
                $raw = $sheet->getCell([$col, $row])->getValue();
                if (is_string($raw) && str_starts_with(trim($raw), '=')) {
                    $hasFormula = true;
                    break;
                }

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

            if ($hasFormula) {
                $skippedFormulaRows++;
                $details[] = $this->buildDetail($row, 'D:O', self::SEVERITY_SKIP, 'FORMULA_ROW', 'Fila omitida por fórmulas en columnas de meses.');
                continue;
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
                $details[] = $this->buildDetail($row, 'D:O', self::SEVERITY_WARNING, 'NO_NUMERIC_MONTHS', 'No hay valores numéricos en meses.');
                continue;
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
