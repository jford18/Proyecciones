<?php

declare(strict_types=1);

namespace App\services;

use PDO;
use RuntimeException;

class EriService
{
    private const MONTHS = [
        'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO',
        'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE',
    ];

    private const TABLES = [
        'ingresos' => 'PRESUPUESTO_INGRESOS',
        'costos' => 'PRESUPUESTO_COSTOS',
        'gastos_operacionales' => 'PRESUPUESTO_GASTOS_OPERACIONALES',
        'gastos_financieros' => 'PRESUPUESTO_GASTOS_FINANCIEROS',
        'otros_ingresos' => 'PRESUPUESTO_OTROS_INGRESOS',
        'otros_egresos' => 'PRESUPUESTO_OTROS_EGRESOS',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function build(int $anio, float $tasaParticipacion = 0.15, float $tasaRenta = 0.25, string $tipo = 'PRESUPUESTO'): array
    {
        $ingresos = $this->sumByMonth(self::TABLES['ingresos'], $anio, $tipo, 1);
        $costos = $this->sumByMonth(self::TABLES['costos'], $anio, $tipo, -1);
        $gananciaBruta = $this->combine([$ingresos, $costos]);

        $gastosOperacionales = $this->sumByMonth(self::TABLES['gastos_operacionales'], $anio, $tipo, -1);
        $resultadoOperacion = $this->combine([$gananciaBruta, $gastosOperacionales]);

        $otrosIngresos = $this->sumByMonth(self::TABLES['otros_ingresos'], $anio, $tipo, 1);
        $gastosFinancieros = $this->sumByMonth(self::TABLES['gastos_financieros'], $anio, $tipo, -1);
        $otrosEgresos = $this->sumByMonth(self::TABLES['otros_egresos'], $anio, $tipo, -1);

        $resultadoAntesImpuestos = $this->combine([
            $resultadoOperacion,
            $otrosIngresos,
            $gastosFinancieros,
            $otrosEgresos,
        ]);

        $participacion = $this->calculateParticipacion($resultadoAntesImpuestos, $tasaParticipacion);
        $impuesto = $this->calculateImpuesto($resultadoAntesImpuestos, $participacion, $tasaRenta);
        $resultadoPeriodo = $this->combine([$resultadoAntesImpuestos, $participacion, $impuesto]);

        return [
            'SUCCESS' => true,
            'ANIO' => $anio,
            'TIPO' => $tipo,
            'TASA_PARTICIPACION' => $tasaParticipacion,
            'TASA_RENTA' => $tasaRenta,
            'ROWS' => [
                ['LABEL' => 'INGRESOS', 'TYPE' => 'HEADER'],
                $this->row('TOTAL INGRESOS', 'TOTAL', $ingresos),

                ['LABEL' => 'COSTO DE VENTAS', 'TYPE' => 'HEADER'],
                $this->row('TOTAL COSTO DE VENTAS', 'TOTAL', $costos),

                $this->row('GANANCIA BRUTA', 'RESULT', $gananciaBruta),

                ['LABEL' => 'GASTOS OPERACIONALES', 'TYPE' => 'HEADER'],
                $this->row('TOTAL GASTOS OPERACIONALES', 'TOTAL', $gastosOperacionales),

                $this->row('RESULTADO DE OPERACIÓN', 'RESULT', $resultadoOperacion),

                ['LABEL' => 'OTROS INGRESOS', 'TYPE' => 'HEADER'],
                $this->row('TOTAL OTROS INGRESOS', 'TOTAL', $otrosIngresos),

                ['LABEL' => 'GASTOS FINANCIEROS', 'TYPE' => 'HEADER'],
                $this->row('TOTAL GASTOS FINANCIEROS', 'TOTAL', $gastosFinancieros),

                ['LABEL' => 'OTROS EGRESOS', 'TYPE' => 'HEADER'],
                $this->row('TOTAL OTROS EGRESOS', 'TOTAL', $otrosEgresos),

                $this->row('RESULTADO ANTES DE IMPUESTOS', 'RESULT', $resultadoAntesImpuestos),
                $this->row('PARTICIPACIÓN TRABAJADORES', 'CALC', $participacion),
                $this->row('IMPUESTO A LA RENTA', 'CALC', $impuesto),
                $this->row('RESULTADO DEL PERÍODO', 'RESULT_FINAL', $resultadoPeriodo),
            ],
        ];
    }

    private function row(string $label, string $type, array $months): array
    {
        return ['LABEL' => $label, 'TYPE' => $type, 'M' => $months, 'TOTAL' => array_sum($months)];
    }

    private function emptyMonths(): array
    {
        return array_fill_keys(self::MONTHS, 0.0);
    }

    private function combine(array $blocks): array
    {
        $result = $this->emptyMonths();
        foreach ($blocks as $block) {
            foreach (self::MONTHS as $month) {
                $result[$month] += (float) ($block[$month] ?? 0.0);
            }
        }

        return $result;
    }

    private function calculateParticipacion(array $resultadoAntesImpuestos, float $tasa): array
    {
        $values = $this->emptyMonths();
        foreach (self::MONTHS as $month) {
            $base = (float) ($resultadoAntesImpuestos[$month] ?? 0.0);
            $values[$month] = $base > 0 ? -($base * $tasa) : 0.0;
        }

        return $values;
    }

    private function calculateImpuesto(array $resultadoAntesImpuestos, array $participacion, float $tasa): array
    {
        $values = $this->emptyMonths();
        foreach (self::MONTHS as $month) {
            $base = (float) ($resultadoAntesImpuestos[$month] ?? 0.0) + (float) ($participacion[$month] ?? 0.0);
            $values[$month] = $base > 0 ? -($base * $tasa) : 0.0;
        }

        return $values;
    }

    private function sumByMonth(string $table, int $anio, string $tipo, int $sign): array
    {
        $columns = $this->columns($table);
        $sum = $this->emptyMonths();
        $abbrMap = [
            'ENERO' => 'ENE', 'FEBRERO' => 'FEB', 'MARZO' => 'MAR', 'ABRIL' => 'ABR',
            'MAYO' => 'MAY', 'JUNIO' => 'JUN', 'JULIO' => 'JUL', 'AGOSTO' => 'AGO',
            'SEPTIEMBRE' => 'SEP', 'OCTUBRE' => 'OCT', 'NOVIEMBRE' => 'NOV', 'DICIEMBRE' => 'DIC',
        ];

        if ($this->hasAllColumns($columns, array_values($abbrMap))) {
            $parts = [];
            foreach ($abbrMap as $month => $column) {
                $parts[] = 'SUM(COALESCE(' . $column . ',0)) AS ' . $month;
            }
            $sql = 'SELECT ' . implode(', ', $parts) . " FROM {$table} WHERE ANIO = :anio";
            $params = ['anio' => $anio];
            if (isset($columns['TIPO'])) {
                $sql .= ' AND TIPO = :tipo';
                $params['tipo'] = $tipo;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach (self::MONTHS as $month) {
                $sum[$month] = ((float) ($row[$month] ?? 0.0)) * $sign;
            }

            return $sum;
        }

        if ($this->hasAllColumns($columns, self::MONTHS)) {
            $parts = [];
            foreach (self::MONTHS as $month) {
                $parts[] = 'SUM(COALESCE(' . $month . ',0)) AS ' . $month;
            }
            $sql = 'SELECT ' . implode(', ', $parts) . " FROM {$table} WHERE ANIO = :anio";
            $params = ['anio' => $anio];
            if (isset($columns['TIPO'])) {
                $sql .= ' AND TIPO = :tipo';
                $params['tipo'] = $tipo;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach (self::MONTHS as $month) {
                $sum[$month] = ((float) ($row[$month] ?? 0.0)) * $sign;
            }

            return $sum;
        }

        if (isset($columns['MES']) && isset($columns['VALOR'])) {
            $sql = "SELECT MES, SUM(COALESCE(VALOR, 0)) AS VALOR FROM {$table} WHERE ANIO = :anio";
            $params = ['anio' => $anio];
            if (isset($columns['TIPO'])) {
                $sql .= ' AND TIPO = :tipo';
                $params['tipo'] = $tipo;
            }
            $sql .= ' GROUP BY MES';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $month = $this->normalizeMonth((string) ($row['MES'] ?? ''));
                if ($month !== null) {
                    $sum[$month] = ((float) ($row['VALOR'] ?? 0.0)) * $sign;
                }
            }

            return $sum;
        }

        throw new RuntimeException("{$table} no tiene estructura mensual compatible para ERI.");
    }

    private function normalizeMonth(string $input): ?string
    {
        $map = [
            '1' => 'ENERO', '2' => 'FEBRERO', '3' => 'MARZO', '4' => 'ABRIL', '5' => 'MAYO', '6' => 'JUNIO',
            '7' => 'JULIO', '8' => 'AGOSTO', '9' => 'SEPTIEMBRE', '10' => 'OCTUBRE', '11' => 'NOVIEMBRE', '12' => 'DICIEMBRE',
            'ENE' => 'ENERO', 'ENERO' => 'ENERO',
            'FEB' => 'FEBRERO', 'FEBRERO' => 'FEBRERO',
            'MAR' => 'MARZO', 'MARZO' => 'MARZO',
            'ABR' => 'ABRIL', 'ABRIL' => 'ABRIL',
            'MAY' => 'MAYO', 'MAYO' => 'MAYO',
            'JUN' => 'JUNIO', 'JUNIO' => 'JUNIO',
            'JUL' => 'JULIO', 'JULIO' => 'JULIO',
            'AGO' => 'AGOSTO', 'AGOSTO' => 'AGOSTO',
            'SEP' => 'SEPTIEMBRE', 'SET' => 'SEPTIEMBRE', 'SEPTIEMBRE' => 'SEPTIEMBRE',
            'OCT' => 'OCTUBRE', 'OCTUBRE' => 'OCTUBRE',
            'NOV' => 'NOVIEMBRE', 'NOVIEMBRE' => 'NOVIEMBRE',
            'DIC' => 'DICIEMBRE', 'DICIEMBRE' => 'DICIEMBRE',
        ];

        $key = strtoupper(trim($input));
        return $map[$key] ?? null;
    }

    private function hasAllColumns(array $columns, array $required): bool
    {
        foreach ($required as $column) {
            if (!isset($columns[$column])) {
                return false;
            }
        }

        return true;
    }

    private function columns(string $table): array
    {
        $stmt = $this->pdo->query('SHOW COLUMNS FROM ' . $table);
        $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $columns = [];
        foreach ($rows as $row) {
            $name = strtoupper((string) ($row['Field'] ?? ''));
            if ($name !== '') {
                $columns[$name] = true;
            }
        }

        return $columns;
    }
}
