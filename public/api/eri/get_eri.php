<?php

declare(strict_types=1);

use App\db\Db;
use App\services\EriService;
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
            $headers[] = '%';
        }
        $sheet->fromArray($headers, null, 'A1');

        $excelRow = 2;
        foreach ($payload['rows'] as $row) {
            $data = [(string) ($row['CODE'] ?? ''), (string) ($row['DESCRIPCION'] ?? '')];
            foreach ($months as $month) {
                $data[] = (float) ($row[$month] ?? 0);
                $data[] = (float) ($row[$month . '_PCT'] ?? 0);
            }
            $sheet->fromArray($data, null, 'A' . $excelRow);
            $excelRow++;
        }

        $sheet->getStyle('A1:Z1')->getFont()->setBold(true);
        foreach (range('A', 'Z') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
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
