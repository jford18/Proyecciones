<?php

declare(strict_types=1);

namespace App\services;

use App\repositories\PresupuestoIngresosRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelIngresosImportService
{
    private const SHEET_NAME = '1.- Ingresos';
    private const MONTH_MAP = [
        'D' => 'ene',
        'E' => 'feb',
        'F' => 'mar',
        'G' => 'abr',
        'H' => 'may',
        'I' => 'jun',
        'J' => 'jul',
        'K' => 'ago',
        'L' => 'sep',
        'M' => 'oct',
        'N' => 'nov',
        'O' => 'dic',
    ];

    public function __construct(private PresupuestoIngresosRepository $repository)
    {
    }

    public function validate(string $fileTmpPath, string $tipo, ?string $originalFileName = null): array
    {
        [$sheet, $anio, $details, $rows, $counts, $fileName] = $this->parseIngresos($fileTmpPath, $originalFileName);

        return [
            'ok' => true,
            'tab' => 'ingresos',
            'tipo' => $tipo,
            'sheet_name' => $sheet->getTitle(),
            'file_name' => $fileName,
            'anio' => $anio,
            'counts' => $counts,
            'details' => $details,
            'preview' => array_slice($rows, 0, 10),
        ];
    }

    public function execute(string $fileTmpPath, string $tipo, string $usuario, ?string $originalFileName = null): array
    {
        [$sheet, $anio, $details, $rows, $counts, $fileName] = $this->parseIngresos($fileTmpPath, $originalFileName);

        $upsert = $this->repository->upsertIngresosRows($tipo, $anio, $sheet->getTitle(), $fileName, $usuario, $rows);
        $insertedCount = (int) ($upsert['inserted_count'] ?? 0);
        $updatedCount = (int) ($upsert['updated_count'] ?? 0);

        $jsonPath = $this->storeJsonEvidence($rows, $tipo, $anio, $sheet->getTitle(), $fileName, $usuario, $counts, $details, $insertedCount, $updatedCount);

        $warningCount = $this->countBySeverity($details, 'WARNING');
        $errorCount = $this->countBySeverity($details, 'ERROR');

        $counts['imported_rows'] = $insertedCount + $updatedCount;
        $counts['inserted_rows'] = $insertedCount;
        $counts['updated_rows'] = $updatedCount;

        $response = [
            'ok' => true,
            'tab' => 'ingresos',
            'tipo' => $tipo,
            'target_table' => 'PRESUPUESTO_INGRESOS',
            'file_name' => $fileName,
            'sheet_name' => $sheet->getTitle(),
            'anio' => $anio,
            'inserted_count' => $insertedCount,
            'updated_count' => $updatedCount,
            'skipped_count' => 0,
            'warning_count' => $warningCount,
            'error_count' => $errorCount,
            'counts' => $counts,
            'details' => $details,
            'preview' => array_slice($rows, 0, 10),
            'json_path' => $jsonPath,
        ];

        $this->repository->insertImportLog($response + ['usuario' => $usuario]);

        return $response;
    }

    private function parseIngresos(string $fileTmpPath, ?string $originalFileName = null): array
    {
        $spreadsheet = IOFactory::load($fileTmpPath);
        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);
        if (!$sheet instanceof Worksheet) {
            throw new \RuntimeException('No existe la hoja requerida: 1.- Ingresos');
        }

        $anio = $this->detectGlobalYear($sheet);
        if ($anio === null) {
            throw new \RuntimeException('No se pudo detectar ANIO global en la plantilla de ingresos.');
        }

        $details = [];
        $rows = [];
        $highestRow = (int) $sheet->getHighestRow();
        $totalRows = 0;

        for ($rowNum = 1; $rowNum <= $highestRow; $rowNum++) {
            $codigo = $this->normalizeText((string) $sheet->getCell('B' . $rowNum)->getFormattedValue());
            $nombre = $this->normalizeText((string) $sheet->getCell('C' . $rowNum)->getFormattedValue());

            if ($codigo === '' || $nombre === '') {
                if ($this->rowLooksRelevant($sheet, $rowNum)) {
                    $details[] = $this->detail($rowNum, $codigo === '' ? 'B' : 'C', 'WARNING', 'MISSING_REQUIRED_FIELD', 'Fila omitida por faltar CODIGO o NOMBRE_CUENTA.');
                }
                continue;
            }

            $totalRows++;
            $item = ['codigo' => $codigo, 'nombre' => $nombre];
            $sum = 0.0;
            $hadFormulaCalculated = false;

            foreach (self::MONTH_MAP as $column => $key) {
                $parsed = $this->readNumericCell($sheet, $column, $rowNum);
                $item[$key] = $parsed['value'];
                $sum += $parsed['value'];

                if ($parsed['detail'] !== null) {
                    $details[] = $parsed['detail'];
                }
                if ($parsed['formula_calculated']) {
                    $hadFormulaCalculated = true;
                }
            }

            $excelTotal = $this->readNumericCell($sheet, 'P', $rowNum, false);
            $item['total'] = round($sum, 2);
            if ($excelTotal['is_numeric']) {
                $item['total'] = round((float) $excelTotal['value'], 2);
            }

            if ($hadFormulaCalculated) {
                $details[] = $this->detail($rowNum, 'D:O', 'WARNING', 'FORMULA_CALCULATED', 'Fórmula calculada y usada para importar.');
            }

            $rows[] = $item;
        }

        $counts = [
            'total_rows' => $totalRows,
            'imported_rows' => 0,
            'importable_rows' => count($rows),
            'imported_formula_rows' => count(array_filter($details, static fn (array $d): bool => ($d['code'] ?? '') === 'FORMULA_CALCULATED')),
            'skipped_formula_rows' => 0,
            'warning_rows' => $this->countBySeverity($details, 'WARNING'),
            'error_rows' => $this->countBySeverity($details, 'ERROR'),
        ];

        return [$sheet, $anio, $details, $rows, $counts, $originalFileName ?: basename($fileTmpPath)];
    }

    private function detectGlobalYear(Worksheet $sheet): ?int
    {
        for ($rowNum = 1; $rowNum <= 30; $rowNum++) {
            $value = $this->toYearCandidate($this->safeCalculatedValue($sheet, 'A', $rowNum));
            if ($value !== null) {
                return $value;
            }
        }

        for ($rowNum = 1; $rowNum <= 60; $rowNum++) {
            for ($col = 'A'; $col <= 'H'; $col++) {
                $candidate = $this->toYearCandidate($sheet->getCell($col . $rowNum)->getFormattedValue());
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function readNumericCell(Worksheet $sheet, string $column, int $rowNum, bool $registerWarnings = true): array
    {
        $cell = $sheet->getCell($column . $rowNum);
        $rawValue = $cell->getValue();
        $isFormula = is_string($rawValue) && str_starts_with(trim($rawValue), '=');
        $formulaCalculated = false;

        try {
            $calculated = $cell->getCalculatedValue();
            $parsed = $this->parseNumeric($calculated);
            if ($parsed !== null) {
                return ['value' => $parsed, 'detail' => null, 'formula_calculated' => $isFormula, 'is_numeric' => true];
            }
            if ($isFormula) {
                $formulaCalculated = true;
            }
        } catch (\Throwable) {
        }

        try {
            $old = $cell->getOldCalculatedValue();
            $parsed = $this->parseNumeric($old);
            if ($parsed !== null) {
                return ['value' => $parsed, 'detail' => null, 'formula_calculated' => $isFormula, 'is_numeric' => true];
            }
        } catch (\Throwable) {
        }

        $plain = $this->parseNumeric($cell->getFormattedValue());
        if ($plain !== null) {
            return ['value' => $plain, 'detail' => null, 'formula_calculated' => false, 'is_numeric' => true];
        }

        $detail = null;
        if ($registerWarnings) {
            $detail = $this->detail(
                $rowNum,
                $column,
                'WARNING',
                $isFormula || $formulaCalculated ? 'FORMULA_NOT_CALCULABLE' : 'NON_NUMERIC_VALUE',
                $isFormula || $formulaCalculated ? 'No se pudo calcular; se usará 0.' : 'Valor no numérico; se usará 0.',
                is_scalar($rawValue) ? (string) $rawValue : null
            );
        }

        return ['value' => 0.0, 'detail' => $detail, 'formula_calculated' => false, 'is_numeric' => false];
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

    private function toYearCandidate(mixed $value): ?int
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/\b(19\d{2}|20\d{2}|30\d{2})\b/', $raw, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function safeCalculatedValue(Worksheet $sheet, string $column, int $rowNum): mixed
    {
        $cell = $sheet->getCell($column . $rowNum);
        try {
            return $cell->getCalculatedValue();
        } catch (\Throwable) {
            try {
                return $cell->getOldCalculatedValue();
            } catch (\Throwable) {
                return $cell->getFormattedValue();
            }
        }
    }

    private function rowLooksRelevant(Worksheet $sheet, int $rowNum): bool
    {
        foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O'] as $column) {
            $value = trim((string) $sheet->getCell($column . $rowNum)->getFormattedValue());
            if ($value !== '') {
                return true;
            }
        }

        return false;
    }

    private function storeJsonEvidence(array $rows, string $tipo, int $anio, string $sheetName, string $fileName, string $usuario, array $counts, array $details, int $insertedCount, int $updatedCount): string
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
