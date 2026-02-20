<?php

declare(strict_types=1);

namespace App\services;

use App\repositories\PresupuestoIngresosRepository;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
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
            'preview' => array_slice($rows, 0, 10),
        ];
    }

    public function execute(string $fileTmpPath, string $tipo, string $usuario, ?int $anioRequest = null, ?string $originalFileName = null): array
    {
        $sheetName = self::SHEET_NAME;
        $fileName = $originalFileName ?: basename($fileTmpPath);
        $counts = ['total_rows' => 0, 'imported_rows' => 0, 'updated_rows' => 0, 'omitted_rows' => 0];
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
            'preview' => array_slice($rows, 0, 10),
            'json_path' => $jsonPath,
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
        $anioActual = $anioRequest;
        $anioDetectado = null;

        $details = [];
        $rows = [];
        $highestRow = (int) $sheet->getHighestRow();
        $totalRows = 0;

        for ($rowNum = 1; $rowNum <= $highestRow; $rowNum++) {
            $periodoCell = $this->normalizeText((string) $sheet->getCell('A' . $rowNum)->getFormattedValue());
            $anioEnFila = $this->parsePeriodoYear($periodoCell);
            if ($anioEnFila !== null) {
                $anioActual = $anioEnFila;
                $anioDetectado = $anioEnFila;
            }

            $codigo = $this->normalizeText((string) $sheet->getCell('B' . $rowNum)->getFormattedValue());
            $nombre = $this->normalizeText((string) $sheet->getCell('C' . $rowNum)->getFormattedValue());

            $rowHasData = $this->rowLooksRelevant($sheet, $rowNum);
            if ($rowHasData) {
                $totalRows++;
            }

            if ($codigo === '' || $nombre === '') {
                if ($codigo === '' && $rowHasData) {
                    continue;
                }
                if ($rowHasData) {
                    $details[] = $this->detail($rowNum, $codigo === '' ? 'B' : 'C', 'WARNING', 'MISSING_REQUIRED_FIELD', 'Fila omitida por faltar CODIGO o NOMBRE_CUENTA.');
                }
                continue;
            }

            if ($anioActual === null) {
                $details[] = $this->detail($rowNum, 'A', 'ERROR', 'ANIO_REQUIRED', 'ANIO requerido');
                continue;
            }

            $item = ['anio' => $anioActual, 'codigo' => $codigo, 'nombre_cuenta' => $nombre];
            $sum = 0.0;

            foreach (self::MONTH_MAP as $column => $key) {
                $parsed = $this->readNumericCell($sheet, $column, $rowNum);
                $item[$key] = $parsed['value'];
                $sum += $parsed['value'];

                if ($parsed['detail'] !== null) {
                    $details[] = $parsed['detail'];
                }
            }

            $excelTotal = $this->readNumericCell($sheet, 'P', $rowNum, false);
            $item['total'] = round($sum, 2);
            if ($excelTotal['is_numeric']) {
                $item['total'] = round((float) $excelTotal['value'], 2);
            } else {
                $details[] = $this->detail($rowNum, 'P', 'WARNING', 'TOTAL_RECALCULATED', 'TOTAL vacío o no numérico; se recalcula con suma ENE..DIC.', is_scalar($excelTotal['raw_value']) ? (string) $excelTotal['raw_value'] : null);
            }

            $rows[] = $item;
        }

        if ($anioDetectado === null) {
            throw new \RuntimeException('ANIO requerido');
        }

        $counts = [
            'total_rows' => $totalRows,
            'imported_rows' => 0,
            'updated_rows' => 0,
            'importable_rows' => count($rows),
            'omitted_rows' => max(0, $totalRows - count($rows)),
            'warning_rows' => $this->countBySeverity($details, 'WARNING'),
            'error_rows' => $this->countBySeverity($details, 'ERROR'),
        ];

        return [$sheet, $anioDetectado, $details, $rows, $counts, $fileName];
    }

    private function readNumericCell(Worksheet $sheet, string $column, int $rowNum, bool $registerWarnings = true): array
    {
        $cell = $sheet->getCell($column . $rowNum);
        $rawValue = $cell->getValue();
        $isFormula = is_string($rawValue) && str_starts_with(trim($rawValue), '=');
        $hadCalculatedValue = false;

        try {
            $calculated = $cell->getCalculatedValue();
            $hadCalculatedValue = true;
            $parsed = $this->parseNumeric($calculated);
            if ($parsed !== null) {
                return ['value' => $parsed, 'detail' => null, 'formula_calculated' => $isFormula, 'is_numeric' => true, 'raw_value' => $rawValue];
            }
        } catch (\Throwable) {
        }

        try {
            $old = $cell->getOldCalculatedValue();
            $parsed = $this->parseNumeric($old);
            if ($parsed !== null) {
                return ['value' => $parsed, 'detail' => null, 'formula_calculated' => $isFormula, 'is_numeric' => true, 'raw_value' => $rawValue];
            }
        } catch (\Throwable) {
        }

        $plain = $this->parseNumeric($cell->getFormattedValue());
        if ($plain !== null) {
            return ['value' => $plain, 'detail' => null, 'formula_calculated' => false, 'is_numeric' => true, 'raw_value' => $rawValue];
        }

        $detail = null;
        if ($registerWarnings) {
            $detail = $this->detail(
                $rowNum,
                $column,
                'WARNING',
                $isFormula && !$hadCalculatedValue ? 'NO_CALCULATED_VALUES' : ($isFormula ? 'FORMULA_NOT_CALCULABLE' : 'NON_NUMERIC_VALUE'),
                $isFormula ? 'No hay valor calculado disponible; se usará 0.' : 'Valor no numérico; se usará 0.',
                is_scalar($rawValue) ? (string) $rawValue : null
            );
        }

        return ['value' => 0.0, 'detail' => $detail, 'formula_calculated' => false, 'is_numeric' => false, 'raw_value' => $rawValue];
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

    private function parsePeriodoYear(string $periodoCell): ?int
    {
        if (preg_match('/^\d{4}$/', $periodoCell) !== 1) {
            return null;
        }

        return (int) $periodoCell;
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
