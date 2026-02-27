<?php

declare(strict_types=1);

use App\controllers\AnexoController;
use App\controllers\DashboardController;
use App\controllers\ExcelImportController;
use App\controllers\ImportController;
use App\db\Db;
use App\repositories\AnexoRepo;
use App\repositories\FlujoRepo;
use App\repositories\ImportLogRepo;
use App\repositories\ProyectoRepo;
use App\services\ExcelAnexoImportService;
use App\services\FlujoGeneratorService;
use App\services\ImportTemplateCatalog;
use App\services\ExcelTemplateImportService;
use App\services\ExcelIngresosImportService;
use App\services\ExcelCostosImportService;
use App\services\ExcelOtrosIngresosImportService;
use App\services\ExcelOtrosEgresosImportService;
use App\services\ExcelGastosOperacionalesImportService;
use App\services\ExcelGastosFinancierosImportService;
use App\services\ExcelProduccionImportService;
use App\services\ExcelEeffRealesEriImportService;
use App\services\PgConsolidationService;
use App\services\WorkflowService;
use App\repositories\PresupuestoIngresosRepository;

session_start();
require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../src/config/config.php';
$pgMap = require __DIR__ . '/../src/config/pg_map.php';

$pdo = Db::pdo($config);
$anexoRepo = new AnexoRepo($pdo);
$logRepo = new ImportLogRepo($pdo);
$proyectoRepo = new ProyectoRepo($pdo);
$flujoRepo = new FlujoRepo($pdo);
$pgService = new PgConsolidationService($anexoRepo, __DIR__ . '/../var/cache', $pgMap);
$workflowService = new WorkflowService($anexoRepo, $logRepo, $pgService);
$flujoService = new FlujoGeneratorService($flujoRepo, $pgMap);
$importController = new ImportController(new ExcelAnexoImportService(), $anexoRepo, $logRepo, $proyectoRepo, $config['upload_dir']);
$presupuestoIngresosRepository = new PresupuestoIngresosRepository($pdo);
$excelImportController = new ExcelImportController(
    new ExcelTemplateImportService(),
    new ImportTemplateCatalog(),
    $config['upload_dir'],
    new ExcelIngresosImportService($presupuestoIngresosRepository),
    new ExcelCostosImportService($presupuestoIngresosRepository),
    new ExcelOtrosIngresosImportService($presupuestoIngresosRepository),
    new ExcelOtrosEgresosImportService($presupuestoIngresosRepository),
    new ExcelGastosOperacionalesImportService($presupuestoIngresosRepository),
    new ExcelGastosFinancierosImportService($presupuestoIngresosRepository),
    new ExcelProduccionImportService($presupuestoIngresosRepository),
    new ExcelEeffRealesEriImportService($presupuestoIngresosRepository),
    $presupuestoIngresosRepository
);
$anexoController = new AnexoController($anexoRepo);
$dashboardController = new DashboardController($workflowService);

