<?php

declare(strict_types=1);

namespace App\services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelAnexoImportService
{
    private array $meses = [
        'ene' => 1, 'enero' => 1,
        'feb' => 2, 'febrero' => 2,
        'mar' => 3, 'marzo' => 3,
        'abr' => 4, 'abril' => 4,
        'may' => 5, 'mayo' => 5,
        'jun' => 6, 'junio' => 6,
        'jul' => 7, 'julio' => 7,
        'ago' => 8, 'agosto' => 8,
        'sep' => 9, 'sept' => 9, 'septiembre' => 9,
        'oct' => 10, 'octubre' => 10,
        'nov' => 11, 'noviembre' => 11,
        'dic' => 12, 'diciembre' => 12,
    ];

    public function importAnexo(string $path, int $proyectoId, string $tipoAnexo, string $tipo): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $this->resolveSheet($spreadsheet->getAllSheets(), $tipoAnexo);
        if (!$sheet instanceof Worksheet) {
            throw new \RuntimeException("No existe la hoja esperada para {$tipoAnexo}.");
        }

        $year = $this->detectYear($sheet);
        $monthColumns = $this->detectMonthColumns($sheet);
        if ($monthColumns === []) {
            throw new \RuntimeException("No se detectaron columnas de meses en la hoja {$sheet->getTitle()}.");
        }

        $rows = [];
        $warnings = 0;
        $highestRow = $sheet->getHighestDataRow();
        for ($row = 6; $row <= $highestRow; $row++) {
            $codigo = trim((string) $sheet->getCell([1, $row])->getFormattedValue());
            $concepto = trim((string) $sheet->getCell([2, $row])->getFormattedValue());
            $descripcion = trim((string) $sheet->getCell([3, $row])->getFormattedValue());
            if ($codigo === '' && $concepto === '') {
                continue;
            }

            foreach ($monthColumns as $col => $mes) {
                $valor = $this->toFloat($sheet->getCell([$col, $row])->getCalculatedValue());
                if ($valor === 0.0) {
                    $warnings++;
                    continue;
                }

                $rows[] = [
                    'proyecto_id' => $proyectoId,
                    'tipo_anexo' => $tipoAnexo,
                    'tipo' => $tipo,
                    'fecha' => sprintf('%04d-%02d-01 00:00:00', $year, $mes),
                    'periodo' => (int) sprintf('%04d%02d01', $year, $mes),
                    'mes' => $mes,
                    'codigo' => $codigo !== '' ? $codigo : null,
                    'concepto' => $concepto !== '' ? $concepto : null,
                    'descripcion' => $descripcion !== '' ? $descripcion : null,
                    'valor' => $valor,
                    'origen_archivo' => basename($path),
                    'origen_hoja' => $sheet->getTitle(),
                    'origen_fila' => $row,
                    'flujo_linea_id' => null,
                ];
            }
        }

        return ['sheet' => $sheet->getTitle(), 'rows' => $rows, 'warnings' => $warnings];
    }

    private function resolveSheet(array $sheets, string $tipoAnexo): ?Worksheet
    {
        $aliases = [
            'GASTOS' => ['GASTOS'],
            'NOMINA' => ['NOMINA', 'NÓMINA'],
            'COBRANZA' => ['COBRANZA'],
            'ACTIVOS' => ['ACTIVOS'],
        ][$tipoAnexo] ?? [$tipoAnexo];

        foreach ($sheets as $sheet) {
            $name = mb_strtoupper(trim($sheet->getTitle()));
            foreach ($aliases as $alias) {
                if ($name === mb_strtoupper($alias)) {
                    return $sheet;
                }
            }
        }

        return $sheets[0] ?? null;
    }

    private function detectYear(Worksheet $sheet): int
    {
        $candidates = [(string) $sheet->getCell('A2')->getValue(), (string) $sheet->getCell('A3')->getValue()];
        foreach ($candidates as $text) {
            if (preg_match('/(20\d{2})/', $text, $m) === 1) {
                return (int) $m[1];
            }
        }

        return (int) date('Y');
    }

    private function detectMonthColumns(Worksheet $sheet): array
    {
        $months = [];
        for ($headerRow = 4; $headerRow <= 6; $headerRow++) {
            for ($col = 3; $col <= 20; $col++) {
                $header = mb_strtolower(trim((string) $sheet->getCell([$col, $headerRow])->getFormattedValue()));
                if ($header !== '' && isset($this->meses[$header])) {
                    $months[$col] = $this->meses[$header];
                }
            }
            if ($months !== []) {
                break;
            }
        }

        return $months;
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
