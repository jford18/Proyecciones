<?php

declare(strict_types=1);

namespace App\services;

use PDO;

class EriService
{
    private const MONTHS = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];
    private const MONTH_TO_DB = ['ENERO' => 'ENE', 'FEBRERO' => 'FEB', 'MARZO' => 'MAR', 'ABRIL' => 'ABR', 'MAYO' => 'MAY', 'JUNIO' => 'JUN', 'JULIO' => 'JUL', 'AGOSTO' => 'AGO', 'SEPTIEMBRE' => 'SEP', 'OCTUBRE' => 'OCT', 'NOVIEMBRE' => 'NOV', 'DICIEMBRE' => 'DIC'];

    public function __construct(private PDO $pdo) {}

    public function build(int $periodo, float $tasaPart = 0.15, float $tasaRenta = 0.25): array
    {
        $template = $this->buildTemplate();
        usort($template, fn(array $a, array $b) => (int) $a['ROW'] <=> (int) $b['ROW']);

        ['values' => $detailByCode, 'descriptions' => $descByCode] = $this->loadDetails($periodo, $template);
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
                $values = $this->subtotalFromDetails($code, $detailByCode);
            } elseif ($type === 'TOTAL' && is_string($code)) {
                $values = $this->resolveTotal($code, $detailByCode);
            }

            $rowDesc = (string) $meta['DESCRIPCION'];
            if ($type === 'DETAIL' && is_string($code) && ($descByCode[$code] ?? '') !== '') {
                $rowDesc = $descByCode[$code];
            }

            $row = [
                'ROW' => (int) $meta['ROW'],
                'CODE' => $code,
                'DESCRIPCION' => $rowDesc,
                'TYPE' => $type,
            ] + $values;

            $rows[] = $row;
            $rowsByRow[(int) $row['ROW']] = $row;
            if (is_string($code) && $code !== '') {
                $rowsByCode[$code] = $values;
            }
        }

        $this->applyFormulaRows($rowsByRow, $tasaPart, $tasaRenta);

        foreach ($rows as &$row) {
            $rowNumber = (int) ($row['ROW'] ?? 0);
            if (isset($rowsByRow[$rowNumber])) {
                foreach (self::MONTHS as $month) {
                    $row[$month] = (float) ($rowsByRow[$rowNumber][$month] ?? 0.0);
                }
            }
        }
        unset($row);

        $totalIngresos = $rowsByRow[76] ?? $this->emptyRow(76, '401', 'TOTAL INGRESOS DE ACTIVIDADES ORDINARIAS', 'TOTAL');
        foreach ($rows as &$row) {
            foreach (self::MONTHS as $month) {
                $den = (float) ($totalIngresos[$month] ?? 0.0);
                $row[$month . '_PCT'] = $den == 0.0 ? 0.0 : (((float) $row[$month]) / $den) * 100;
            }
        }
        unset($row);

        return ['success' => true, 'periodo' => $periodo, 'tasa_part' => $tasaPart, 'tasa_renta' => $tasaRenta, 'rows' => $rows, 'SUCCESS' => true, 'ROWS' => $rows];
    }

    private function buildTemplate(): array
    {
        $rows = [];

        $rows[] = ['ROW' => 5, 'CODE' => '401', 'DESCRIPCION' => 'A.  INGRESOS DE ACTIVIDADES ORDINARIAS', 'TYPE' => 'HEADER', 'SOURCE_TABLE' => 'PRESUPUESTO_INGRESOS', 'SIGN' => 1];
        $this->appendRangeBlock($rows, 6, '4010101', '4010112', '40101', '    Venta de Bienes', 'PRESUPUESTO_INGRESOS', 1);
        $this->appendRangeBlock($rows, 19, '4010201', '4010212', '40102', '    Prestación de Servicios', 'PRESUPUESTO_INGRESOS', 1);
        $this->appendRangeBlock($rows, 32, '4010301', '4010310', '40103', '    Contratos de Construcción', 'PRESUPUESTO_INGRESOS', 1);
        $this->appendRangeBlock($rows, 43, '4010401', '4010405', '40104', '    Subvenciones del Gobierno', 'PRESUPUESTO_INGRESOS', 1);
        $this->appendRangeBlock($rows, 49, '4010501', '4010505', '40105', '    Regalías', 'PRESUPUESTO_INGRESOS', 1);
        $this->appendRangeBlock($rows, 55, '4010601', '4010606', '40106', '    Intereses', 'PRESUPUESTO_INGRESOS', 1);
        $this->appendRangeBlock($rows, 62, '4010701', '4010705', '40107', '    Dividendos', 'PRESUPUESTO_INGRESOS', 1);
        $this->appendRangeBlock($rows, 68, '4010801', '4010803', '40108', '    Ganancia por valor razonable', 'PRESUPUESTO_INGRESOS', 1);
        $this->appendRangeBlock($rows, 72, '4019001', '4019003', '40190', '    Descuentos y Rebajas', 'PRESUPUESTO_INGRESOS', 1);
        $rows[] = ['ROW' => 76, 'CODE' => '401', 'DESCRIPCION' => 'TOTAL INGRESOS DE ACTIVIDADES ORDINARIAS', 'TYPE' => 'TOTAL', 'SOURCE_TABLE' => 'PRESUPUESTO_INGRESOS', 'SIGN' => 1];

        $rows[] = ['ROW' => 77, 'CODE' => '501', 'DESCRIPCION' => 'B. COSTO DE VENTAS', 'TYPE' => 'HEADER', 'SOURCE_TABLE' => 'PRESUPUESTO_COSTOS', 'SIGN' => -1];
        $this->appendRangeBlock($rows, 78, '5010101', '5010112', '50101', '    Costo de Venta de Bienes', 'PRESUPUESTO_COSTOS', -1);
        $this->appendRangeBlock($rows, 91, '5010201', '5010212', '50102', '    Costo de Prestación de Servicios', 'PRESUPUESTO_COSTOS', -1);
        $this->appendRangeBlock($rows, 104, '5010301', '5010310', '50103', '    Contratos de Construcción', 'PRESUPUESTO_COSTOS', -1);
        $this->appendRangeBlock($rows, 115, '5010401', '5010405', '50104', '    Subvenciones del Gobierno', 'PRESUPUESTO_COSTOS', -1);
        $this->appendRangeBlock($rows, 121, '5010501', '5010505', '50105', '    Regalías', 'PRESUPUESTO_COSTOS', -1);
        $rows[] = ['ROW' => 128, 'CODE' => '501', 'DESCRIPCION' => 'TOTAL COSTO DE VENTAS', 'TYPE' => 'TOTAL', 'SOURCE_TABLE' => 'PRESUPUESTO_COSTOS', 'SIGN' => -1];
        $rows[] = ['ROW' => 130, 'CODE' => null, 'DESCRIPCION' => 'GANANCIA BRUTA', 'TYPE' => 'RESULT', 'SOURCE_TABLE' => null, 'SIGN' => 1];

        $rows[] = ['ROW' => 131, 'CODE' => '701', 'DESCRIPCION' => 'C. GASTOS OPERACIONALES', 'TYPE' => 'HEADER', 'SOURCE_TABLE' => 'PRESUPUESTO_GASTOS_OPERACIONALES', 'SIGN' => -1];
        $this->appendRangeBlock($rows, 132, '7010101', '7010160', '70101', '    Gastos Operacionales - Administración', 'PRESUPUESTO_GASTOS_OPERACIONALES', -1);
        $this->appendRangeBlock($rows, 193, '7010201', '7010260', '70102', '    Gastos Operacionales - Ventas', 'PRESUPUESTO_GASTOS_OPERACIONALES', -1);
        $rows[] = ['ROW' => 255, 'CODE' => '701', 'DESCRIPCION' => 'TOTAL GASTOS OPERACIONALES', 'TYPE' => 'TOTAL', 'SOURCE_TABLE' => 'PRESUPUESTO_GASTOS_OPERACIONALES', 'SIGN' => -1];
        $rows[] = ['ROW' => 257, 'CODE' => null, 'DESCRIPCION' => 'RESULTADO DE ACTIVIDADES DE OPERACIÓN', 'TYPE' => 'RESULT', 'SOURCE_TABLE' => null, 'SIGN' => 1];

        $rows[] = ['ROW' => 258, 'CODE' => '80101', 'DESCRIPCION' => 'D. OTROS INGRESOS', 'TYPE' => 'HEADER', 'SOURCE_TABLE' => 'PRESUPUESTO_OTROS_INGRESOS', 'SIGN' => 1];
        $this->appendRangeBlock($rows, 259, '8010101', '8010125', '80101', '    Otros Ingresos', 'PRESUPUESTO_OTROS_INGRESOS', 1);
        $rows[] = ['ROW' => 285, 'CODE' => '80101', 'DESCRIPCION' => 'TOTAL OTROS INGRESOS', 'TYPE' => 'TOTAL', 'SOURCE_TABLE' => 'PRESUPUESTO_OTROS_INGRESOS', 'SIGN' => 1];

        $rows[] = ['ROW' => 286, 'CODE' => '70301', 'DESCRIPCION' => 'E. GASTOS FINANCIEROS', 'TYPE' => 'HEADER', 'SOURCE_TABLE' => 'PRESUPUESTO_GASTOS_FINANCIEROS', 'SIGN' => -1];
        $this->appendRangeBlock($rows, 287, '7030101', '7030140', '70301', '    Gastos Financieros', 'PRESUPUESTO_GASTOS_FINANCIEROS', -1);
        $rows[] = ['ROW' => 328, 'CODE' => '70301', 'DESCRIPCION' => 'TOTAL GASTOS FINANCIEROS', 'TYPE' => 'TOTAL', 'SOURCE_TABLE' => 'PRESUPUESTO_GASTOS_FINANCIEROS', 'SIGN' => -1];

        $rows[] = ['ROW' => 329, 'CODE' => '90101', 'DESCRIPCION' => 'F. OTROS EGRESOS', 'TYPE' => 'HEADER', 'SOURCE_TABLE' => 'PRESUPUESTO_OTROS_EGRESOS', 'SIGN' => -1];
        $this->appendRangeBlock($rows, 330, '9010101', '9010125', '90101', '    Otros Egresos', 'PRESUPUESTO_OTROS_EGRESOS', -1);
        $rows[] = ['ROW' => 356, 'CODE' => '90101', 'DESCRIPCION' => 'TOTAL OTROS EGRESOS', 'TYPE' => 'TOTAL', 'SOURCE_TABLE' => 'PRESUPUESTO_OTROS_EGRESOS', 'SIGN' => -1];

        $rows[] = ['ROW' => 358, 'CODE' => null, 'DESCRIPCION' => 'RESULTADO ANTES DE PARTICIPACIÓN E IMPUESTOS', 'TYPE' => 'RESULT', 'SOURCE_TABLE' => null, 'SIGN' => 1];
        $rows[] = ['ROW' => 360, 'CODE' => null, 'DESCRIPCION' => 'IMPUESTOS Y PARTICIPACIÓN', 'TYPE' => 'HEADER', 'SOURCE_TABLE' => null, 'SIGN' => 1];
        $rows[] = ['ROW' => 362, 'CODE' => null, 'DESCRIPCION' => '(-) Participación a Trabajadores (15%)', 'TYPE' => 'CALC', 'SOURCE_TABLE' => null, 'SIGN' => -1];
        $rows[] = ['ROW' => 364, 'CODE' => null, 'DESCRIPCION' => '(-) Impuesto a la Renta Sociedades (25%)', 'TYPE' => 'CALC', 'SOURCE_TABLE' => null, 'SIGN' => -1];
        $rows[] = ['ROW' => 366, 'CODE' => null, 'DESCRIPCION' => 'RESULTADO DEL PERÍODO', 'TYPE' => 'RESULT_FINAL', 'SOURCE_TABLE' => null, 'SIGN' => 1];

        return $rows;
    }

    private function appendRangeBlock(array &$rows, int $startRow, string $startCode, string $endCode, string $subtotalCode, string $subtotalDesc, string $sourceTable, int $sign): void
    {
        $row = $startRow;
        for ($code = (int) $startCode; $code <= (int) $endCode; $code++) {
            $rows[] = ['ROW' => $row, 'CODE' => (string) $code, 'DESCRIPCION' => '', 'TYPE' => 'DETAIL', 'SOURCE_TABLE' => $sourceTable, 'SIGN' => $sign];
            $row++;
        }
        $rows[] = ['ROW' => $row, 'CODE' => $subtotalCode, 'DESCRIPCION' => $subtotalDesc, 'TYPE' => 'SUBTOTAL', 'SOURCE_TABLE' => $sourceTable, 'SIGN' => $sign];
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
        $descByCode = [];
        foreach ($byTableCodes as $table => $codes) {
            $data = $this->queryDetailTable($table, $periodo, array_values(array_unique($codes)));
            foreach ($data as $code => $dataRow) {
                $values = $this->zeroMonths();
                $sign = $signByCode[$code] ?? 1;
                foreach (self::MONTHS as $month) {
                    $values[$month] = ((float) ($dataRow[$month] ?? 0.0)) * $sign;
                }
                $detail[$code] = $values;
                $descByCode[$code] = trim((string) ($dataRow['DESCRIPCION'] ?? ''));
            }
            foreach ($codes as $code) {
                $detail[$code] ??= $this->zeroMonths();
            }
        }

        return ['values' => $detail, 'descriptions' => $descByCode];
    }

    private function queryDetailTable(string $table, int $periodo, array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $monthSelect = implode(', ', array_map(fn($m) => 'COALESCE(' . self::MONTH_TO_DB[$m] . ', 0) AS ' . $m, self::MONTHS));
        $sql = "SELECT CODIGO, COALESCE(NOMBRE_CUENTA, '') AS DESCRIPCION, {$monthSelect} FROM {$table} WHERE ANIO = ? AND CODIGO IN ({$placeholders})";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$periodo], $codes));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $code = (string) ($row['CODIGO'] ?? '');
            if ($code === '') {
                continue;
            }
            $out[$code] = ['DESCRIPCION' => (string) ($row['DESCRIPCION'] ?? '')];
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
            $codeStr = (string) ($code ?? '');
            if ($codeStr === '') {
                continue;
            }
            if (str_starts_with($codeStr, $prefix) && strlen($codeStr) === 7) {
                foreach (self::MONTHS as $month) {
                    $sum[$month] += (float) ($values[$month] ?? 0.0);
                }
            }
        }

        return $sum;
    }

    private function resolveTotal(string $code, array $detailByCode): array
    {
        $sum = $this->zeroMonths();
        $ranges = [
            '401' => ['4010101', '4019003'],
            '501' => ['5010101', '5010505'],
            '701' => ['7010101', '7010260'],
            '80101' => ['8010101', '8010125'],
            '70301' => ['7030101', '7030140'],
            '90101' => ['9010101', '9010125'],
        ];
        if (!isset($ranges[$code])) {
            return $sum;
        }

        [$start, $end] = $ranges[$code];
        foreach ($detailByCode as $detailCode => $values) {
            $detailCodeStr = (string) ($detailCode ?? '');
            if ($detailCodeStr === '') {
                continue;
            }
            if ($detailCodeStr >= $start && $detailCodeStr <= $end) {
                foreach (self::MONTHS as $month) {
                    $sum[$month] += (float) ($values[$month] ?? 0.0);
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
        return ['ROW' => $rowNumber, 'CODE' => $code, 'DESCRIPCION' => $desc, 'TYPE' => $type] + $this->zeroMonths();
    }
}
