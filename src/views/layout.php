<?php
/** @var string $contentView */
/** @var array $viewData */
extract($viewData);

$navItems = [
    'dashboard' => ['Dashboard', 'bi-speedometer2'],
    'upload' => ['Subir archivo', 'bi-upload'],
    'files' => ['Historial de archivos', 'bi-clock-history'],
    'import-gastos' => ['Importar GASTOS', 'bi-cash-stack'],
    'import-nomina' => ['Importar NÓMINA', 'bi-people'],
    'anexos' => ['Ver anexos', 'bi-table'],
    'config' => ['Configuración', 'bi-gear'],
];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Importador de Anexos</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar p-3">
    <h5 class="text-white mb-3">Proyecciones</h5>
    <div class="small text-uppercase text-secondary mb-2">Menú</div>
    <nav class="nav flex-column gap-1">
      <?php foreach ($navItems as $navRoute => [$label, $icon]): ?>
        <a class="nav-link sidebar-link <?= $route === $navRoute ? 'active' : '' ?>" href="?r=<?= $navRoute ?>"><i class="bi <?= $icon ?> me-2"></i><?= $label ?></a>
      <?php endforeach; ?>
    </nav>
  </aside>
  <main class="content-area">
    <header class="topbar d-flex flex-wrap align-items-center justify-content-between gap-2 p-3">
      <form method="post" action="?r=set-project" class="d-flex align-items-center gap-2 m-0">
        <input type="hidden" name="back_route" value="<?= htmlspecialchars((string) $route) ?>">
        <label class="small text-muted">Proyecto activo</label>
        <select class="form-select form-select-sm" name="project_id" onchange="this.form.submit()">
          <?php foreach ($projectOptions as $projectId): ?>
            <option value="<?= $projectId ?>" <?= $activeProjectId === $projectId ? 'selected' : '' ?>>Proyecto <?= $projectId ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <div class="d-flex align-items-center gap-2">
        <span class="small text-muted">Archivo activo:</span>
        <?php if ($activeFile): ?>
          <span class="badge text-bg-primary"><?= htmlspecialchars(basename((string) $activeFile)) ?></span>
        <?php else: ?>
          <span class="badge text-bg-warning">Sin archivo seleccionado</span>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="?r=files">Cambiar</a>
      </div>
    </header>

    <div class="container-fluid p-3">
      <?php if ($flash): ?>
        <?php $map = ['success' => 'success', 'error' => 'danger', 'info' => 'info']; ?>
        <div class="alert alert-<?= $map[$flash['type']] ?? 'secondary' ?>"><?= htmlspecialchars((string) $flash['text']) ?></div>
      <?php endif; ?>

      <?php include $contentView; ?>
    </div>
  </main>
</div>


<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Confirmar importación</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"></div>
    <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button id="confirmImportBtn" class="btn btn-primary">Continuar</button></div>
  </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
