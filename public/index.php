<?php

declare(strict_types=1);

use App\controllers\AnexoController;
use App\controllers\DashboardController;
use App\controllers\ImportController;
use App\db\Db;
use App\repositories\AnexoRepo;
use App\repositories\ImportLogRepo;
use App\repositories\ProyectoRepo;
use App\services\AnexoMapeoService;
use App\services\ExcelAnexoImportService;

session_start();

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../src/config/config.php';

$pdo = Db::pdo($config);
$anexoRepo = new AnexoRepo($pdo);
$logRepo = new ImportLogRepo($pdo);
$proyectoRepo = new ProyectoRepo($pdo);
$mapeoService = new AnexoMapeoService($pdo);
$importService = new ExcelAnexoImportService($mapeoService);
$importController = new ImportController($importService, $anexoRepo, $logRepo, $proyectoRepo, $config['upload_dir']);
$anexoController = new AnexoController($anexoRepo);
$dashboardController = new DashboardController($logRepo, $anexoRepo);

$projectOptions = $proyectoRepo->listAll();
if ($projectOptions === []) {
    $defaultProjectId = $proyectoRepo->createDefaultIfEmpty();
    $_SESSION['flash'] = ['type' => 'info', 'text' => "Se creó el proyecto por defecto (ID={$defaultProjectId})."];
    $projectOptions = $proyectoRepo->listAll();
}

$firstProjectId = isset($projectOptions[0]['ID']) ? (int) $projectOptions[0]['ID'] : 0;
$activeProjectId = (int) ($_SESSION['active_project_id'] ?? 0);
if ($activeProjectId < 1 || $proyectoRepo->findById($activeProjectId) === null) {
    $activeProjectId = $firstProjectId;
}
$_SESSION['active_project_id'] = $activeProjectId;

$route = $_GET['r'] ?? 'dashboard';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function redirectTo(string $route, array $query = []): never
{
    $params = array_merge(['r' => $route], $query);
    header('Location: ?' . http_build_query($params));
    exit;
}

try {
    if ($route === 'set-project' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $selectedProjectId = (int) ($_POST['project_id'] ?? 0);
        $selectedProject = $proyectoRepo->findById($selectedProjectId);
        if ($selectedProject === null) {
            $_SESSION['active_project_id'] = $firstProjectId;
            $_SESSION['flash'] = ['type' => 'error', 'text' => "Proyecto no existe (ID={$selectedProjectId}). Se seleccionó un proyecto válido."];
            redirectTo((string) ($_POST['back_route'] ?? 'dashboard'));
        }

        $_SESSION['active_project_id'] = $selectedProjectId;
        $_SESSION['flash'] = ['type' => 'info', 'text' => 'Proyecto activo actualizado.'];
        redirectTo((string) ($_POST['back_route'] ?? 'dashboard'));
    }

    if ($route === 'import-gastos' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = $importController->importGastos($_POST, $_FILES);
        $_SESSION['active_project_id'] = (int) ($_POST['proyecto_id'] ?? $_SESSION['active_project_id']);
        $_SESSION['flash'] = ['type' => 'success', 'text' => (string) $result['message']];
        $_SESSION['import_result'] = $result;
        redirectTo('import-gastos');
    }

    if ($route === 'import-nomina' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $projectId = (int) ($_POST['proyecto_id'] ?? 0);
        if ($projectId < 1 || $proyectoRepo->findById($projectId) === null) {
            throw new RuntimeException('Proyecto inválido para importar NÓMINA.');
        }

        $result = $importController->importNomina($projectId, $_FILES);
        $_SESSION['active_project_id'] = $projectId;
        $_SESSION['flash'] = ['type' => 'success', 'text' => (string) $result['message']];
        $_SESSION['import_result'] = $result;
        redirectTo('import-nomina');
    }
} catch (Throwable $e) {
    $_SESSION['flash'] = ['type' => 'error', 'text' => $e->getMessage()];
    redirectTo($route === '' ? 'dashboard' : $route);
}

$activeProjectId = (int) $_SESSION['active_project_id'];
$importResult = $_SESSION['import_result'] ?? null;
unset($_SESSION['import_result']);
$activeProject = $proyectoRepo->findById($activeProjectId);
$hasProjects = $projectOptions !== [];

$viewData = [
    'route' => $route,
    'flash' => $flash,
    'activeProjectId' => $activeProjectId,
    'activeProject' => $activeProject,
    'projectOptions' => $projectOptions,
    'hasProjects' => $hasProjects,
    'importResult' => $importResult,
];

switch ($route) {
    case 'dashboard':
        $viewData['stats'] = $dashboardController->stats($activeProjectId);
        $contentView = __DIR__ . '/../src/views/dashboard.php';
        break;
    case 'import-gastos':
        $contentView = __DIR__ . '/../src/views/import_gastos.php';
        break;
    case 'import-nomina':
        $contentView = __DIR__ . '/../src/views/import_nomina.php';
        break;
    case 'anexos':
        $viewData['anexos'] = $anexoController->list($_GET + ['proyectoId' => $_GET['proyectoId'] ?? $activeProjectId]);
        $contentView = __DIR__ . '/../src/views/anexos.php';
        break;
    case 'history-imports':
        $viewData['logs'] = $logRepo->listRecent($activeProjectId, 100);
        $contentView = __DIR__ . '/../src/views/history_imports.php';
        break;
    case 'config':
        $contentView = __DIR__ . '/../src/views/config.php';
        break;
    default:
        redirectTo('dashboard');
}

require __DIR__ . '/../src/views/layout.php';
