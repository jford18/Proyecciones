<?php

declare(strict_types=1);

use App\controllers\AnexoController;
use App\controllers\DashboardController;
use App\controllers\ImportController;
use App\db\Db;
use App\repositories\AnexoRepo;
use App\repositories\ImportLogRepo;
use App\services\AnexoMapeoService;
use App\services\ExcelAnexoImportService;

session_start();

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../src/config/config.php';

$pdo = Db::pdo($config);
$anexoRepo = new AnexoRepo($pdo);
$logRepo = new ImportLogRepo($pdo);
$mapeoService = new AnexoMapeoService($pdo);
$importService = new ExcelAnexoImportService($mapeoService);
$importController = new ImportController($importService, $anexoRepo, $logRepo, $config['upload_dir']);
$anexoController = new AnexoController($anexoRepo);
$dashboardController = new DashboardController($logRepo, $anexoRepo);

if (!isset($_SESSION['active_project_id'])) {
    $_SESSION['active_project_id'] = 1;
}

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
        $_SESSION['active_project_id'] = max(1, (int) ($_POST['project_id'] ?? 1));
        $_SESSION['flash'] = ['type' => 'info', 'text' => 'Proyecto activo actualizado.'];
        redirectTo((string) ($_POST['back_route'] ?? 'dashboard'));
    }

    if ($route === 'import-gastos' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $projectId = (int) ($_POST['proyecto_id'] ?? 0);
        if ($projectId < 1) {
            throw new RuntimeException('Proyecto inválido para importar GASTOS.');
        }

        $result = $importController->importGastos($projectId, $_FILES);
        $_SESSION['flash'] = ['type' => 'success', 'text' => (string) $result['message']];
        $_SESSION['import_result'] = $result;
        redirectTo('import-gastos');
    }

    if ($route === 'import-nomina' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $projectId = (int) ($_POST['proyecto_id'] ?? 0);
        if ($projectId < 1) {
            throw new RuntimeException('Proyecto inválido para importar NÓMINA.');
        }

        $result = $importController->importNomina($projectId, $_FILES);
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

$projectOptions = [1, 2, 3, 4, 5];
if (!in_array($activeProjectId, $projectOptions, true)) {
    $projectOptions[] = $activeProjectId;
    sort($projectOptions);
}

$viewData = [
    'route' => $route,
    'flash' => $flash,
    'activeProjectId' => $activeProjectId,
    'projectOptions' => $projectOptions,
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
