<?php

declare(strict_types=1);

namespace App\services;

use PDO;

class EriService
{
    private const MONTHS = ['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO','JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'];
    private const MONTH_TO_DB = ['ENERO'=>'ENE','FEBRERO'=>'FEB','MARZO'=>'MAR','ABRIL'=>'ABR','MAYO'=>'MAY','JUNIO'=>'JUN','JULIO'=>'JUL','AGOSTO'=>'AGO','SEPTIEMBRE'=>'SEP','OCTUBRE'=>'OCT','NOVIEMBRE'=>'NOV','DICIEMBRE'=>'DIC'];

    /**
     * ERI_TEMPLATE base (ordenado por ROW).
     * Nota: se incluyen todas las filas base de cálculo necesarias para ERI y se completan cuentas vacías con 0.00.
     */
    private const ERI_TEMPLATE = [
        ['ROW'=>5,'CODE'=>'401','DESC'=>'A.  INGRESOS DE ACTIVIDADES ORDINARIAS','TYPE'=>'HEADER','SOURCE_TABLE'=>'PRESUPUESTO_INGRESOS','SIGN'=>1],
        ['ROW'=>6,'CODE'=>'4010101','DESC'=>'VENTA DE REPUESTOS','TYPE'=>'DETAIL','SOURCE_TABLE'=>'PRESUPUESTO_INGRESOS','SIGN'=>1],
        ['ROW'=>7,'CODE'=>'4010102','DESC'=>'VENTA DE MATERIALES','TYPE'=>'DETAIL','SOURCE_TABLE'=>'PRESUPUESTO_INGRESOS','SIGN'=>1],
        ['ROW'=>8,'CODE'=>'4010103','DESC'=>'VENTA DE PRODUCTOS','TYPE'=>'DETAIL','SOURCE_TABLE'=>'PRESUPUESTO_INGRESOS','SIGN'=>1],
        ['ROW'=>9,'CODE'=>'4010104','DESC'=>'VENTA DE INSUMOS MEDICOS','TYPE'=>'DETAIL','SOURCE_TABLE'=>'PRESUPUESTO_INGRESOS','SIGN'=>1],
        ['ROW'=>10,'CODE'=>'4010105','DESC'=>'VENTA DE ACTIVOS BIOLOGICOS','TYPE'=>'DETAIL','SOURCE_TABLE'=>'PRESUPUESTO_INGRESOS','SIGN'=>1],
        ['ROW'=>11,'CODE'=>'4010106','DESC'=>'VENTA DE PLASTICOS','TYPE'=>'DETAIL','SOURCE_TABLE'=>'PRESUPUESTO_INGRESOS','SIGN'=>1],
        ['ROW'=>12,'CODE'=>'4010107','DESC'=>'','TYPE'=>'DETAIL','SOURCE_TABLE'=>'PRESUPUESTO_INGRESOS','SIGN'=>1],
        ['ROW'=>13,'CODE'=>'4010108','DESC'=>'','TYPE'=>'DETAIL','SOURCE_TABLE'=>'PRESUPUESTO_INGRESOS','SIGN'=>1],
        ['ROW'=>14,'CODE'=>'4010109','DESC'=>'','TYPE'=>'DETAIL','SOURCE_TABLE'=>'PRESUPUESTO_INGRESOS','SIGN'=>1],
        ['ROW'=>15,'CODE'=>'4010110','DESC'=>'','TYPE'=>'DETAIL','SOURCE_TABLE'=>'PRESUPUESTO_INGRESOS','SIGN'=>1],
        ['ROW'=>16,'CODE'=>'4010111','DESC'=>'','TYPE'=>'DETAIL','SOURCE_TABLE'=>'PRESUPUESTO_INGRESOS','SIGN'=>1],
        ['ROW'=>17,'CODE'=>'4010112','DESC'=>'','TYPE'=>'DETAIL','SOURCE_TABLE'=>'PRESUPUESTO_INGRESOS','SIGN'=>1],
        ['ROW'=>18,'CODE'=>'40101','DESC'=>'    Venta de Bienes','TYPE'=>'SUBTOTAL','SOURCE_TABLE'=>'PRESUPUESTO_INGRESOS','SIGN'=>1],
        ['ROW'=>76,'CODE'=>'401','DESC'=>'TOTAL INGRESOS DE ACTIVIDADES ORDINARIAS','TYPE'=>'TOTAL','SOURCE_TABLE'=>'PRESUPUESTO_INGRESOS','SIGN'=>1],

        ['ROW'=>77,'CODE'=>'501','DESC'=>'B. COSTO DE VENTAS','TYPE'=>'HEADER','SOURCE_TABLE'=>'PRESUPUESTO_COSTOS','SIGN'=>-1],
        ['ROW'=>128,'CODE'=>'501','DESC'=>'TOTAL COSTO DE VENTAS','TYPE'=>'TOTAL','SOURCE_TABLE'=>'PRESUPUESTO_COSTOS','SIGN'=>-1],
        ['ROW'=>130,'CODE'=>null,'DESC'=>'GANANCIA BRUTA','TYPE'=>'RESULT','SOURCE_TABLE'=>null,'SIGN'=>1],

        ['ROW'=>131,'CODE'=>'701','DESC'=>'C. GASTOS OPERACIONALES','TYPE'=>'HEADER','SOURCE_TABLE'=>'PRESUPUESTO_GASTOS_OPERACIONALES','SIGN'=>-1],
        ['ROW'=>255,'CODE'=>'701','DESC'=>'TOTAL GASTOS OPERACIONALES','TYPE'=>'TOTAL','SOURCE_TABLE'=>'PRESUPUESTO_GASTOS_OPERACIONALES','SIGN'=>-1],
        ['ROW'=>257,'CODE'=>null,'DESC'=>'RESULTADO DE OPERACIÓN','TYPE'=>'RESULT','SOURCE_TABLE'=>null,'SIGN'=>1],

        ['ROW'=>258,'CODE'=>'80101','DESC'=>'D. OTROS INGRESOS','TYPE'=>'HEADER','SOURCE_TABLE'=>'PRESUPUESTO_OTROS_INGRESOS','SIGN'=>1],
        ['ROW'=>285,'CODE'=>'80101','DESC'=>'TOTAL OTROS INGRESOS','TYPE'=>'TOTAL','SOURCE_TABLE'=>'PRESUPUESTO_OTROS_INGRESOS','SIGN'=>1],

        ['ROW'=>286,'CODE'=>'70301','DESC'=>'E. GASTOS FINANCIEROS','TYPE'=>'HEADER','SOURCE_TABLE'=>'PRESUPUESTO_GASTOS_FINANCIEROS','SIGN'=>-1],
        ['ROW'=>328,'CODE'=>'70301','DESC'=>'TOTAL GASTOS FINANCIEROS','TYPE'=>'TOTAL','SOURCE_TABLE'=>'PRESUPUESTO_GASTOS_FINANCIEROS','SIGN'=>-1],

        ['ROW'=>329,'CODE'=>'90101','DESC'=>'F. OTROS EGRESOS','TYPE'=>'HEADER','SOURCE_TABLE'=>'PRESUPUESTO_OTROS_EGRESOS','SIGN'=>-1],
        ['ROW'=>356,'CODE'=>'90101','DESC'=>'TOTAL OTROS EGRESOS','TYPE'=>'TOTAL','SOURCE_TABLE'=>'PRESUPUESTO_OTROS_EGRESOS','SIGN'=>-1],

        ['ROW'=>358,'CODE'=>null,'DESC'=>'RESULTADO ANTES DE IMPUESTOS','TYPE'=>'RESULT','SOURCE_TABLE'=>null,'SIGN'=>1],
        ['ROW'=>362,'CODE'=>null,'DESC'=>'PARTICIPACIÓN TRABAJADORES','TYPE'=>'CALC','SOURCE_TABLE'=>null,'SIGN'=>-1],
        ['ROW'=>364,'CODE'=>null,'DESC'=>'IMPUESTO A LA RENTA','TYPE'=>'CALC','SOURCE_TABLE'=>null,'SIGN'=>-1],
        ['ROW'=>366,'CODE'=>null,'DESC'=>'RESULTADO DEL PERIODO','TYPE'=>'RESULT_FINAL','SOURCE_TABLE'=>null,'SIGN'=>1],
    ];

