<?php

declare(strict_types=1);

use App\db\Db;
use App\services\EriService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require __DIR__ . '/../../../vendor/autoload.php';

$config = require __DIR__ . '/../../../src/config/config.php';
$pdo = Db::pdo($config);
$service = new EriService($pdo);

$periodo = (int) ($_REQUEST['periodo'] ?? $_REQUEST['ANIO'] ?? date('Y'));
$tasaPart = (float) ($_REQUEST['tasa_part'] ?? $_REQUEST['TASA_PARTICIPACION'] ?? 0.15);
$tasaRenta = (float) ($_REQUEST['tasa_renta'] ?? $_REQUEST['TASA_RENTA'] ?? 0.25);
$format = (string) ($_REQUEST['format'] ?? 'json');
$tipoReal = (string) ($_REQUEST['tipo_real'] ?? $_REQUEST['tipo'] ?? 'REAL');
$months = ['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO','JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'];

try {
    $payload = $service->build($periodo, $tasaPart, $tasaRenta, $tipoReal);

    if ($format === 'xlsx') {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('ERI');
        $headers = ['CÓDIGO', 'DESCRIPCIÓN'];
        foreach ($months as $month) {
            $headers[] = $month;
            $headers[] = 'REAL';
            $headers[] = 'VARIACIÓN';
            $headers[] = '% VARIACIÓN';
            $headers[] = '%';
        }
        $headers[] = 'TOTAL';
        $headers[] = 'REAL TOTAL';
        $headers[] = 'VARIACIÓN TOTAL';
        $headers[] = 'VAR. % TOTAL';
        $headers[] = '% TOTAL';
        $sheet->fromArray($headers, null, 'A1');

        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $rowTotals = [];
        foreach ($rows as $index => $row) {
            $total = 0.0;
            foreach ($months as $month) {
                $total += (float) ($row[$month] ?? 0);
            }
            $rowTotals[$index] = $total;
        }

        $blockTotals = [];
        foreach ($rows as $index => $row) {
            $code = trim((string) ($row['CODE'] ?? ''));
            $block = substr($code, 0, 1);
            if (!preg_match('/[4-9]/', (string) $block)) {
                continue;
            }
            if (strtoupper((string) ($row['TYPE'] ?? '')) === 'TOTAL') {
                $blockTotals[$block] = (float) ($rowTotals[$index] ?? 0);
            }
        }

        foreach ($rows as $index => $row) {
            $code = trim((string) ($row['CODE'] ?? ''));
            $block = substr($code, 0, 1);
            if (!preg_match('/[4-9]/', (string) $block) || array_key_exists($block, $blockTotals)) {
                continue;
            }
            $sum = 0.0;
            foreach ($rows as $currentIndex => $current) {
                $currentCode = trim((string) ($current['CODE'] ?? ''));
                $sameBlock = substr($currentCode, 0, 1) === $block;
                $isDetail = strtoupper((string) ($current['TYPE'] ?? '')) === 'DETAIL';
                if ($sameBlock && $isDetail) {
                    $sum += (float) ($rowTotals[$currentIndex] ?? 0);
                }
            }
            $blockTotals[$block] = $sum;
        }

        $excelRow = 2;
        foreach ($rows as $row) {
            $data = [(string) ($row['CODE'] ?? ''), (string) ($row['DESCRIPCION'] ?? '')];
            $codigo = trim((string) ($row['CODE'] ?? ''));
            $isDetalle = strlen($codigo) >= 7;
            $mesTotal = 0.0;
            $realTotal = 0.0;

            foreach ($months as $month) {
                $mes = (float) ($row[$month] ?? 0);
                $real = $isDetalle ? (float) ($row['REAL_' . $month] ?? 0) : 0.0;
                $var = $real - $mes;
                $varPct = $mes == 0.0 ? ($real == 0.0 ? 0.0 : 100.0) : (($var / $mes) * 100);

                $data[] = $mes;
                $data[] = $isDetalle ? $real : null;
                $data[] = $isDetalle ? $var : null;
                $data[] = $isDetalle ? $varPct : null;
                $data[] = (float) ($row[$month . '_PCT'] ?? 0);

                $mesTotal += $mes;
                if ($isDetalle) {
                    $realTotal += $real;
                }
            }

            $varTotal = $realTotal - $mesTotal;
            $varPctTotal = $mesTotal == 0.0 ? ($realTotal == 0.0 ? 0.0 : 100.0) : (($varTotal / $mesTotal) * 100);
            $rowBlock = substr($codigo, 0, 1);
            $rowDenominator = preg_match('/[4-9]/', (string) $rowBlock) ? (float) ($blockTotals[$rowBlock] ?? 0) : 0.0;
            $rowPctTotal = $rowDenominator == 0.0 ? 0.0 : (($mesTotal / $rowDenominator) * 100);

            $data[] = $mesTotal;
            $data[] = $isDetalle ? $realTotal : null;
            $data[] = $isDetalle ? $varTotal : null;
            $data[] = $isDetalle ? $varPctTotal : null;
            $data[] = $rowPctTotal;

            $sheet->fromArray($data, null, 'A' . $excelRow);
            $excelRow++;
        }

        $highestColumn = $sheet->getHighestColumn();
        $sheet->getStyle('A1:' . $highestColumn . '1')->getFont()->setBold(true);
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
        for ($column = 1; $column <= $highestColumnIndex; $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="ERI_' . $periodo . '.xlsx"');
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
