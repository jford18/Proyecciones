<?php

declare(strict_types=1);

namespace App\services;

use App\repositories\PresupuestoIngresosRepository;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelProduccionImportService
{
    private const SHEET_NAME = '7.-Produccion';
    private const GRID_HEADERS = ['PERIODO', 'CODIGO', 'NOMBRE_CUENTA', 'ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'NOV', 'DIC', 'TOTAL_RECALCULADO'];
    private const MONTH_KEYS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    private const HEADER_SCAN_LIMIT = 40;
    private const CONSECUTIVE_EMPTY_LIMIT = 5;
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
        'total' => ['TOTAL'],
    ];

    public function __construct(private PresupuestoIngresosRepository $repository, private ?NumberParser $numberParser = null)
    {
        $this->numberParser ??= new NumberParser();
    }

    public function validate(string $fileTmpPath, string $tipo, ?int $anioRequest = null, ?string $originalFileName = null): array
    {
        $parsed = $this->parseProduccion($fileTmpPath, $anioRequest, $originalFileName);
        $jsonPath = $this->storeJsonEvidence($parsed['rows'], $tipo, $parsed['anio'], $parsed['sheet_name'], $parsed['file_name'], 'validate', $parsed['counts'], $parsed['details']);

        return [
            'ok' => true,
            'tab' => 'produccion',
            'tipo' => $tipo,
            'target_table' => 'PRESUPUESTO_PRODUCCION',
            'sheet_name' => $parsed['sheet_name'],
            'file_name' => $parsed['file_name'],
            'anio' => $parsed['anio'],
            'inserted_count' => 0,
            'updated_count' => 0,
            'skipped_count' => (int) ($parsed['counts']['omitted_rows'] ?? 0),
            'warning_count' => (int) ($parsed['counts']['warning_rows'] ?? 0),
            'error_count' => (int) ($parsed['counts']['error_rows'] ?? 0),
            'counts' => $parsed['counts'],
            'details' => $parsed['details'],
            'preview' => array_slice($parsed['rows'], 0, 30),
            'json_path' => $jsonPath,
            'user' => 'validate',
            'timestamp' => date('c'),
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
            'rows' => array_map(static fn(array $row): array => [
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
        $parsed = $this->parseProduccion($fileTmpPath, $anioRequest, $originalFileName);
        $upsert = $this->repository->upsertProduccionRows($tipo, $parsed['sheet_name'], $parsed['file_name'], $usuario, $parsed['rows']);

        $counts = $parsed['counts'];
        $counts['imported_rows'] = (int) ($upsert['inserted_count'] ?? 0);
        $counts['updated_rows'] = (int) ($upsert['updated_count'] ?? 0);

        $jsonPath = $this->storeJsonEvidence($parsed['rows'], $tipo, $parsed['anio'], $parsed['sheet_name'], $parsed['file_name'], $usuario, $counts, $parsed['details']);

        $response = [
            'ok' => true,
            'tab' => 'produccion',
            'tipo' => $tipo,
            'target_table' => 'PRESUPUESTO_PRODUCCION',
            'file_name' => $parsed['file_name'],
            'sheet_name' => $parsed['sheet_name'],
            'anio' => $parsed['anio'],
            'inserted_count' => $counts['imported_rows'],
            'updated_count' => $counts['updated_rows'],
            'skipped_count' => (int) ($counts['omitted_rows'] ?? 0),
            'warning_count' => (int) ($counts['warning_rows'] ?? 0),
            'error_count' => (int) ($counts['error_rows'] ?? 0),
            'counts' => $counts,
            'details' => $parsed['details'],
            'preview' => array_slice($parsed['rows'], 0, 30),
            'json_path' => $jsonPath,
            'user' => $usuario,
            'timestamp' => date('c'),
        ];

        $this->repository->insertImportLog($response + ['usuario' => $usuario]);

        return $response;
    }

    private function parseProduccion(string $fileTmpPath, ?int $anioRequest = null, ?string $originalFileName = null): array
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($fileTmpPath);

        $sheet = $this->resolveSheet($spreadsheet);
        $highestRow = (int) $sheet->getHighestRow();
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        $headerInfo = $this->findHeaderRowAndMap($sheet, min(self::HEADER_SCAN_LIMIT, $highestRow), $highestColumnIndex);
        if ($headerInfo === null) {
            throw new \RuntimeException('No se encontró encabezado con PERIODO y CODIGO en la hoja de Producción.');
        }

        $rows = [];
        $details = [];
        $lastPeriodo = null;
        $lastAnio = $anioRequest;
        $consecutiveEmpty = 0;
        $totalRows = 0;

        for ($rowNum = $headerInfo['row'] + 1; $rowNum <= $highestRow; $rowNum++) {
            $totalRows++;

            $periodoRaw = $this->cellText($sheet, $headerInfo['map']['periodo'], $rowNum);
            $codigo = $this->normalizeCodigo($this->cellText($sheet, $headerInfo['map']['codigo'], $rowNum));
            $nombre = $this->cellText($sheet, $headerInfo['map']['nombre_cuenta'] ?? 0, $rowNum);

            if ($periodoRaw !== '') {
                $lastPeriodo = $periodoRaw;
                $anio = $this->parsePeriodoYear($periodoRaw);
                if ($anio !== null) {
                    $lastAnio = $anio;
                }
            }
            $periodo = $periodoRaw !== '' ? $periodoRaw : ($lastPeriodo ?? '');

            $isEmptyStructural = ($periodoRaw === '' && $codigo === '' && $nombre === '');
            if ($isEmptyStructural) {
                $consecutiveEmpty++;
                if ($consecutiveEmpty >= self::CONSECUTIVE_EMPTY_LIMIT) {
                    break;
                }
                $details[] = $this->detail($rowNum, '-', 'WARNING', 'EMPTY_ROW', 'Fila vacía; se omite.');
                continue;
            }

            $consecutiveEmpty = 0;

            if ($codigo === '') {
                $details[] = $this->detail($rowNum, $this->columnLabel($headerInfo['map']['codigo']), 'WARNING', 'EMPTY_CODIGO', 'CODIGO vacío; fila omitida.');
                continue;
            }

            if ($nombre === '') {
                $details[] = $this->detail($rowNum, $this->columnLabel($headerInfo['map']['nombre_cuenta'] ?? 0), 'WARNING', 'EMPTY_NOMBRE_CUENTA', 'NOMBRE_CUENTA vacío; fila omitida.');
                continue;
            }

            if ($periodo === '') {
                $details[] = $this->detail($rowNum, $this->columnLabel($headerInfo['map']['periodo']), 'WARNING', 'EMPTY_PERIODO', 'PERIODO vacío y sin valor previo; fila omitida.');
                continue;
            }

            if ($lastAnio === null) {
                $details[] = $this->detail($rowNum, $this->columnLabel($headerInfo['map']['periodo']), 'WARNING', 'ANIO_REQUIRED', 'No se pudo derivar ANIO desde PERIODO; fila omitida.');
                continue;
            }

            $item = [
                'periodo' => $periodo,
                'anio' => $lastAnio,
                'codigo' => $codigo,
                'nombre_cuenta' => $nombre,
            ];

            $sum = 0.0;
            foreach (self::MONTH_KEYS as $monthKey) {
                $columnIndex = $headerInfo['map'][$monthKey] ?? null;
                $value = $this->readNumeric($sheet, $rowNum, $columnIndex, true, $details);
                $item[$monthKey] = $value;
                $sum += $value;
            }

            $totalFromSheet = $this->readNumeric($sheet, $rowNum, $headerInfo['map']['total'] ?? null, false, $details);
            $item['total_recalculado'] = $totalFromSheet !== 0.0 || $this->hasCellContent($sheet, $rowNum, $headerInfo['map']['total'] ?? null)
                ? $totalFromSheet
                : round($sum, 2);

            $rows[] = $item;
        }

        if ($rows === []) {
            $details[] = $this->detail(0, '-', 'WARNING', 'NO_IMPORTABLE_ROWS', 'No se encontraron filas importables para Producción.');
        }

        $counts = [
            'total_rows' => $totalRows,
            'importable_rows' => count($rows),
            'imported_rows' => 0,
            'updated_rows' => 0,
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

    private function resolveSheet($spreadsheet): Worksheet
    {
        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);
        if ($sheet instanceof Worksheet) {
            return $sheet;
        }

        $required = $this->normalizeSheetName(self::SHEET_NAME);
        foreach ($spreadsheet->getSheetNames() as $name) {
            if ($this->normalizeSheetName($name) === $required) {
                $candidate = $spreadsheet->getSheetByName($name);
                if ($candidate instanceof Worksheet) {
                    return $candidate;
                }
            }
        }

        $available = implode(', ', $spreadsheet->getSheetNames());
        throw new \RuntimeException('No existe la hoja requerida "' . self::SHEET_NAME . '". Hojas detectadas: ' . $available);
    }

    private function findHeaderRowAndMap(Worksheet $sheet, int $scanRows, int $highestColumnIndex): ?array
    {
        for ($rowNum = 1; $rowNum <= $scanRows; $rowNum++) {
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

    private function readNumeric(Worksheet $sheet, int $rowNum, ?int $columnIndex, bool $registerWarnings, array &$details): float
    {
        if ($columnIndex === null) {
            return 0.0;
        }

        $ref = $this->cellRef($columnIndex, $rowNum);
        if ($ref === null) {
            return 0.0;
        }

        $cell = $sheet->getCell($ref);
        $rawValue = $cell->getCalculatedValue();
        $parsed = $this->parseFlexibleNumber($rawValue);

        if (!$parsed['is_numeric']) {
            $parsed = $this->parseFlexibleNumber($cell->getFormattedValue());
        }

        if (!$parsed['is_numeric'] && $registerWarnings && $this->normalizeText((string) $cell->getFormattedValue()) !== '') {
            $details[] = $this->detail($rowNum, $this->columnLabel($columnIndex), 'WARNING', 'NON_NUMERIC_VALUE', 'Valor no numérico; se usará 0.', is_scalar($rawValue) ? (string) $rawValue : null);
        }

        return round((float) ($parsed['value'] ?? 0.0), 2);
    }

    private function parseFlexibleNumber(mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['value' => 0.0, 'is_numeric' => true];
        }

        if (is_int($value) || is_float($value)) {
            return ['value' => (float) $value, 'is_numeric' => true];
        }

        $text = trim((string) $value);
        if ($text === '') {
            return ['value' => 0.0, 'is_numeric' => true];
        }

        $text = str_replace(["\xc2\xa0", ' '], '', $text);
        $text = preg_replace('/[^\d,\.\-]/u', '', $text) ?? '';
        if ($text === '' || $text === '-' || $text === '.' || $text === ',') {
            return ['value' => 0.0, 'is_numeric' => false];
        }

        if (str_contains($text, ',') && str_contains($text, '.')) {
            if (strrpos($text, ',') > strrpos($text, '.')) {
                $text = str_replace('.', '', $text);
                $text = str_replace(',', '.', $text);
            } else {
                $text = str_replace(',', '', $text);
            }
        } elseif (str_contains($text, ',')) {
            $text = str_replace('.', '', $text);
            $text = str_replace(',', '.', $text);
        }

        if (!is_numeric($text)) {
            return ['value' => 0.0, 'is_numeric' => false];
        }

        return ['value' => (float) $text, 'is_numeric' => true];
    }

    private function hasCellContent(Worksheet $sheet, int $rowNum, ?int $columnIndex): bool
    {
        if ($columnIndex === null) {
            return false;
        }

        return $this->cellText($sheet, $columnIndex, $rowNum) !== '';
    }

    private function parsePeriodoYear(mixed $value): ?int
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if (strlen($digits) < 4) {
            return null;
        }

        $year = (int) substr($digits, 0, 4);
        return ($year >= 1900 && $year <= 2500) ? $year : null;
    }

    private function normalizeSheetName(string $value): string
    {
        $text = $this->normalizeText($value);
        $text = preg_replace('/\s*-\s*/u', '-', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = mb_strtolower($text);
        return strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    private function normalizeHeader(string $value): string
    {
        $text = mb_strtoupper($this->normalizeText($value));
        $text = strtr($text, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
        $text = str_replace(['.', ':', '_'], ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function normalizeCodigo(mixed $value): string
    {
        $text = $this->normalizeText((string) $value);
        if ($text === '') {
            return '';
        }

        return is_numeric($text) ? (string) (int) round((float) $text) : $text;
    }

    private function cellText(Worksheet $sheet, int $columnIndex, int $rowNum): string
    {
        $ref = $this->cellRef($columnIndex, $rowNum);
        if ($ref === null) {
            return '';
        }

        return $this->normalizeText((string) $sheet->getCell($ref)->getFormattedValue());
    }

    private function cellRef(int $columnIndex, int $rowNum): ?string
    {
        if ($columnIndex < 1 || $rowNum < 1) {
            return null;
        }

        return Coordinate::stringFromColumnIndex((int) $columnIndex) . (int) $rowNum;
    }

    private function columnLabel(int $columnIndex): string
    {
        return $columnIndex > 0 ? Coordinate::stringFromColumnIndex($columnIndex) : '-';
    }

    private function storeJsonEvidence(array $rows, string $tipo, ?int $anio, string $sheetName, string $fileName, string $usuario, array $counts, array $details): string
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
            'counts' => $counts,
            'details' => $details,
            'headers' => self::GRID_HEADERS,
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
        return count(array_filter($details, static fn(array $detail): bool => strtoupper((string) ($detail['severity'] ?? '')) === strtoupper($severity)));
    }

    private function normalizeText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