    public function __construct(private PDO $pdo) {}

    public function build(int $periodo, float $tasaPart = 0.15, float $tasaRenta = 0.25): array
    {
        $template = self::ERI_TEMPLATE;
        usort($template, fn(array $a, array $b) => (int) $a['ROW'] <=> (int) $b['ROW']);

        $detailByCode = $this->loadDetails($periodo, $template);
        $rows = [];
        $rowsByCode = [];
        $rowsByRow = [];

        foreach ($template as $meta) {
            $values = $this->zeroMonths();
            $type = (string) $meta['TYPE'];
            $code = $meta['CODE'];
            if ($type === 'DETAIL' && is_string($code)) {
                $values = $detailByCode[$code] ?? $values;
            } elseif ($type === 'SUBTOTAL' && is_string($code)) {
                $values = $this->subtotalFromDetails((string) $code, $detailByCode);
            } elseif ($type === 'TOTAL' && is_string($code)) {
                $values = $this->resolveTotal($code, $rowsByCode, $detailByCode);
            }

            $row = [
                'ROW' => (int) $meta['ROW'],
                'CODE' => $code,
                'DESC' => (string) ($meta['DESC'] ?? ''),
                'TYPE' => $type,
            ] + $values;

            $rows[] = $row;
            if (is_string($code) && $code !== '') {
                $rowsByCode[$code] = $values;
            }
            $rowsByRow[(int) $meta['ROW']] = &$rows[array_key_last($rows)];
        }

        $this->applyFormulaRows($rowsByRow, $tasaPart, $tasaRenta);

        $totalIngresos = $rowsByRow[76] ?? $this->emptyRow(76, '401', 'TOTAL INGRESOS DE ACTIVIDADES ORDINARIAS', 'TOTAL');
        foreach ($rows as &$row) {
            foreach (self::MONTHS as $month) {
                $den = (float) ($totalIngresos[$month] ?? 0.0);
                $row[$month . '_PCT'] = $den == 0.0 ? 0.0 : (((float) $row[$month]) / $den) * 100;
            }
        }

        return ['success'=>true,'periodo'=>$periodo,'tasa_part'=>$tasaPart,'tasa_renta'=>$tasaRenta,'rows'=>$rows, 'SUCCESS'=>true, 'ROWS'=>$rows];
    }

