<?php

declare(strict_types=1);

use App\controllers\AnexoController;
use App\controllers\DashboardController;
use App\controllers\ExcelImportController;
use App\controllers\ImportController;
use App\controllers\ClientesController;
use App\db\Db;
use App\models\Cliente;
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
$clientesController = new ClientesController(new Cliente($pdo), __DIR__);

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

    if ($route === 'clientes/list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query('SELECT id, nombre_empresa FROM CLIENTES ORDER BY nombre_empresa');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $data = array_map(static fn (array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'nombre' => (string) ($row['nombre_empresa'] ?? ''),
        ], $rows);
        sendJsonResponse(['data' => $data]);
    }

    if ($route === 'clientes/crear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        sendJsonResponse($clientesController->crear($_POST, $_FILES));
    }

    if ($route === 'clientes/editar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        sendJsonResponse($clientesController->editar((int) ($_POST['id'] ?? 0), $_POST, $_FILES));
    }

    if ($route === 'clientes/eliminar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        sendJsonResponse($clientesController->eliminar((int) ($_POST['id'] ?? 0)));
    }

} catch (Throwable $e) {
    if (str_starts_with($route, 'clientes/')) {
        sendJsonResponse(['ok' => false, 'message' => $e->getMessage()], 422);
    }
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
    case 'eri':
    case 'eri_presupuesto':
    case 'eri_real':
        $viewData['eriDefaultYear'] = isset($_GET['anio']) ? (int) $_GET['anio'] : (int) date('Y');
        $viewData['eriMode'] = $route === 'eri' ? 'full' : ($route === 'eri_presupuesto' ? 'presupuesto' : 'real');
        $contentView = __DIR__ . '/../src/views/eri.php';
        break;
    case 'clientes':
        $viewData = array_merge($viewData, $clientesController->index());
        $contentView = __DIR__ . '/../src/views/clientes/index.php';
        break;
    default:
        http_response_code(404);
        $contentView = __DIR__ . '/../src/views/404.php';
}

require __DIR__ . '/../src/views/layout.php';