$projectOptions = $proyectoRepo->listAll();
if ($projectOptions === []) {
    $proyectoRepo->createDefaultIfEmpty();
    $projectOptions = $proyectoRepo->listAll();
}
$firstProjectId = isset($projectOptions[0]['ID']) ? (int) $projectOptions[0]['ID'] : 0;
$activeProjectId = (int) ($_SESSION['active_project_id'] ?? $firstProjectId);
$activeTipo = in_array(($_GET['tipo'] ?? $_SESSION['active_tipo'] ?? 'PRESUPUESTO'), ['PRESUPUESTO', 'REAL'], true) ? (string) ($_GET['tipo'] ?? $_SESSION['active_tipo'] ?? 'PRESUPUESTO') : 'PRESUPUESTO';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$path = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}
function sendJsonResponse(array $payload, int $status = 200): never {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function handleImportApi(ExcelImportController $excelImportController, string $endpoint): void {
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $user = (string) ($_SESSION['user'] ?? 'local-user');

    try {
        if ($endpoint === 'templates' && $method === 'GET') {
            sendJsonResponse($excelImportController->templates());
        }
        if ($endpoint === 'validate' && $method === 'POST') {
            sendJsonResponse($excelImportController->validate($_POST, $_FILES, $user));
        }
        if ($endpoint === 'execute' && $method === 'POST') {
            sendJsonResponse($excelImportController->execute($_POST, $_FILES, $user));
        }
        if ($endpoint === 'logs' && $method === 'GET') {
            sendJsonResponse($excelImportController->logs((int) ($_GET['limit'] ?? 50)));
        }

        if ($endpoint === 'validar-eeff-reales-eri' && $method === 'POST') {
            $_POST['template_id'] = 'eeff_reales_eri';
            sendJsonResponse($excelImportController->validate($_POST, $_FILES, $user));
        }
        if ($endpoint === 'importar-eeff-reales-eri' && $method === 'POST') {
            $_POST['template_id'] = 'eeff_reales_eri';
            sendJsonResponse($excelImportController->execute($_POST, $_FILES, $user));
        }
        sendJsonResponse([
            'ok' => false,
            'message' => 'Endpoint no encontrado',
            'details' => ['endpoint' => $endpoint, 'method' => $method],
        ], 404);
    } catch (Throwable $e) {
        $status = ($endpoint === 'execute') ? 500 : 422;
        sendJsonResponse([
            'ok' => false,
            'message' => $e->getMessage(),
            'details' => ['endpoint' => $endpoint, 'method' => $method],
        ], $status);
    }
}

if (str_starts_with($path, '/import/')) {
    handleImportApi($excelImportController, basename($path));
}

if ($path === '/api/eri-real') {
    require __DIR__ . '/api/eri-real/index.php';
    exit;
}

if ($path === '/api/eri-real/upsert' && (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require __DIR__ . '/api/eri-real/upsert_handler.php';
    exit;
}

$route = (string) ($_GET['r'] ?? 'dashboard');
if (str_starts_with($route, 'import-excel/')) {
    handleImportApi($excelImportController, substr($route, strlen('import-excel/')) ?: '');
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($route === 'import-excel') {
    $user = (string) ($_SESSION['user'] ?? 'local-user');
    $action = isset($_GET['action']) ? (string) $_GET['action'] : null;
    if ($action === 'view_excel' || $action === 'view-excel') {
        $viewData = [
            'route' => $route,
            'flash' => $flash,
            'activeProjectId' => $activeProjectId,
            'projectOptions' => $projectOptions,
            'activeTipo' => $activeTipo,
            'importResult' => $_SESSION['import_result'] ?? null,
        ];
        unset($_SESSION['import_result']);
        $viewData['excelView'] = $excelImportController->viewExcelPage((string) ($_GET['tab'] ?? 'ingresos'), $activeTipo, isset($_GET['anio']) ? (int) $_GET['anio'] : null);
        $contentView = __DIR__ . '/../src/views/import_excel_view.php';
        require __DIR__ . '/../src/views/layout.php';
        exit;
    }
    $excelImportController->handleActionRequest($action, $user);
}

$_SESSION['active_tipo'] = $activeTipo;

function redirectTo(string $route, array $query = []): never {
    header('Location: ?' . http_build_query(array_merge(['r' => $route], $query)));
    exit;
}

try {
    if ($route === 'set-project' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION['active_project_id'] = (int) ($_POST['project_id'] ?? $firstProjectId);
        redirectTo((string) ($_POST['back_route'] ?? 'dashboard'), ['tipo' => $activeTipo]);
    }

    $importRoutes = ['import-gastos' => 'GASTOS', 'import-nomina' => 'NOMINA', 'import-cobranza' => 'COBRANZA', 'import-activos' => 'ACTIVOS'];
    if (isset($importRoutes[$route]) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $tipoAnexo = $importRoutes[$route];
        $result = $importController->importAnexo($tipoAnexo, $_POST, $_FILES);
        $_SESSION['active_project_id'] = (int) ($_POST['proyecto_id'] ?? $activeProjectId);
        $_SESSION['import_result'] = $result;
        $_SESSION['flash'] = ['type' => 'success', 'text' => $result['message']];
        redirectTo($route, ['tipo' => $activeTipo]);
    }

    if ($route === 'consolidar-pg' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $status = $workflowService->status($activeProjectId, $activeTipo);
        if (!$status['step1']['ok']) {
            throw new RuntimeException('Paso 2 bloqueado: primero importe anexos en Paso 1.');
        }
        $pg = $pgService->consolidate($activeProjectId, $activeTipo);
        $_SESSION['flash'] = ['type' => 'success', 'text' => 'PG consolidado correctamente.'];
        $_SESSION['pg_preview'] = $pg;
        $logRepo->insertLog($activeProjectId, '-', 'PG_CONSOLIDADO', 0, 'Consolidación PG ejecutada');
        redirectTo('consolidar-pg', ['tipo' => $activeTipo]);
    }

    if ($route === 'generar-flujo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $status = $workflowService->status($activeProjectId, $activeTipo);
        if (!$status['step1']['ok'] || !$status['step2']['ok']) {
            throw new RuntimeException('Paso 3 bloqueado: complete Paso 1 y Paso 2 antes de generar FLUJO.');
        }
        $pg = $pgService->load($activeProjectId, $activeTipo);
        if ($pg === null) {
            throw new RuntimeException('No existe consolidación PG para este proyecto/tipo.');
        }
        $count = $flujoService->generate($activeProjectId, $activeTipo, $pg);
        $logRepo->insertLog($activeProjectId, '-', 'FLUJO_GENERADO', $count, 'Generación de flujo final');
        $_SESSION['flash'] = ['type' => 'success', 'text' => "Flujo generado. Celdas actualizadas: {$count}."];
        redirectTo('flujo', ['tipo' => $activeTipo]);
    }
} catch (Throwable $e) {
    $_SESSION['flash'] = ['type' => 'error', 'text' => $e->getMessage()];
    redirectTo($route === '' ? 'dashboard' : $route, ['tipo' => $activeTipo]);
}

$activeProjectId = (int) ($_SESSION['active_project_id'] ?? $firstProjectId);
$viewData = [
    'route' => $route,
    'flash' => $flash,
    'activeProjectId' => $activeProjectId,
    'projectOptions' => $projectOptions,
    'activeTipo' => $activeTipo,
    'importResult' => $_SESSION['import_result'] ?? null,
];
unset($_SESSION['import_result']);

switch ($route) {
    case 'dashboard':
        $viewData['stats'] = $dashboardController->stats($activeProjectId, $activeTipo);
        $contentView = __DIR__ . '/../src/views/dashboard.php';
        break;
    case 'import-excel':
        $viewData['excelTemplates'] = $excelImportController->templates()['templates'];
        $viewData['excelValidationResult'] = $_SESSION['excel_validation_result'] ?? null;
        $viewData['excelExecutionResult'] = $_SESSION['excel_execution_result'] ?? null;
        unset($_SESSION['excel_validation_result'], $_SESSION['excel_execution_result']);
        $contentView = __DIR__ . '/../src/views/import_excel.php';
        break;
    case 'import-gastos': case 'import-nomina': case 'import-cobranza': case 'import-activos':
        $labels = ['import-gastos' => ['GASTOS', '1.1'], 'import-nomina' => ['NOMINA', '1.2'], 'import-cobranza' => ['COBRANZA', '1.3'], 'import-activos' => ['ACTIVOS', '1.4']];
        [$viewData['anexoTipo'], $viewData['stepLabel']] = $labels[$route];
        $contentView = __DIR__ . '/../src/views/import_anexo.php';
        break;
    case 'consolidar-pg':
        $viewData['pgPreview'] = $_SESSION['pg_preview'] ?? $pgService->load($activeProjectId, $activeTipo);
        unset($_SESSION['pg_preview']);
        $contentView = __DIR__ . '/../src/views/consolidar_pg.php';
        break;
    case 'generar-flujo':
        $contentView = __DIR__ . '/../src/views/generar_flujo.php';
        break;
    case 'flujo':
        $rows = $flujoRepo->report($activeProjectId, $activeTipo);
        $byLinea = [];
        foreach ($rows as $row) {
            $id = (int) $row['ID'];
            $byLinea[$id] ??= ['SECCION' => $row['SECCION'], 'NOMBRE' => $row['NOMBRE'], 'meses' => array_fill(1, 12, 0.0)];
            if ($row['MES'] !== null) {
                $byLinea[$id]['meses'][(int) $row['MES']] = (float) $row['VALOR'];
            }
        }
        $viewData['flujo'] = array_values($byLinea);
        $contentView = __DIR__ . '/../src/views/flujo.php';
        break;
    case 'anexos':
        $viewData['anexos'] = $anexoController->list($_GET + ['proyectoId' => $_GET['proyectoId'] ?? $activeProjectId]);
        $contentView = __DIR__ . '/../src/views/anexos.php';
        break;
    case 'eri':
        $viewData['eriDefaultYear'] = isset($_GET['anio']) ? (int) $_GET['anio'] : (int) date('Y');
        $contentView = __DIR__ . '/../src/views/eri.php';
        break;
    case 'history-imports':
        $viewData['logs'] = $logRepo->listRecent($activeProjectId, 100);
        $contentView = __DIR__ . '/../src/views/history_imports.php';
        break;
    case 'config':
        $contentView = __DIR__ . '/../src/views/config.php';
        break;
    default:
        redirectTo('dashboard', ['tipo' => $activeTipo]);
}

require __DIR__ . '/../src/views/layout.php';
