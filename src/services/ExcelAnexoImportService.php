<?php

declare(strict_types=1);

namespace App\services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelAnexoImportService
{
    private array $meses = [
        'enero' => 1,
        'febrero' => 2,
        'marzo' => 3,
        'abril' => 4,
        'mayo' => 5,
        'junio' => 6,
        'julio' => 7,
        'agosto' => 8,
        'septiembre' => 9,
        'setiembre' => 9,
        'octubre' => 10,
        'noviembre' => 11,
        'diciembre' => 12,
    ];

    public function __construct(private AnexoMapeoService $mapeoService)
    {
    }

    public function importGastos(string $path, int $proyectoId): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('GASTOS');
        if (!$sheet instanceof Worksheet) {
            throw new \RuntimeException('No existe la hoja GASTOS.');
        }

        $year = $this->parseYearFromRangeText((string) $sheet->getCell('A3')->getValue());
        $monthsByColumn = $this->detectMonthColumns($sheet, 5, 3, 20);

        $rows = [];
        $highestRow = $sheet->getHighestDataRow();
        for ($row = 6; $row <= $highestRow; $row++) {
            $codigo = trim((string) $sheet->getCellByColumnAndRow(1, $row)->getFormattedValue());
            $concepto = trim((string) $sheet->getCellByColumnAndRow(2, $row)->getFormattedValue());
            if ($codigo === '' || $concepto === '') {
                continue;
            }

            foreach ($monthsByColumn as $col => $mes) {
                $raw = $sheet->getCellByColumnAndRow($col, $row)->getCalculatedValue();
                $valor = $this->toFloat($raw);
                if ($valor == 0.0) {
                    continue;
                }

                $fecha = sprintf('%04d-%02d-01 00:00:00', $year, $mes);
                $periodo = (int) sprintf('%04d%02d01', $year, $mes);
                $rows[] = [
                    'proyecto_id' => $proyectoId,
                    'tipo_anexo' => 'GASTOS',
                    'tipo' => 'PRESUPUESTO',
                    'fecha' => $fecha,
                    'periodo' => $periodo,
                    'mes' => $mes,
                    'codigo' => $codigo,
                    'concepto' => $concepto,
                    'descripcion' => null,
                    'valor' => $valor,
                    'origen_archivo' => basename($path),
                    'origen_hoja' => 'GASTOS',
                    'origen_fila' => $row,
                    'flujo_linea_id' => $this->mapeoService->resolveFlujoLineaId($proyectoId, 'GASTOS', $codigo, $concepto),
                ];
            }
        }

        return ['sheet' => 'GASTOS', 'rows' => $rows];
    }

    public function importNomina(string $path, int $proyectoId): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('NOMINA');
        if (!$sheet instanceof Worksheet) {
            throw new \RuntimeException('No existe la hoja NOMINA.');
        }

        [$mes, $anio] = $this->parseNominaMonthYear((string) $sheet->getCell('A2')->getValue());
        $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $highestRow = $sheet->getHighestDataRow();

        $excludedHeaders = ['número', 'numero', 'cedula', 'cédula', 'empleado', 'departamento', 'cargo', 'centro de costo', 'fecha registro'];
        $totales = [];

        for ($col = 1; $col <= $highestCol; $col++) {
            $header = trim((string) $sheet->getCellByColumnAndRow($col, 4)->getFormattedValue());
            if ($header === '') {
                continue;
            }

            if (in_array(mb_strtolower($header), $excludedHeaders, true)) {
                continue;
            }

            $sum = 0.0;
            for ($row = 5; $row <= $highestRow; $row++) {
                $sum += $this->toFloat($sheet->getCellByColumnAndRow($col, $row)->getCalculatedValue());
            }
            if ($sum != 0.0) {
                $totales[$header] = $sum;
            }
        }

        $rows = [];
        foreach ($totales as $concepto => $valor) {
            $rows[] = [
                'proyecto_id' => $proyectoId,
                'tipo_anexo' => 'NOMINA',
                'tipo' => 'REAL',
                'fecha' => sprintf('%04d-%02d-01 00:00:00', $anio, $mes),
                'periodo' => (int) sprintf('%04d%02d01', $anio, $mes),
                'mes' => $mes,
                'codigo' => null,
                'concepto' => $concepto,
                'descripcion' => 'NOMINA TOTAL',
                'valor' => $valor,
                'origen_archivo' => basename($path),
                'origen_hoja' => 'NOMINA',
                'origen_fila' => 4,
                'flujo_linea_id' => $this->mapeoService->resolveFlujoLineaId($proyectoId, 'NOMINA', null, $concepto),
            ];
        }

        return ['sheet' => 'NOMINA', 'rows' => $rows];
    }

    private function detectMonthColumns(Worksheet $sheet, int $headerRow, int $fromCol, int $toCol): array
    {
        $months = [];
        for ($col = $fromCol; $col <= $toCol; $col++) {
            $header = trim((string) $sheet->getCellByColumnAndRow($col, $headerRow)->getFormattedValue());
            $key = mb_strtolower($header);
            if ($key === '' || str_contains($key, 'acumul')) {
                continue;
            }
            if (isset($this->meses[$key])) {
                $months[$col] = $this->meses[$key];
            }
        }

        return $months;
    }

    private function parseYearFromRangeText(string $text): int
    {
        if (preg_match('/(\d{4})\D*$/', $text, $m) === 1) {
            return (int) $m[1];
        }

        return (int) date('Y');
    }

    private function parseNominaMonthYear(string $text): array
    {
        if (preg_match('/-\s*([[:alpha:]áéíóúÁÉÍÓÚ]+)\s+(\d{4})/u', $text, $m) === 1) {
            $mesNombre = mb_strtolower(trim($m[1]));
            $mes = $this->meses[$mesNombre] ?? null;
            if ($mes !== null) {
                return [$mes, (int) $m[2]];
            }
        }

        return [(int) date('n'), (int) date('Y')];
    }

    private function toFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = str_replace(['$', ' '], '', (string) $value);
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }
}
