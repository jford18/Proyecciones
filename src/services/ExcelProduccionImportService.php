<?php

declare(strict_types=1);

namespace App\services;

use App\repositories\PresupuestoIngresosRepository;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelProduccionImportService
{
    private const SHEET_NAME = '7.- Produccion';
    private const GRID_HEADERS = ['PERIODO', 'CODIGO', 'NOMBRE_CUENTA', 'ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC', 'TOTAL_RECALCULADO'];
    private const MONTH_KEYS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    private const HEADER_ALIASES = [
        'periodo' => ['PERIODO'],
        'codigo' => ['CODIGO'],
        'nombre_cuenta' => ['NOMBRE DE LA CUENTA', 'NOMBRE CUENTA', 'NOMBRE_CUENTA'],
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
        'total_recalculado' => ['TOTAL', 'TOTAL RECALCULADO', 'TOTAL_RECALCULADO'],
    ];

    public function __construct(private PresupuestoIngresosRepository $repository, private ?NumberParser $numberParser = null)
    {
        $this->numberParser ??= new NumberParser();
    }

    public function validate(string $fileTmpPath, string $tipo, ?int $anioRequest = null, ?string $originalFileName = null): array
    {
        $parsed = $this->parseProduccion($fileTmpPath, $anioRequest, $originalFileName);

        return [
            'ok' => true,
            'tab' => 'produccion',
            'tipo' => $tipo,
            'target_table' => 'PRESUPUESTO_PRODUCCION',
            'file_name' => $parsed['file_name'],
            'sheet_name' => $parsed['sheet_name'],
            'anio' => $parsed['anio'],
            'inserted_count' => 0,
            'updated_count' => 0,
            'skipped_count' => (int) ($parsed['counts']['omitted_rows'] ?? 0),
            'warning_count' => (int) ($parsed['counts']['warning_rows'] ?? 0),
            'error_count' => 0,
            'counts' => $parsed['counts'],
            'details' => $parsed['details'],
            'preview' => array_slice($parsed['rows'], 0, 50),
            'json_path' => null,
        ];
    }

    public function previewGrid(string $fileTmpPath, string $tipo, ?int $anioRequest = null, ?string $originalFileName = null): array
    {
        $parsed = $this->parseProduccion($fileTmpPath, $anioRequest, $originalFileName);

        return [
            'ok' => true,
            'tab' => 'produccion',
            'tipo' => $tipo,
            'sheet_name' => $parsed['sheet_name'],
            'file_name' => $parsed['file_name'],
            'headers' => self::GRID_HEADERS,
            'rows' => array_map(static fn (array $row): array => [
                'PERIODO' => $row['periodo'],
                'CODIGO' => $row['codigo'],
                'NOMBRE_CUENTA' => $row['nombre_cuenta'],
                'ENE' => $row['ene'],
                'FEB' => $row['feb'],
                'MAR' => $row['mar'],
                'ABR' => $row['abr'],
                'MAY' => $row['may'],
                'JUN' => $row['jun'],
                'JUL' => $row['jul'],
                'AGO' => $row['ago'],
                'SEP' => $row['sep'],
                'OCT' => $row['oct'],
                'NOV' => $row['nov'],
                'DIC' => $row['dic'],
                'TOTAL_RECALCULADO' => $row['total_recalculado'],
            ], $parsed['rows']),
            'counts' => $parsed['counts'],
            'details' => $parsed['details'],
        ];
    }

    public function execute(string $fileTmpPath, string $tipo, string $usuario, ?int $anioRequest = null, ?string $originalFileName = null): array
    {
        $sheetName = self::SHEET_NAME;
        $fileName = $originalFileName ?: basename($fileTmpPath);
        $counts = ['total_rows' => 0, 'imported_rows' => 0, 'updated_rows' => 0, 'omitted_rows' => 0, 'warning_rows' => 0, 'error_rows' => 0];
        $details = [];
        $rows = [];
        $jsonPath = null;

        try {
            $parsed = $this->parseProduccion($fileTmpPath, $anioRequest, $originalFileName);
            $rows = $parsed['rows'];
            $details = $parsed['details'];
            $counts = $parsed['counts'];
            $sheetName = $parsed['sheet_name'];
            $fileName = $parsed['file_name'];

            $upsert = $this->repository->upsertProduccionRows($tipo, $sheetName, $fileName, $usuario, $rows);
            $counts['imported_rows'] = (int) ($upsert['inserted_count'] ?? 0);
            $counts['updated_rows'] = (int) ($upsert['updated_count'] ?? 0);

            $jsonPath = $this->storeJsonEvidence($rows, $tipo, $parsed['anio'], $sheetName, $fileName, $usuario, $counts, $details, $counts['imported_rows'], $counts['updated_rows']);

            $response = [
                'ok' => true,
                'tab' => 'produccion',
                'tipo' => $tipo,
                'target_table' => 'PRESUPUESTO_PRODUCCION',
                'file_name' => $fileName,
                'sheet_name' => $sheetName,
                'anio' => $parsed['anio'],
                'inserted_count' => $counts['imported_rows'],
                'updated_count' => $counts['updated_rows'],
                'skipped_count' => (int) ($counts['omitted_rows'] ?? 0),
                'warning_count' => $this->countBySeverity($details, 'WARNING'),
                'error_count' => 0,
                'counts' => $counts,
                'details' => $details,
                'preview' => array_slice($rows, 0, 50),
                'json_path' => $jsonPath,
                'user' => $usuario,
                'timestamp' => date('c'),
            ];

            $this->repository->insertImportLog($response + ['usuario' => $usuario]);

            return $response;
        } catch (\Throwable $e) {
            $errorDetails = $details;
            $errorDetails[] = $this->detail(0, '-', 'ERROR', 'EXECUTE_ERROR', $e->getMessage());
            $this->repository->insertImportLog([
                'tab' => 'produccion',
                'tipo' => $tipo,
                'sheet_name' => $sheetName,
                'file_name' => $fileName,
                'counts' => $counts,
                'inserted_count' => 0,
                'updated_count' => 0,
                'warning_count' => $this->countBySeverity($errorDetails, 'WARNING'),
                'error_count' => 1,
                'json_path' => $jsonPath,
                'usuario' => $usuario,
            ]);
            throw $e;
        }
    }

    private function parseProduccion(string $fileTmpPath, ?int $anioRequest = null, ?string $originalFileName = null): array
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($fileTmpPath);
        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);
        if (!$sheet instanceof Worksheet) {
            $sheet = $spreadsheet->getSheetCount() > 0 ? $spreadsheet->getSheet(0) : null;
        }
        if (!$sheet instanceof Worksheet) {
            throw new \RuntimeException('No existe la hoja requerida: 7.- Produccion');
        }

        $highestRow = (int) $sheet->getHighestDataRow();
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $headerInfo = $this->findHeaderRowAndMap($sheet, $highestRow, $highestColumnIndex);
        if ($headerInfo === null) {
            throw new \RuntimeException('No se encontró encabezado con PERIODO y CODIGO');
        }

        $rows = [];
        $details = [];
        $totalRows = 0;
        $lastPeriodo = $anioRequest !== null ? (string) $anioRequest : '';
        $lastAnio = $anioRequest ?? $this->parsePeriodoYear($sheet->getCell('A3')->getFormattedValue());

        for ($rowNum = $headerInfo['row'] + 1; $rowNum <= $highestRow; $rowNum++) {
            $totalRows++;
            $periodoCell = $this->cellText($sheet, $headerInfo['map']['periodo'], $rowNum);
            $codigo = $this->normalizeCodigo($this->cellText($sheet, $headerInfo['map']['codigo'], $rowNum));
            $nombre = $this->normalizeText($this->cellText($sheet, $headerInfo['map']['nombre_cuenta'] ?? 0, $rowNum));

            if ($periodoCell !== '') {
                $parsedAnio = $this->parsePeriodoYear($periodoCell);
                if ($parsedAnio !== null) {
                    $lastPeriodo = $periodoCell;
                    $lastAnio = $parsedAnio;
                }
            } elseif ($lastPeriodo !== '') {
                $periodoCell = $lastPeriodo;
            } elseif ($lastAnio !== null) {
                $periodoCell = (string) $lastAnio;
            }

            if ($codigo === '' && $nombre === '') {
                $details[] = $this->detail($rowNum, '-', 'WARNING', 'EMPTY_ROW', 'Fila vacía; se omite.');
                continue;
            }

            if ($codigo === '') {
                $details[] = $this->detail($rowNum, $this->columnLabel($headerInfo['map']['codigo']), 'WARNING', 'EMPTY_CODIGO', 'CODIGO vacío; fila omitida.');
                continue;
            }

            if ($nombre === '') {
                $details[] = $this->detail($rowNum, $this->columnLabel($headerInfo['map']['nombre_cuenta'] ?? 0), 'WARNING', 'EMPTY_NOMBRE_CUENTA', 'NOMBRE_CUENTA vacío; fila omitida.');
                continue;
            }

            if ($lastAnio === null) {
                $details[] = $this->detail($rowNum, $this->columnLabel($headerInfo['map']['periodo']), 'WARNING', 'ANIO_REQUIRED', 'No se pudo derivar ANIO desde PERIODO.');
                continue;
            }

            $item = [
                'periodo' => $periodoCell !== '' ? (int) $periodoCell : $lastAnio,
                'anio' => $lastAnio,
                'codigo' => $codigo,
                'nombre_cuenta' => $nombre,
            ];

            $sum = 0.0;
            $hasMonthData = false;
            foreach (self::MONTH_KEYS as $monthKey) {
                $columnIndex = $headerInfo['map'][$monthKey] ?? null;
                $parsed = $this->readNumericNullable($sheet, $rowNum, $columnIndex, $details);
                $item[$monthKey] = $parsed;
                $numeric = $parsed ?? 0.0;
                $sum += $numeric;
                if (abs($numeric) > 0.0) {
                    $hasMonthData = true;
                }
            }

            if (!$hasMonthData) {
                $details[] = $this->detail($rowNum, '-', 'WARNING', 'EMPTY_MESES', 'Meses vacíos o en 0; fila omitida.');
                continue;
            }

            $item['total_recalculado'] = round($sum, 2);

            if (isset($headerInfo['map']['total_recalculado'])) {
                $excelTotal = $this->readNumericNullable($sheet, $rowNum, $headerInfo['map']['total_recalculado'], $details);
                if ($excelTotal !== null && abs($excelTotal - $item['total_recalculado']) > 0.01) {
                    $details[] = $this->detail($rowNum, $this->columnLabel($headerInfo['map']['total_recalculado']), 'WARNING', 'TOTAL_MISMATCH', 'TOTAL Excel difiere del TOTAL recalculado.');
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
        ];

        return [
            'sheet_name' => $sheet->getTitle(),
            'file_name' => $originalFileName ?: basename($fileTmpPath),
            'anio' => $lastAnio,
            'rows' => $rows,
            'details' => $details,
            'counts' => $counts,
        ];
    }

    private function findHeaderRowAndMap(Worksheet $sheet, int $highestRow, int $highestColumnIndex): ?array
    {
        for ($rowNum = 1; $rowNum <= $highestRow; $rowNum++) {
            $map = [];
            for ($columnIndex = 1; $columnIndex <= $highestColumnIndex; $columnIndex++) {
                $header = $this->normalizeHeader($this->cellText($sheet, $columnIndex, $rowNum));
                if ($header === '') {
                    continue;
                }

                foreach (self::HEADER_ALIASES as $key => $aliases) {
                    foreach ($aliases as $alias) {
                        if ($header === $this->normalizeHeader($alias) && !isset($map[$key])) {
                            $map[$key] = $columnIndex;
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

    private function readNumericNullable(Worksheet $sheet, int $rowNum, ?int $columnIndex, array &$details): ?float
    {
        if ($columnIndex === null || $columnIndex < 1) {
            return null;
        }

        $ref = Coordinate::stringFromColumnIndex($columnIndex) . $rowNum;
        $cell = $sheet->getCell($ref);
        $rawValue = $cell->getValue();

        if ((is_string($rawValue) && trim($rawValue) === '') || $rawValue === null) {
            return null;
        }

        if (is_string($rawValue) && str_starts_with(trim($rawValue), '=')) {
            try {
                $calculated = $cell->getCalculatedValue();
                $parsedCalc = $this->numberParser->parse($calculated);
                if ($parsedCalc['is_numeric']) {
                    return round((float) $parsedCalc['value'], 2);
                }
            } catch (\Throwable) {
            }
        }

        $parsed = $this->numberParser->parse($rawValue);
        if (!$parsed['is_numeric']) {
            $parsedFormatted = $this->numberParser->parse($cell->getFormattedValue());
            if ($parsedFormatted['is_numeric']) {
                return round((float) $parsedFormatted['value'], 2);
            }
        }

        if (!$parsed['is_numeric']) {
            $details[] = $this->detail(
                $rowNum,
                $this->columnLabel($columnIndex),
                'WARNING',
                'NON_NUMERIC_VALUE',
                'Valor no numérico; se usará NULL.',
                is_scalar($rawValue) ? (string) $rawValue : null
            );
            return null;
        }

        return round((float) $parsed['value'], 2);
    }

    private function parsePeriodoYear(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            $digits = preg_replace('/\D+/', '', (string) (int) $value) ?? '';
        } else {
            $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        }

        if (strlen($digits) < 4) {
            return null;
        }

        $year = (int) substr($digits, 0, 4);
        return ($year >= 1900 && $year <= 2500) ? $year : null;
    }

    private function cellText(Worksheet $sheet, int $columnIndex, int $rowNum): string
    {
        if ($columnIndex < 1 || $rowNum < 1) {
            return '';
        }

        return $this->normalizeText((string) $sheet->getCell(Coordinate::stringFromColumnIndex($columnIndex) . $rowNum)->getFormattedValue());
    }

    private function columnLabel(int $columnIndex): string
    {
        return $columnIndex > 0 ? Coordinate::stringFromColumnIndex($columnIndex) : '-';
    }

    private function normalizeCodigo(mixed $value): string
    {
        $text = $this->normalizeText((string) $value);
        if ($text === '') {
            return '';
        }

        return is_numeric($text) ? (string) (int) round((float) $text) : $text;
    }

    private function normalizeHeader(string $value): string
    {
        $text = strtoupper($this->normalizeText($value));
        return str_replace(['.', ':'], '', $text);
    }

    private function storeJsonEvidence(array $rows, string $tipo, ?int $anio, string $sheetName, string $fileName, string $usuario, array $counts, array $details, int $insertedCount, int $updatedCount): string
    {
        $relativePath = 'var/import_store/produccion.json';
        $absolutePath = dirname(__DIR__, 2) . '/' . $relativePath;
        if (!is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }

        file_put_contents($absolutePath, json_encode([
            'tab' => 'produccion',
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
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

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
