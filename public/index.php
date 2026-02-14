<?php

declare(strict_types=1);

use App\controllers\AnexoController;
use App\controllers\DashboardController;
use App\controllers\FileController;
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
$importController = new ImportController($importService, $anexoRepo, $logRepo);
$anexoController = new AnexoController($anexoRepo);
$dashboardController = new DashboardController($logRepo, $anexoRepo);
$fileController = new FileController($config['upload_dir']);

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

    if ($route === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = $importController->uploadExcel($_FILES, $config);
        if (($result['ok'] ?? false) === true) {
            $fileController->registerUploadedFile((string) $result['path']);
            $_SESSION['active_file'] = $result['path'];
            $_SESSION['flash'] = ['type' => 'success', 'text' => 'Archivo cargado y seleccionado.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'text' => (string) ($result['message'] ?? 'No se pudo subir el archivo.')];
        }

        redirectTo('upload');
    }

    if ($route === 'select-file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $selected = $fileController->selectFile((string) ($_POST['path'] ?? ''));
        if ($selected !== null) {
            $_SESSION['active_file'] = $selected['path'];
            $_SESSION['flash'] = ['type' => 'success', 'text' => 'Archivo activo actualizado.'];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'text' => 'No se encontró el archivo seleccionado.'];
        }

        redirectTo((string) ($_POST['back_route'] ?? 'files'));
    }

    if ($route === 'import-gastos' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $activeFile = (string) ($_SESSION['active_file'] ?? '');
        if ($activeFile === '') {
            throw new RuntimeException('Primero sube o selecciona un Excel.');
        }

        $projectId = (int) $_SESSION['active_project_id'];
        $result = $importController->importGastos($projectId, $activeFile);
        $_SESSION['flash'] = ['type' => 'success', 'text' => (string) $result['message']];
        $_SESSION['import_result'] = $result + ['type' => 'GASTOS'];
        redirectTo('import-gastos');
    }

    if ($route === 'import-nomina' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $activeFile = (string) ($_SESSION['active_file'] ?? '');
        if ($activeFile === '') {
            throw new RuntimeException('Primero sube o selecciona un Excel.');
        }

        $projectId = (int) $_SESSION['active_project_id'];
        $result = $importController->importNomina($projectId, $activeFile);
        $_SESSION['flash'] = ['type' => 'success', 'text' => (string) $result['message']];
        $_SESSION['import_result'] = $result + ['type' => 'NOMINA'];
        redirectTo('import-nomina');
    }
} catch (Throwable $e) {
    $_SESSION['flash'] = ['type' => 'error', 'text' => $e->getMessage()];
    redirectTo($route === '' ? 'dashboard' : $route);
}

$activeProjectId = (int) $_SESSION['active_project_id'];
$activeFile = $_SESSION['active_file'] ?? null;
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
    'activeFile' => $activeFile,
    'projectOptions' => $projectOptions,
    'importResult' => $importResult,
];

switch ($route) {
    case 'dashboard':
        $viewData['stats'] = $dashboardController->stats($activeProjectId);
        $contentView = __DIR__ . '/../src/views/dashboard.php';
        break;
    case 'upload':
        $contentView = __DIR__ . '/../src/views/upload.php';
        break;
    case 'files':
        $viewData['files'] = $fileController->listFiles();
        $contentView = __DIR__ . '/../src/views/files.php';
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
    case 'config':
        $viewData['files'] = $fileController->listFiles();
        $contentView = __DIR__ . '/../src/views/config.php';
        break;
    default:
        redirectTo('dashboard');
}

require __DIR__ . '/../src/views/layout.php';