    private function loadDetails(int $periodo, array $template): array
    {
        $byTableCodes = [];
        $signByCode = [];
        foreach ($template as $meta) {
            if (($meta['TYPE'] ?? '') !== 'DETAIL') {
                continue;
            }
            $table = (string) ($meta['SOURCE_TABLE'] ?? '');
            $code = (string) ($meta['CODE'] ?? '');
            if ($table === '' || $code === '') {
                continue;
            }
            $byTableCodes[$table][] = $code;
            $signByCode[$code] = (int) ($meta['SIGN'] ?? 1);
        }

        $detail = [];
        foreach ($byTableCodes as $table => $codes) {
            $data = $this->queryDetailTable($table, $periodo, array_values(array_unique($codes)));
            foreach ($data as $code => $months) {
                $values = $this->zeroMonths();
                $sign = $signByCode[$code] ?? 1;
                foreach (self::MONTHS as $month) {
                    $values[$month] = ((float) ($months[$month] ?? 0.0)) * $sign;
                }
                $detail[$code] = $values;
            }
            foreach ($codes as $code) {
                $detail[$code] ??= $this->zeroMonths();
            }
        }

        return $detail;
    }

    private function queryDetailTable(string $table, int $periodo, array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $sql = "SELECT CODIGO, " . implode(', ', array_map(fn($m) => 'COALESCE(' . self::MONTH_TO_DB[$m] . ', 0) AS ' . $m, self::MONTHS)) . " FROM {$table} WHERE ANIO = ? AND CODIGO IN ({$placeholders})";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$periodo], $codes));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $code = (string) ($row['CODIGO'] ?? '');
            if ($code === '') {
                continue;
            }
            $out[$code] = [];
            foreach (self::MONTHS as $month) {
                $out[$code][$month] = (float) ($row[$month] ?? 0.0);
            }
        }

        return $out;
    }

    private function subtotalFromDetails(string $prefix, array $detailByCode): array
    {
        $sum = $this->zeroMonths();
        foreach ($detailByCode as $code => $values) {
            if (str_starts_with($code, $prefix) && strlen($code) === 7) {
                foreach (self::MONTHS as $month) {
                    $sum[$month] += (float) ($values[$month] ?? 0.0);
                }
            }
        }
        return $sum;
    }

    private function resolveTotal(string $code, array $rowsByCode, array $detailByCode): array
    {
        $sum = $this->zeroMonths();
        $ranges = [
            '401' => ['40101','40190'],
            '501' => ['50101','50105'],
            '701' => ['70101','70102'],
            '80101' => ['8010101','8010125'],
            '70301' => ['7030101','7030125'],
            '90101' => ['9010101','9010125'],
        ];
        if (!isset($ranges[$code])) {
            return $sum;
        }
        [$start, $end] = $ranges[$code];
        foreach ($detailByCode as $detailCode => $values) {
            if ($detailCode >= $start && $detailCode <= $end) {
                foreach (self::MONTHS as $month) {
                    $sum[$month] += (float) ($values[$month] ?? 0.0);
                }
            }
        }
        if ($code === '401') {
            foreach ($rowsByCode as $rowCode => $values) {
                if (strlen($rowCode) === 5 && str_starts_with($rowCode, '401')) {
                    foreach (self::MONTHS as $month) {
                        $sum[$month] += (float) ($values[$month] ?? 0.0);
                    }
                }
            }
        }

        return $sum;
    }

    private function applyFormulaRows(array &$rowsByRow, float $tasaPart, float $tasaRenta): void
    {
        foreach (self::MONTHS as $month) {
            $totalIngresos = (float) ($rowsByRow[76][$month] ?? 0.0);
            $totalCosto = (float) ($rowsByRow[128][$month] ?? 0.0);
            $ganBruta = $totalIngresos + $totalCosto;
            $rowsByRow[130][$month] = $ganBruta;

            $totGasOp = (float) ($rowsByRow[255][$month] ?? 0.0);
            $resOperacion = $ganBruta + $totGasOp;
            $rowsByRow[257][$month] = $resOperacion;

            $resAntes = $resOperacion + (float) ($rowsByRow[285][$month] ?? 0.0) + (float) ($rowsByRow[328][$month] ?? 0.0) + (float) ($rowsByRow[356][$month] ?? 0.0);
            $rowsByRow[358][$month] = $resAntes;

            $part = $resAntes > 0 ? -($resAntes * $tasaPart) : 0.0;
            $rowsByRow[362][$month] = $part;

            $base = $resAntes + $part;
            $ir = $base > 0 ? -($base * $tasaRenta) : 0.0;
            $rowsByRow[364][$month] = $ir;

            $rowsByRow[366][$month] = $resAntes + $part + $ir;
        }
    }

    private function zeroMonths(): array
    {
        $row = [];
        foreach (self::MONTHS as $month) {
            $row[$month] = 0.0;
        }
        return $row;
    }

    private function emptyRow(int $rowNumber, ?string $code, string $desc, string $type): array
    {
        return ['ROW'=>$rowNumber,'CODE'=>$code,'DESC'=>$desc,'TYPE'=>$type] + $this->zeroMonths();
    }
}
