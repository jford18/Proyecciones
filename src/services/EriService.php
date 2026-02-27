<?php

declare(strict_types=1);

namespace App\services;

use PDO;

class EriService
{
    private const MONTHS = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];
    private const MONTH_TO_DB = ['ENERO' => 'ENE', 'FEBRERO' => 'FEB', 'MARZO' => 'MAR', 'ABRIL' => 'ABR', 'MAYO' => 'MAY', 'JUNIO' => 'JUN', 'JULIO' => 'JUL', 'AGOSTO' => 'AGO', 'SEPTIEMBRE' => 'SEP', 'OCTUBRE' => 'OCT', 'NOVIEMBRE' => 'NOV', 'DICIEMBRE' => 'DIC'];
    private const RESULTADO_ANTES_COMPONENTS = [
        ['row' => 257, 'sign' => 1, 'section' => 'OPERACIÓN'],
        ['row' => 328, 'sign' => 1, 'section' => 'GASTOS FINANCIEROS'],
        ['row' => 356, 'sign' => 1, 'section' => 'OTROS EGRESOS'],
    ];

    public function __construct(private PDO $pdo) {}

    public function build(int $periodo, float $tasaPart = 0.15, float $tasaRenta = 0.25, string $tipoReal = 'REAL'): array
    {
        $template = $this->buildTemplate();
        usort($template, fn(array $a, array $b) => (int) $a['ROW'] <=> (int) $b['ROW']);

        ['values' => $detailByCode, 'descriptions' => $descByCode] = $this->loadDetails($periodo, $template);
        $realByCode = $this->loadImportedReals($periodo, $tipoReal);
        $rows = [];
        $rowsByCode = [];
        $rowsByRow = [];
        $matchedRealRows = 0;

        foreach ($template as $meta) {
            $values = $this->zeroMonths();
            $type = (string) $meta['TYPE'];
            $code = $meta['CODE'];
            $normalizedCode = is_string($code) ? $this->normalizeCodigo($code) : '';
            if ($type === 'DETAIL' && $normalizedCode !== '') {
                $values = $detailByCode[$normalizedCode] ?? $values;
            } elseif ($type === 'SUBTOTAL' && $normalizedCode !== '') {
                $values = $this->subtotalFromDetails($normalizedCode, $detailByCode);
            } elseif ($type === 'TOTAL' && $normalizedCode !== '') {
                $values = $this->resolveTotal($normalizedCode, (string) ($meta['SOURCE_TABLE'] ?? ''), $periodo, (int) ($meta['SIGN'] ?? 1), $detailByCode);
            }

            $rowDesc = (string) $meta['DESCRIPCION'];
            if ($type === 'DETAIL' && $normalizedCode !== '' && ($descByCode[$normalizedCode] ?? '') !== '') {
                $rowDesc = $descByCode[$normalizedCode];
            }

            $row = [
                'ROW' => (int) $meta['ROW'],
                'CODE' => is_string($code) ? $normalizedCode : $code,
                'DESCRIPCION' => $rowDesc,
                'TYPE' => $type,
            ] + $values;

            $realValues = $this->zeroRealMonths();
            if ($type === 'DETAIL' && $normalizedCode !== '' && isset($realByCode[$normalizedCode])) {
                $real = $realByCode[$normalizedCode];
                foreach (self::MONTHS as $month) {
                    $realValues['REAL_' . $month] = (float) ($real[$month] ?? 0.0);
                }
                $realValues['REAL_TOTAL'] = (float) ($real['TOTAL'] ?? 0.0);
                $matchedRealRows++;
            }
            if ($type === 'DETAIL' && $normalizedCode !== '') {
                error_log('[ERI_MATCH] codigo=' . $normalizedCode
                    . ' base=' . json_encode($detailByCode[$normalizedCode] ?? [])
                    . ' real=' . json_encode($realByCode[$normalizedCode] ?? []));
            }
            $row = array_merge($row, $realValues);

            $rows[] = $row;
            $rowsByRow[(int) $row['ROW']] = $row;
            if ($normalizedCode !== '') {
                $rowsByCode[$normalizedCode] = $values;
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

        $this->logRealSampleDebug($periodo, $realByCode);
        error_log(sprintf('[ERI][REAL] anio=%d tipo=%s rows=%d match_real=%d', $periodo, strtoupper(trim($tipoReal)), count($rows), $matchedRealRows));

        return ['success' => true, 'periodo' => $periodo, 'tasa_part' => $tasaPart, 'tasa_renta' => $tasaRenta, 'rows' => $rows, 'SUCCESS' => true, 'ROWS' => $rows];
    }

    public function buildResultadoAntesDesglose(int $periodo, float $tasaPart = 0.15, float $tasaRenta = 0.25): array
    {
        $payload = $this->build($periodo, $tasaPart, $tasaRenta);
        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $rowsByRow = [];
        foreach ($rows as $row) {
            $rowNumber = (int) ($row['ROW'] ?? 0);
            if ($rowNumber > 0) {
                $rowsByRow[$rowNumber] = $row;
            }
        }

        $items = [];
        $total = 0.0;
        foreach (self::RESULTADO_ANTES_COMPONENTS as $component) {
            $rowNumber = (int) ($component['row'] ?? 0);
            $sign = ((int) ($component['sign'] ?? 1)) >= 0 ? '+' : '-';
            $source = $rowsByRow[$rowNumber] ?? [];
            $value = $this->sumMonths($source);
            $signedValue = $sign === '+' ? $value : -$value;
            $total += $signedValue;

            $items[] = [
                'row' => $rowNumber,
                'codigo' => (string) ($source['CODE'] ?? ''),
                'nombre' => (string) ($source['DESCRIPCION'] ?? ''),
                'valor' => $signedValue,
                'valor_base' => $value,
                'signo' => $sign,
                'seccion' => (string) ($component['section'] ?? ''),
                'acumulado' => $total,
            ];
        }

        return [
            'ok' => true,
            'periodo' => $periodo,
            'total' => $total,
            'items' => $items,
        ];
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
            $normalizedCode = $this->normalizeCodigo($code);
            if ($normalizedCode === '') {
                continue;
            }
            $byTableCodes[$table][] = $normalizedCode;
            $signByCode[$normalizedCode] = (int) ($meta['SIGN'] ?? 1);
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
        $monthSelect = implode(', ', array_map(fn($m) => 'COALESCE(A.' . self::MONTH_TO_DB[$m] . ', 0) AS ' . $m, self::MONTHS));
        $sql = "SELECT TRIM(CAST(A.CODIGO AS CHAR)) AS CODIGO, COALESCE(A.NOMBRE_CUENTA, '') AS DESCRIPCION, {$monthSelect}\n"
            . "FROM {$table} A\n"
            . "WHERE A.ANIO = ? AND TRIM(CAST(A.CODIGO AS CHAR)) IN ({$placeholders})";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$periodo], $codes));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $code = $this->normalizeCodigo((string) ($row['CODIGO'] ?? ''));
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

    private function loadImportedReals(int $periodo, string $tipoReal): array
    {
        $tipoNormalized = strtoupper(trim($tipoReal));
        $monthSelect = implode(', ', array_map(function (string $month): string {
            return 'COALESCE(B.' . $month . ', C.' . $month . ', 0) AS ' . $month;
        }, self::MONTHS));
        $monthSelectByType = implode(', ', array_map(function (string $month): string {
            return 'MAX(COALESCE(' . $month . ', 0)) AS ' . $month;
        }, self::MONTHS));

        $sql = "SELECT A.CODIGO, {$monthSelect}, COALESCE(B.TOTAL, C.TOTAL, 0) AS TOTAL "
            . 'FROM ( '
            . 'SELECT TRIM(CAST(CODIGO AS CHAR)) AS CODIGO '
            . 'FROM eeff_reales_eri_import '
            . 'WHERE ANIO = ? '
            . 'GROUP BY TRIM(CAST(CODIGO AS CHAR)) '
            . ') A '
            . 'LEFT JOIN ( '
            . "SELECT TRIM(CAST(CODIGO AS CHAR)) AS CODIGO, {$monthSelectByType}, MAX(COALESCE(TOTAL, 0)) AS TOTAL "
            . 'FROM eeff_reales_eri_import '
            . 'WHERE ANIO = ? AND UPPER(TRIM(COALESCE(TIPO, ""))) = ? '
            . 'GROUP BY TRIM(CAST(CODIGO AS CHAR)) '
            . ') B ON TRIM(CAST(B.CODIGO AS CHAR)) = TRIM(CAST(A.CODIGO AS CHAR)) '
            . 'LEFT JOIN ( '
            . "SELECT TRIM(CAST(CODIGO AS CHAR)) AS CODIGO, {$monthSelectByType}, MAX(COALESCE(TOTAL, 0)) AS TOTAL "
            . 'FROM eeff_reales_eri_import '
            . 'WHERE ANIO = ? AND UPPER(TRIM(COALESCE(TIPO, ""))) = "PRESUPUESTO" '
            . 'GROUP BY TRIM(CAST(CODIGO AS CHAR)) '
            . ') C ON TRIM(CAST(C.CODIGO AS CHAR)) = TRIM(CAST(A.CODIGO AS CHAR))';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$periodo, $periodo, $tipoNormalized, $periodo]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $code = $this->normalizeCodigo((string) ($row['CODIGO'] ?? ''));
            if ($code === '') {
                continue;
            }
            $out[$code] = ['TOTAL' => (float) ($row['TOTAL'] ?? 0.0)];
            foreach (self::MONTHS as $month) {
                $out[$code][$month] = (float) ($row[$month] ?? 0.0);
            }
        }

        return $out;
    }


    private function normalizeCodigo(string $codigo): string
    {
        return trim((string) $codigo);
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

    private function resolveTotal(string $code, string $sourceTable, int $periodo, int $sign, array $detailByCode): array
    {
        $sum = $this->zeroMonths();
        foreach ($detailByCode as $detailCode => $values) {
            $detailCodeStr = (string) ($detailCode ?? '');
            if ($detailCodeStr === '' || !str_starts_with($detailCodeStr, $code) || strlen($detailCodeStr) !== 7) {
                continue;
            }
            foreach (self::MONTHS as $month) {
                $sum[$month] += (float) ($values[$month] ?? 0.0);
            }
        }

        if ($this->hasAnyMonthValue($sum)) {
            return $sum;
        }

        if ($sourceTable === '') {
            return $sum;
        }

        $fallback = $this->queryTotalFallbackFromSource($sourceTable, $periodo, $code);
        if (!$this->hasAnyMonthValue($fallback)) {
            return $sum;
        }

        foreach (self::MONTHS as $month) {
            $fallback[$month] = ((float) ($fallback[$month] ?? 0.0)) * $sign;
        }

        return $fallback;
    }

    private function queryTotalFallbackFromSource(string $table, int $periodo, string $code): array
    {
        $sum = $this->zeroMonths();
        $monthSelect = implode(', ', array_map(fn($m) => 'COALESCE(SUM(COALESCE(' . self::MONTH_TO_DB[$m] . ', 0)), 0) AS ' . $m, self::MONTHS));

        $sqlLeaf = "SELECT {$monthSelect}, COUNT(*) AS CNT FROM {$table} WHERE ANIO = ? AND CODIGO LIKE ? AND CHAR_LENGTH(CODIGO) = 7";
        $stmtLeaf = $this->pdo->prepare($sqlLeaf);
        $stmtLeaf->execute([$periodo, $code . '%']);
        $leaf = $stmtLeaf->fetch(PDO::FETCH_ASSOC) ?: [];

        if (((int) ($leaf['CNT'] ?? 0)) > 0) {
            foreach (self::MONTHS as $month) {
                $sum[$month] = (float) ($leaf[$month] ?? 0.0);
            }
            return $sum;
        }

        $sqlTotal = "SELECT {$monthSelect} FROM {$table} WHERE ANIO = ? AND CODIGO = ?";
        $stmtTotal = $this->pdo->prepare($sqlTotal);
        $stmtTotal->execute([$periodo, $code]);
        $total = $stmtTotal->fetch(PDO::FETCH_ASSOC) ?: [];

        foreach (self::MONTHS as $month) {
            $sum[$month] = (float) ($total[$month] ?? 0.0);
        }

        return $sum;
    }

    private function hasAnyMonthValue(array $values): bool
    {
        foreach (self::MONTHS as $month) {
            if (abs((float) ($values[$month] ?? 0.0)) > 0.000001) {
                return true;
            }
        }

        return false;
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

            $resAntes = 0.0;
            foreach (self::RESULTADO_ANTES_COMPONENTS as $component) {
                $rowNumber = (int) ($component['row'] ?? 0);
                $sign = (int) ($component['sign'] ?? 1);
                $resAntes += ((float) ($rowsByRow[$rowNumber][$month] ?? 0.0)) * $sign;
            }
            $rowsByRow[358][$month] = $resAntes;
            $this->logResultadoAntesDebug($month, $resOperacion, (float) ($rowsByRow[328][$month] ?? 0.0), (float) ($rowsByRow[356][$month] ?? 0.0), $resAntes);

            $part = $resAntes > 0 ? -round($resAntes * $tasaPart, 0) : 0.0;
            $rowsByRow[362][$month] = $part;

            $base = $resAntes + $part;
            $ir = $base > 0 ? -round($base * $tasaRenta, 0) : 0.0;
            $rowsByRow[364][$month] = $ir;

            $rowsByRow[366][$month] = $resAntes + $part + $ir;
        }
    }


    private function logResultadoAntesDebug(string $month, float $resultadoOperacion, float $totalGastosFinancieros, float $totalOtrosEgresos, float $resultadoAntes): void
    {
        $enabled = filter_var((string) getenv('ERI_DEBUG_RESULTADO_ANTES'), FILTER_VALIDATE_BOOL);
        if (!$enabled) {
            return;
        }

        error_log(sprintf(
            '[ERI][RESULTADO_ANTES][%s] ResultadoOperacion=%s TotalGastosFinancieros=%s TotalOtrosEgresos=%s ResultadoAntes=%s',
            $month,
            (string) $resultadoOperacion,
            (string) $totalGastosFinancieros,
            (string) $totalOtrosEgresos,
            (string) $resultadoAntes
        ));
    }

    private function sumMonths(array $row): float
    {
        $total = 0.0;
        foreach (self::MONTHS as $month) {
            $total += (float) ($row[$month] ?? 0.0);
        }

        return $total;
    }

    private function zeroMonths(): array
    {
        $row = [];
        foreach (self::MONTHS as $month) {
            $row[$month] = 0.0;
        }

        return $row;
    }


    private function zeroRealMonths(): array
    {
        $row = [];
        foreach (self::MONTHS as $month) {
            $row['REAL_' . $month] = 0.0;
        }
        $row['REAL_TOTAL'] = 0.0;

        return $row;
    }

    private function logRealSampleDebug(int $periodo, array $importRealByCode): void
    {
        $sampleCodes = ['4010101', '4010102', '4010103'];
        foreach ($sampleCodes as $code) {
            $realEnero = (float) ($importRealByCode[$code]['ENERO'] ?? 0.0);
            error_log(sprintf(
                '[ERI][REAL_IMPORT] anio=%d codigo=%s real_enero=%s',
                $periodo,
                $code,
                (string) $realEnero
            ));
        }
    }

    private function emptyRow(int $rowNumber, ?string $code, string $desc, string $type): array
    {
        return ['ROW' => $rowNumber, 'CODE' => $code, 'DESCRIPCION' => $desc, 'TYPE' => $type] + $this->zeroMonths();
    }
}
