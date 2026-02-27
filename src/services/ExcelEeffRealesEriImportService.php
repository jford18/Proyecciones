<?php

declare(strict_types=1);

namespace App\services;

use App\repositories\PresupuestoIngresosRepository;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelEeffRealesEriImportService
{
    private const TAB_KEY = 'eeff_reales_eri';
    private const TAB_LOG = 'EEFF_REALES_ERI';
    private const TARGET_TABLE = 'EEFF_REALES_ERI_IMPORT';
    private const SHEET_NAME = 'ERI';
    private const MONTH_KEYS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(private PresupuestoIngresosRepository $repository, private ?NumberParser $numberParser = null)
    {
        $this->numberParser ??= new NumberParser();
    }

    public function validate(string $fileTmpPath, string $tipo, ?int $anioRequest = null, ?string $originalFileName = null): array
    {
        $parsed = $this->parseSheet($fileTmpPath, $originalFileName, $anioRequest);
        $jsonPath = $this->storeJsonEvidence($parsed['rows'], $tipo, $parsed['anio'], $parsed['sheet_name'], $parsed['file_name'], $parsed['counts'], $parsed['details']);

        $warnings = array_values(array_filter(
            $parsed['details'],
            static fn (array $detail): bool => strtoupper((string) ($detail['severity'] ?? '')) === 'WARNING'
        ));
        $errors = array_values(array_filter(
            $parsed['details'],
            static fn (array $detail): bool => strtoupper((string) ($detail['severity'] ?? '')) === 'ERROR'
        ));

        $response = [
            'ok' => true,
            'tab' => self::TAB_KEY,
            'tipo' => $tipo,
            'target_table' => self::TARGET_TABLE,
            'sheet_name' => $parsed['sheet_name'],
            'file_name' => $parsed['file_name'],
            'anio' => $parsed['anio'],
            'inserted_count' => 0,
            'updated_count' => 0,
            'skipped_count' => (int) ($parsed['counts']['omitted_rows'] ?? 0),
            'warning_count' => (int) ($parsed['counts']['warning_rows'] ?? 0),
            'error_count' => (int) ($parsed['counts']['error_rows'] ?? 0),
            'summary' => [
                'total_rows' => (int) ($parsed['counts']['total_rows'] ?? 0),
                'importables' => (int) ($parsed['counts']['importable_rows'] ?? 0),
                'warning_rows' => (int) ($parsed['counts']['warning_rows'] ?? 0),
                'error_rows' => (int) ($parsed['counts']['error_rows'] ?? 0),
            ],
            'counts' => $parsed['counts'],
            'details' => $parsed['details'],
            'warnings' => $warnings,
            'errors' => $errors,
            'preview' => array_slice($parsed['rows'], 0, 50),
            'json_path' => $jsonPath,
            'meta' => ['sheet_name' => $parsed['sheet_name'], 'file_name' => $parsed['file_name']],
            'user' => 'validate',
            'timestamp' => date('c'),
        ];

        $this->repository->insertImportLog($response + ['tab' => self::TAB_LOG, 'usuario' => 'validate']);
        error_log(sprintf(
            '[EEFF_REALES_ERI][VALIDAR] TAB_KEY=%s, TAB_LOG=%s, TIPO=%s, JSON_PATH=%s',
            self::TAB_KEY,
            self::TAB_LOG,
            $tipo,
            $jsonPath
        ));

        return $response;
    }

    public function executeFromValidatedJson(string $tipo, string $usuario, ?int $anioRequest, ?int $proyectoId = null): array
    {
        $latest = $this->repository->findLatestImportLogByTabTipo(self::TAB_LOG, $tipo);
        error_log(sprintf(
            '[EEFF_REALES_ERI][IMPORTAR] buscando TAB_LOG=%s, TIPO=%s, encontrado=%s, JSON_PATH=%s',
            self::TAB_LOG,
            $tipo,
            is_array($latest) ? 'SI' : 'NO',
            is_array($latest) ? (string) ($latest['JSON_PATH'] ?? $latest['json_path'] ?? '') : ''
        ));
        if (!is_array($latest)) {
            throw new \RuntimeException('No existe validación previa para EEFF Reales ERI. Debe validar primero.');
        }

        $jsonPath = trim((string) ($latest['JSON_PATH'] ?? $latest['json_path'] ?? ''));
        if ($jsonPath === '') {
            throw new \RuntimeException('No existe JSON validado para EEFF Reales ERI. Debe validar primero.');
        }

        $absolutePath = dirname(__DIR__, 2) . '/' . ltrim($jsonPath, '/');
        if (!is_file($absolutePath)) {
            throw new \RuntimeException('No se encontró el JSON validado. Debe validar nuevamente.');
        }

        $decoded = json_decode((string) file_get_contents($absolutePath), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('JSON validado corrupto. Debe validar nuevamente.');
        }

        $rows = is_array($decoded['rows'] ?? null) ? $decoded['rows'] : [];
        if ($rows === []) {
            throw new \RuntimeException('No hay filas importables para EEFF Reales ERI.');
        }

        $sheetName = (string) ($decoded['sheet_name'] ?? $latest['SHEET_NAME'] ?? $latest['HOJA_NOMBRE'] ?? self::SHEET_NAME);
        $fileName = (string) ($decoded['file_name'] ?? $latest['FILE_NAME'] ?? $latest['ARCHIVO_NOMBRE'] ?? 'archivo.xlsx');
        $anio = $anioRequest ?? (int) ($decoded['anio'] ?? 0);
        if ($anio <= 0) {
            $anio = (int) date('Y');
        }

        $inserted = $this->repository->insertEeffRealesEriImportRows($tipo, $anio, $sheetName, $fileName, $usuario, $rows, $proyectoId);
        $counts = [
            'total_rows' => count($rows),
            'importable_rows' => count($rows),
            'imported_rows' => $inserted,
            'updated_rows' => 0,
            'omitted_rows' => 0,
            'warning_rows' => (int) (($decoded['counts']['warning_rows'] ?? 0)),
            'error_rows' => 0,
        ];

        $response = [
            'ok' => true,
            'tab' => self::TAB_KEY,
            'tipo' => $tipo,
            'target_table' => self::TARGET_TABLE,
            'file_name' => $fileName,
            'sheet_name' => $sheetName,
            'anio' => $anio,
            'inserted_count' => $inserted,
            'updated_count' => 0,
            'skipped_count' => 0,
            'warning_count' => (int) ($counts['warning_rows'] ?? 0),
            'error_count' => 0,
            'counts' => $counts,
            'details' => [],
            'preview' => array_slice($rows, 0, 50),
            'json_path' => $jsonPath,
            'meta' => ['sheet_name' => $sheetName, 'file_name' => $fileName],
            'user' => $usuario,
            'timestamp' => date('c'),
        ];

        $this->repository->insertImportLog($response + ['tab' => self::TAB_LOG, 'usuario' => $usuario]);

        return $response;
    }

    private function parseSheet(string $fileTmpPath, ?string $originalFileName, ?int $anioRequest): array
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($fileTmpPath);

        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);
        if (!$sheet instanceof Worksheet) {
            $sheetNames = $spreadsheet->getSheetNames();
            throw new \RuntimeException('No existe la hoja objetivo "ERI". Hojas encontradas: ' . implode(', ', $sheetNames));
        }

        $headerMap = $this->resolveHeaderMap($sheet);
        $highestRow = (int) $sheet->getHighestDataRow();
        $rows = [];
        $details = [];
        $totalRows = 0;
        $descLogs = 0;

        for ($rowNum = $headerMap['header_row'] + 1; $rowNum <= $highestRow; $rowNum++) {
            $totalRows++;
            $codigo = trim((string) $this->cellValue($sheet, (int) $headerMap['codigo'], $rowNum, false));
            $description = $this->resolveDescriptionValue($sheet, $spreadsheet, (int) $headerMap['descripcion'], $rowNum);
            $descripcion = $description['resolved'];

            if ($descLogs < 3 && ($codigo !== '' || $descripcion !== '')) {
                error_log(sprintf(
                    '[EEFF_REALES_ERI][DESC] codigo=%s raw=%s resolved=%s',
                    $codigo !== '' ? $codigo : '-',
                    $description['raw'],
                    $descripcion
                ));
                $descLogs++;
            }

            $item = [
                'codigo' => $codigo,
                'descripcion' => $descripcion,
                'origen_fila' => $rowNum,
            ];

            $hasNumeric = false;
            foreach (self::MONTH_KEYS as $month) {
                $col = $headerMap[$month] ?? null;
                $value = $this->readNumber($sheet, $rowNum, $col, $details);
                $item[$month] = $value;
                if ($value !== 0.0) {
                    $hasNumeric = true;
                }
            }

            $totalCol = $headerMap['total'] ?? null;
            if ($totalCol !== null) {
                $item['total'] = $this->readNumber($sheet, $rowNum, $totalCol, $details);
                if ($item['total'] !== 0.0) {
                    $hasNumeric = true;
                }
            } else {
                $item['total'] = array_sum(array_map(static fn(string $month): float => (float) ($item[$month] ?? 0.0), self::MONTH_KEYS));
            }

            if ($codigo === '' && $descripcion === '' && !$hasNumeric) {
                $details[] = $this->detail($rowNum, '-', 'WARNING', 'EMPTY_ROW', 'Fila vacía; se omite.');
                continue;
            }

            if (!$hasNumeric) {
                $details[] = $this->detail($rowNum, '-', 'WARNING', 'EMPTY_NUMERIC_ROW', 'Fila vacía; se omite.');
                continue;
            }

            if ($codigo === '') {
                $details[] = $this->detail($rowNum, 'CODIGO', 'ERROR', 'EMPTY_CODIGO', 'CODIGO no puede estar vacío.');
                continue;
            }

            if ($descripcion === '') {
                $details[] = $this->detail($rowNum, 'DESCRIPCION', 'ERROR', 'EMPTY_DESCRIPCION', 'DESCRIPCION no puede estar vacía.');
                continue;
            }

            $rows[] = $item;
        }

        return [
            'sheet_name' => $sheet->getTitle(),
            'file_name' => $originalFileName ?: basename($fileTmpPath),
            'anio' => $anioRequest,
            'rows' => $rows,
            'details' => $details,
            'counts' => [
                'total_rows' => $totalRows,
                'importable_rows' => count($rows),
                'imported_rows' => 0,
                'updated_rows' => 0,
                'omitted_rows' => max(0, $totalRows - count($rows)),
                'warning_rows' => $this->countBySeverity($details, 'WARNING'),
                'error_rows' => $this->countBySeverity($details, 'ERROR'),
            ],
        ];
    }

    private function resolveHeaderMap(Worksheet $sheet): array
    {
        $highestRow = min((int) $sheet->getHighestRow(), 15);
        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $requiredMonths = array_fill_keys(self::MONTH_KEYS, true);

        for ($row = 1; $row <= $highestRow; $row++) {
            $map = ['header_row' => $row];
            for ($col = 1; $col <= $highestCol; $col++) {
                $normalized = $this->normalizeHeaderValue($this->cellValue($sheet, $col, $row, false));
                if ($normalized === '' || $normalized === '%') {
                    continue;
                }

                if ($normalized === 'CODIGO') {
                    $map['codigo'] = $col;
                    continue;
                }
                if ($normalized === 'DESCRIPCION') {
                    $map['descripcion'] = $col;
                    continue;
                }
                foreach (self::MONTH_KEYS as $month) {
                    if (strtoupper($month) === $normalized) {
                        $map[$month] = $col;
                        continue 2;
                    }
                }
                if ($normalized === 'TOTAL') {
                    $map['total'] = $col;
                }
            }

            $foundMonths = array_intersect_key($map, $requiredMonths);
            if (isset($map['codigo'], $map['descripcion']) && count($foundMonths) >= 1) {
                return $map;
            }
        }

        throw new \RuntimeException('No se encontró encabezado válido para EEFF Reales ERI (CODIGO, DESCRIPCION, ENERO..DICIEMBRE).');
    }

    private function normalizeHeaderValue(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        $withoutAccents = strtr($text, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A',
            'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
            'ñ' => 'n', 'Ñ' => 'N',
        ]);

        return strtoupper((string) preg_replace('/\s+/', '', $withoutAccents));
    }

    private function readNumber(Worksheet $sheet, int $rowNum, ?int $column, array &$details): float
    {
        if ($column === null || $column <= 0) {
            return 0.0;
        }
        $raw = $this->cellValue($sheet, $column, $rowNum, true);
        $parsed = $this->numberParser->parse($raw);
        if (($parsed['is_numeric'] ?? false) !== true) {
            $details[] = $this->detail($rowNum, (string) $column, 'WARNING', 'INVALID_NUMERIC', 'Valor numérico inválido; se interpreta como 0.', (string) $raw);
            return 0.0;
        }

        return (float) ($parsed['value'] ?? 0.0);
    }

    private function cellValue(Worksheet $sheet, int $col, int $row, bool $calculated = true): mixed
    {
        $addr = Coordinate::stringFromColumnIndex($col) . $row;
        $cell = $sheet->getCell($addr);
        if (!$cell) {
            return null;
        }

        return $calculated ? $cell->getCalculatedValue() : $cell->getValue();
    }

    private function resolveDescriptionValue(Worksheet $sheet, Spreadsheet $spreadsheet, int $col, int $row): array
    {
        $addr = Coordinate::stringFromColumnIndex($col) . $row;
        $cell = $sheet->getCell($addr);
        if (!$cell) {
            return ['raw' => '', 'resolved' => ''];
        }

        $raw = trim((string) $cell->getValue());
        $resolved = '';

        if ($cell->isFormula()) {
            $val = $cell->getCalculatedValue();
            $resolved = trim((string) ($val ?? ''));

            if (is_string($val) && (str_contains($val, "!'") || str_starts_with($val, '='))) {
                $reference = trim($val);
                if (preg_match('/^=?\'?([^\']+)\'?\!([$]?[A-Z]+[$]?\d+)$/', $reference, $matches) === 1) {
                    $sheetName = trim((string) $matches[1]);
                    $address = strtoupper(str_replace('$', '', trim((string) $matches[2])));
                    $targetSheet = $spreadsheet->getSheetByName($sheetName);
                    if ($targetSheet instanceof Worksheet) {
                        $target = $targetSheet->getCell($address);
                        $formattedValue = trim((string) $target->getFormattedValue());
                        if ($formattedValue !== '') {
                            $resolved = $formattedValue;
                        } else {
                            $resolved = trim((string) $target->getCalculatedValue());
                        }
                    }
                }
            }
        } else {
            $resolved = trim((string) $cell->getFormattedValue());
        }

        return ['raw' => $raw, 'resolved' => $resolved];
    }

    private function storeJsonEvidence(array $rows, string $tipo, ?int $anio, string $sheetName, string $fileName, array $counts, array $details): string
    {
        $relativePath = 'var/import_store/eeff_reales_eri.json';
        $absolutePath = dirname(__DIR__, 2) . '/' . $relativePath;
        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($absolutePath, json_encode([
            'tab' => self::TAB_KEY,
            'tipo' => $tipo,
            'anio' => $anio,
            'sheet_name' => $sheetName,
            'file_name' => $fileName,
            'headers' => ['CODIGO', 'DESCRIPCION', 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE', 'TOTAL'],
            'rows' => $rows,
            'counts' => $counts,
            'details' => $details,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $relativePath;
    }

    private function detail(int $row, string $column, string $severity, string $code, string $message, string $rawValue = ''): array
    {
        return [
            'row_num' => $row,
            'column' => $column,
            'severity' => strtoupper($severity),
            'code' => $code,
            'message' => $message,
            'raw_value' => $rawValue,
        ];
    }

    private function countBySeverity(array $details, string $severity): int
    {
        $sev = strtoupper($severity);
        $count = 0;
        foreach ($details as $detail) {
            if (strtoupper((string) ($detail['severity'] ?? '')) === $sev) {
                $count++;
            }
        }

        return $count;
    }
}
