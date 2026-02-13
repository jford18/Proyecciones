<?php

declare(strict_types=1);

use App\controllers\AnexoController;
use App\controllers\ImportController;
use App\db\Db;
use App\repositories\AnexoRepo;
use App\repositories\ImportLogRepo;
use App\services\AnexoMapeoService;
use App\services\ExcelAnexoImportService;

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../src/config/config.php';

$pdo = Db::pdo($config);
$anexoRepo = new AnexoRepo($pdo);
$logRepo = new ImportLogRepo($pdo);
$mapeoService = new AnexoMapeoService($pdo);
$importService = new ExcelAnexoImportService($mapeoService);
$importController = new ImportController($importService, $anexoRepo, $logRepo);
$anexoController = new AnexoController($anexoRepo);

$route = $_GET['r'] ?? '';
$message = null;
$error = null;

try {
    if ($route === 'upload-excel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = $importController->uploadExcel($_FILES, $config);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }

    if ($route === 'import-gastos' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = $importController->importGastos((int) ($_POST['proyectoId'] ?? 0), (string) ($_POST['path'] ?? ''));
        $message = $result['message'];
    }

    if ($route === 'import-nomina' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = $importController->importNomina((int) ($_POST['proyectoId'] ?? 0), (string) ($_POST['path'] ?? ''));
        $message = $result['message'];
    }

    if ($route === 'anexos') {
        header('Content-Type: application/json');
        echo json_encode($anexoController->list($_GET));
        exit;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$rows = $anexoController->list($_GET);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Importador de Anexos</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="container">
  <h1>Importador de Anexos</h1>

  <?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <section class="card">
    <h2>Subir Excel</h2>
    <form id="upload-form" enctype="multipart/form-data">
      <input type="file" name="excel" accept=".xlsx,.xls" required>
      <button type="submit">Subir Excel</button>
    </form>
    <p id="upload-result"></p>
  </section>

  <section class="card">
    <h2>Importar</h2>
    <form method="post" action="?r=import-gastos">
      <input type="number" name="proyectoId" placeholder="Proyecto ID" required>
      <input type="text" name="path" placeholder="Ruta archivo" required>
      <button type="submit">Importar GASTOS</button>
    </form>

    <form method="post" action="?r=import-nomina">
      <input type="number" name="proyectoId" placeholder="Proyecto ID" required>
      <input type="text" name="path" placeholder="Ruta archivo" required>
      <button type="submit">Importar NOMINA</button>
    </form>
  </section>

  <section class="card">
    <h2>Filtros</h2>
    <form method="get">
      <input type="hidden" name="r" value="home">
      <input type="number" name="proyectoId" placeholder="Proyecto ID" value="<?= htmlspecialchars((string) ($_GET['proyectoId'] ?? '')) ?>">
      <input type="text" name="tipoAnexo" placeholder="GASTOS/NOMINA" value="<?= htmlspecialchars((string) ($_GET['tipoAnexo'] ?? '')) ?>">
      <input type="text" name="tipo" placeholder="PRESUPUESTO/REAL" value="<?= htmlspecialchars((string) ($_GET['tipo'] ?? '')) ?>">
      <input type="number" name="mes" placeholder="Mes" min="1" max="12" value="<?= htmlspecialchars((string) ($_GET['mes'] ?? '')) ?>">
      <button type="submit">Aplicar filtros</button>
    </form>
  </section>

  <section class="card">
    <h2>Anexos importados</h2>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>ID</th><th>TIPO_ANEXO</th><th>TIPO</th><th>MES</th><th>PERIODO</th>
            <th>CODIGO</th><th>CONCEPTO</th><th>DESCRIPCION</th><th>VALOR</th><th>ORIGEN_HOJA</th><th>ORIGEN_FILA</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><?= htmlspecialchars((string) $row['ID']) ?></td>
            <td><?= htmlspecialchars((string) $row['TIPO_ANEXO']) ?></td>
            <td><?= htmlspecialchars((string) $row['TIPO']) ?></td>
            <td><?= htmlspecialchars((string) $row['MES']) ?></td>
            <td><?= htmlspecialchars((string) $row['PERIODO']) ?></td>
            <td><?= htmlspecialchars((string) $row['CODIGO']) ?></td>
            <td><?= htmlspecialchars((string) $row['CONCEPTO']) ?></td>
            <td><?= htmlspecialchars((string) $row['DESCRIPCION']) ?></td>
            <td><?= htmlspecialchars((string) $row['VALOR']) ?></td>
            <td><?= htmlspecialchars((string) $row['ORIGEN_HOJA']) ?></td>
            <td><?= htmlspecialchars((string) $row['ORIGEN_FILA']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
<script src="assets/app.js"></script>
</body>
</html>
