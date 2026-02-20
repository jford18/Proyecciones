<?php

declare(strict_types=1);

namespace App\services;

use App\repositories\PresupuestoIngresosRepository;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelProduccionImportService
{
    private const SHEET_NAME = '7.- Produccion';
    private const GRID_HEADERS = ['ANIO', 'TIPO', 'PARAMETRO_KEY', 'PARAMETRO_NOMBRE', 'VALOR'];
    private const SCAN_ROWS_LIMIT = 60;

    public function __construct(private PresupuestoIngresosRepository $repository, private ?NumberParser $numberParser = null)
    {
        $this->numberParser ??= new NumberParser();
    }

    public function validate(string $fileTmpPath, string $tipo, ?int $anioRequest = null, ?string $originalFileName = null): array
    {
        $parsed = $this->parseProduccion($fileTmpPath, $tipo, $anioRequest, $originalFileName);
        $jsonPath = $this->storeJsonEvidence($parsed['rows'], $tipo, $parsed['anio'], $parsed['sheet_name'], $parsed['file_name'], 'validate', $parsed['counts'], $parsed['details']);

        return [
            'ok' => true,
            'tab' => 'produccion',
            'tipo' => $tipo,
            'target_table' => 'PRESUPUESTO_PRODUCCION_PARAMETRO',
            'file_name' => $parsed['file_name'],
            'sheet_name' => $parsed['sheet_name'],
            'anio' => $parsed['anio'],
            'inserted_count' => 0,
            'updated_count' => 0,
            'skipped_count' => (int) ($parsed['counts']['omitted_rows'] ?? 0),
            'warning_count' => (int) ($parsed['counts']['warning_rows'] ?? 0),
            'error_count' => (int) ($parsed['counts']['error_rows'] ?? 0),
            'counts' => $parsed['counts'],
            'details' => $parsed['details'],
            'preview' => array_slice($parsed['rows'], 0, 50),
            'json_path' => $jsonPath,
            'user' => 'validate',
            'timestamp' => date('c'),
        ];
    }

    public function previewGrid(string $fileTmpPath, string $tipo, ?int $anioRequest = null, ?string $originalFileName = null): array
    {
        $parsed = $this->parseProduccion($fileTmpPath, $tipo, $anioRequest, $originalFileName);

        return [
            'ok' => true,
            'tab' => 'produccion',
            'tipo' => $tipo,
            'sheet_name' => $parsed['sheet_name'],
            'file_name' => $parsed['file_name'],
            'headers' => self::GRID_HEADERS,
            'rows' => array_map(static fn (array $row): array => [
                'ANIO' => $row['ANIO'],
                'TIPO' => $row['TIPO'],
                'PARAMETRO_KEY' => $row['PARAMETRO_KEY'],
                'PARAMETRO_NOMBRE' => $row['PARAMETRO_NOMBRE'],
                'VALOR' => $row['VALOR'],
            ], $parsed['rows']),
            'counts' => $parsed['counts'],
            'details' => $parsed['details'],
        ];
    }

    public function execute(string $fileTmpPath, string $tipo, string $usuario, ?int $anioRequest = null, ?string $originalFileName = null): array
    {
        $parsed = $this->parseProduccion($fileTmpPath, $tipo, $anioRequest, $originalFileName);
        $upsert = $this->repository->upsertProduccionRows($tipo, $parsed['sheet_name'], $parsed['file_name'], $usuario, $parsed['rows']);

        $counts = $parsed['counts'];
        $counts['imported_rows'] = (int) ($upsert['inserted_count'] ?? 0);
        $counts['updated_rows'] = (int) ($upsert['updated_count'] ?? 0);

        $jsonPath = $this->storeJsonEvidence($parsed['rows'], $tipo, $parsed['anio'], $parsed['sheet_name'], $parsed['file_name'], $usuario, $counts, $parsed['details']);

        $response = [
            'ok' => true,
            'tab' => 'produccion',
            'tipo' => $tipo,
            'target_table' => 'PRESUPUESTO_PRODUCCION_PARAMETRO',
            'file_name' => $parsed['file_name'],
            'sheet_name' => $parsed['sheet_name'],
            'anio' => $parsed['anio'],
            'inserted_count' => (int) ($upsert['inserted_count'] ?? 0),
            'updated_count' => (int) ($upsert['updated_count'] ?? 0),
            'skipped_count' => (int) ($counts['omitted_rows'] ?? 0),
            'warning_count' => (int) ($counts['warning_rows'] ?? 0),
            'error_count' => (int) ($counts['error_rows'] ?? 0),
            'counts' => $counts,
            'details' => $parsed['details'],
            'preview' => array_slice($parsed['rows'], 0, 50),
            'json_path' => $jsonPath,
            'user' => $usuario,
            'timestamp' => date('c'),
        ];

        $this->repository->insertImportLog($response + ['usuario' => $usuario]);

        return $response;
    }

    private function parseProduccion(string $fileTmpPath, string $tipo, ?int $anioRequest = null, ?string $originalFileName = null): array
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($fileTmpPath);

        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);
        if (!$sheet instanceof Worksheet) {
            throw new \RuntimeException('No existe la hoja requerida: ' . self::SHEET_NAME);
        }

        $anio = $anioRequest ?? (int) date('Y');
        $rows = [];
        $details = [];
        $scanLimit = min(self::SCAN_ROWS_LIMIT, (int) $sheet->getHighestRow());

        for ($rowNum = 1; $rowNum <= $scanLimit; $rowNum++) {
            $rawA = $this->cellText($sheet, 1, $rowNum);
            $rawB = $this->cellText($sheet, 2, $rowNum);

            if ($rawA === '' && $rawB === '') {
                $details[] = $this->detail($rowNum, 'A:B', 'WARNING', 'EMPTY_ROW', 'Fila vacía; se omite.');
                continue;
            }

            [$paramName, $rawValue] = $this->splitParametro($rawA, $rawB);
            if ($paramName === '') {
                $details[] = $this->detail($rowNum, 'A', 'WARNING', 'EMPTY_PARAMETRO', 'Nombre de parámetro vacío; fila omitida.', $rawA);
                continue;
            }

            $normalizedValue = $this->normalizeNumericString($rawValue);
            if ($normalizedValue === null) {
                $details[] = $this->detail($rowNum, 'B', 'WARNING', 'INVALID_VALOR', 'VALOR vacío o no numérico; fila omitida.', $rawValue);
                continue;
            }

            $rows[] = [
                'ANIO' => $anio,
                'TIPO' => $tipo,
                'PARAMETRO_KEY' => $this->buildParametroKey($paramName),
                'PARAMETRO_NOMBRE' => $paramName,
                'VALOR' => $normalizedValue,
            ];
        }

        if ($rows === []) {
            $details[] = $this->detail(0, '-', 'ERROR', 'NO_PARAMETROS', 'La hoja no contiene parámetros reconocibles en A/B o formato "PARAMETRO | VALOR".');
            throw new \RuntimeException('La hoja "' . self::SHEET_NAME . '" no contiene parámetros reconocibles.');
        }

        $counts = [
            'total_rows' => $scanLimit,
            'importable_rows' => count($rows),
            'imported_rows' => 0,
            'updated_rows' => 0,
            'omitted_rows' => max(0, $scanLimit - count($rows)),
            'warning_rows' => $this->countBySeverity($details, 'WARNING'),
            'error_rows' => $this->countBySeverity($details, 'ERROR'),
        ];

        return [
            'sheet_name' => $sheet->getTitle(),
            'file_name' => $originalFileName ?: basename($fileTmpPath),
            'anio' => $anio,
            'rows' => $rows,
            'details' => $details,
            'counts' => $counts,
        ];
    }

    private function splitParametro(string $rawA, string $rawB): array
    {
        if ($rawB !== '') {
            return [$this->normalizeText($rawA), $rawB];
        }

        if (str_contains($rawA, '|')) {
            [$left, $right] = array_pad(explode('|', $rawA, 2), 2, '');
            return [$this->normalizeText($left), trim($right)];
        }

        return [$this->normalizeText($rawA), ''];
    }

    private function normalizeNumericString(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^[-+]?\d+\s+\d+$/', $value) === 1) {
            $value = str_replace(' ', '.', $value);
        } else {
            $value = preg_replace('/\s+/', '', $value) ?? $value;
        }

        if (preg_match('/,\d{1,4}$/', $value) === 1) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (substr_count($value, '.') > 1) {
            $parts = explode('.', $value);
            $decimal = array_pop($parts);
            $value = implode('', $parts) . '.' . $decimal;
        }

        if (!is_numeric($value)) {
            $parsed = $this->numberParser->parse($value);
            if (($parsed['is_numeric'] ?? false) !== true) {
                return null;
            }
            return round((float) ($parsed['value'] ?? 0), 4);
        }

        return round((float) $value, 4);
    }

    private function buildParametroKey(string $name): string
    {
        $key = strtoupper($this->normalizeText($name));
        $key = strtr($key, [
            'Á' => 'A',
            'É' => 'E',
            'Í' => 'I',
            'Ó' => 'O',
            'Ú' => 'U',
            'Ñ' => 'N',
        ]);
        $key = preg_replace('/[^A-Z0-9]+/', '_', $key) ?? '';
        $key = preg_replace('/_+/', '_', $key) ?? '';

        return trim($key, '_');
    }

    private function cellText(Worksheet $sheet, int $col, int $row): string
    {
        return $this->normalizeText((string) $sheet->getCell([$col, $row])->getFormattedValue());
    }

    private function storeJsonEvidence(array $rows, string $tipo, int $anio, string $sheetName, string $fileName, string $usuario, array $counts, array $details): string
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
        return count(array_filter($details, static fn (array $detail): bool => strtoupper((string) ($detail['severity'] ?? '')) === strtoupper($severity)));
    }

    private function normalizeText(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
