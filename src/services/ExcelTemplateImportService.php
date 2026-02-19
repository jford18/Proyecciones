<?php

declare(strict_types=1);

namespace App\services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelTemplateImportService
{
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
        $sheet = $this->loadTemplateSheet($path, $template['sheet_name']);
        $errors = [];

        $header = $this->readHeader($sheet);
        if ($header !== ImportTemplateCatalog::FIXED_HEADER) {
            $errors[] = [
                'row' => 1,
                'column' => 'A:P',
                'message' => 'Header inválido. Debe coincidir exactamente con la plantilla oficial.',
                'expected' => ImportTemplateCatalog::FIXED_HEADER,
                'actual' => $header,
            ];
        }

        $parsed = $this->parseRows($sheet);

        return [
            'sheet_name' => $sheet->getTitle(),
            'template_id' => $template['id'],
            'preview' => array_slice($parsed['importable_rows'], 0, 20),
            'counts' => [
                'total_rows' => $parsed['total_rows'],
                'importable_rows' => count($parsed['importable_rows']),
                'skipped_formula_rows' => $parsed['skipped_formula_rows'],
                'error_rows' => count($parsed['row_errors']),
            ],
            'errors' => array_merge($errors, $parsed['row_errors']),
        ];
    }

    public function execute(string $path, array $template, string $user): array
    {
        $validation = $this->validate($path, $template);
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
                'error_rows' => $validation['counts']['error_rows'],
                'omitted_rows' => $validation['counts']['total_rows'] - ($inserted + $updated),
            ],
            'errors' => $validation['errors'],
            'preview' => $validation['preview'],
        ];
    }

    private function loadTemplateSheet(string $path, string $sheetName): Worksheet
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (!$sheet instanceof Worksheet) {
            throw new \RuntimeException("El Excel no contiene la hoja requerida: {$sheetName}");
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

    private function parseRows(Worksheet $sheet): array
    {
        $highest = $sheet->getHighestDataRow();
        $importable = [];
        $errors = [];
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
                $monthValues[$col] = $this->toFloat($raw);
            }

            if ($hasFormula) {
                $skippedFormulaRows++;
                continue;
            }

            if ($codigo === '') {
                $errors[] = ['row' => $row, 'column' => 'B', 'message' => 'CODIGO vacío'];
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
                $errors[] = ['row' => $row, 'column' => 'D:O', 'message' => 'No hay valores numéricos en meses'];
                continue;
            }

            if ($periodo < 1900 || $periodo > 3000) {
                $errors[] = ['row' => $row, 'column' => 'A', 'message' => 'PERIODO inválido (debe ser YYYY)'];
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
            'row_errors' => $errors,
            'skipped_formula_rows' => $skippedFormulaRows,
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
