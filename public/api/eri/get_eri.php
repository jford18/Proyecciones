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

$anio = (int) ($_REQUEST['ANIO'] ?? date('Y'));
$tasaParticipacion = (float) ($_REQUEST['TASA_PARTICIPACION'] ?? 0.15);
$tasaRenta = (float) ($_REQUEST['TASA_RENTA'] ?? 0.25);
$tipo = (string) ($_REQUEST['TIPO'] ?? 'PRESUPUESTO');
$format = (string) ($_REQUEST['format'] ?? 'json');

try {
    $payload = $service->build($anio, $tasaParticipacion, $tasaRenta, $tipo);

    if ($format === 'xlsx') {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('ERI');

        $headers = ['CUENTA / DETALLE', 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE', 'TOTAL'];
        $sheet->fromArray($headers, null, 'A1');

        $rowNumber = 2;
        foreach ($payload['ROWS'] as $row) {
            $sheet->setCellValue('A' . $rowNumber, $row['LABEL']);
            if (($row['TYPE'] ?? '') !== 'HEADER') {
                $months = $row['M'] ?? [];
                $col = 'B';
                foreach (['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO','JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'] as $month) {
                    $sheet->setCellValue($col . $rowNumber, (float) ($months[$month] ?? 0));
                    $col++;
                }
                $sheet->setCellValue('N' . $rowNumber, (float) ($row['TOTAL'] ?? 0));
            }
            $rowNumber++;
        }

        $sheet->getStyle('A1:N1')->getFont()->setBold(true);
        $sheet->getStyle('A1:N1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B2:N' . ($rowNumber - 1))->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');
        foreach (range('A', 'N') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="ERI_' . $anio . '.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'SUCCESS' => false,
        'MESSAGE' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
