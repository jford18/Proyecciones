<?php

declare(strict_types=1);

use App\controllers\ExcelImportController;
use App\db\Db;
use App\repositories\PresupuestoIngresosRepository;
use App\services\ExcelCostosImportService;
use App\services\ExcelGastosOperacionalesImportService;
use App\services\ExcelGastosFinancierosImportService;
use App\services\ExcelProduccionImportService;
use App\services\ExcelOtrosIngresosImportService;
use App\services\ExcelOtrosEgresosImportService;
use App\services\ExcelIngresosImportService;
use App\services\ExcelTemplateImportService;
use App\services\ImportTemplateCatalog;

if (!function_exists('importApiSendJson')) {
    function importApiSendJson(array $payload, int $status = 200): never
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('importApiController')) {
    function importApiController(): ExcelImportController
    {
        require_once __DIR__ . '/../../vendor/autoload.php';
        $config = require __DIR__ . '/../../src/config/config.php';
        $pdo = Db::pdo($config);
        $repo = new PresupuestoIngresosRepository($pdo);

        return new ExcelImportController(
            new ExcelTemplateImportService(),
            new ImportTemplateCatalog(),
            $config['upload_dir'],
            new ExcelIngresosImportService($repo),
            new ExcelCostosImportService($repo),
            new ExcelOtrosIngresosImportService($repo),
            new ExcelOtrosEgresosImportService($repo),
            new ExcelGastosOperacionalesImportService($repo),
            new ExcelGastosFinancierosImportService($repo),
            new ExcelProduccionImportService($repo),
            $repo
        );
    }
}

if (!function_exists('importApiUser')) {
    function importApiUser(): string
    {
        return (string) ($_SESSION['user'] ?? 'local-user');
    }
}
