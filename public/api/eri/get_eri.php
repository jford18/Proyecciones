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
        $excelRow = 2;
        foreach ($rows as $row) {
            $data = [(string) ($row['CODE'] ?? ''), (string) ($row['DESCRIPCION'] ?? '')];
            $codigo = trim((string) ($row['CODE'] ?? ''));
            $mesTotal = 0.0;
            $realTotal = 0.0;

            foreach ($months as $month) {
                $mes = (float) ($row[$month] ?? 0);
                $real = (float) ($row['REAL_' . $month] ?? 0);
                $var = $real - $mes;
                $varPct = $mes == 0.0 ? ($real == 0.0 ? 0.0 : 100.0) : (($var / $mes) * 100);

                $data[] = $mes;
                $data[] = $real;
                $data[] = $var;
                $data[] = $varPct;
                $data[] = (float) ($row['REAL_' . $month . '_PCT'] ?? 0);

                $mesTotal += $mes;
                $realTotal += $real;
            }

            $varTotal = $realTotal - $mesTotal;
            $varPctTotal = $mesTotal == 0.0 ? ($realTotal == 0.0 ? 0.0 : 100.0) : (($varTotal / $mesTotal) * 100);
            $rowPctTotal = (float) ($row['REAL_PCT_TOTAL'] ?? 0.0);

            $data[] = $mesTotal;
            $data[] = $realTotal;
            $data[] = $varTotal;
            $data[] = $varPctTotal;
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
