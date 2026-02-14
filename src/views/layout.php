<?php
/** @var string $contentView */
/** @var array $viewData */
extract($viewData);

$sections = [
    [
        'title' => null,
        'items' => [
            'dashboard' => ['Dashboard', 'bi-speedometer2'],
        ],
    ],
    [
        'title' => 'Importaciones',
        'items' => [
            'import-gastos' => ['Importar GASTOS', 'bi-cash-stack'],
            'import-nomina' => ['Importar NÓMINA', 'bi-people'],
        ],
    ],
    [
        'title' => 'Anexos',
        'items' => [
            'anexos' => ['Ver anexos', 'bi-table'],
        ],
    ],
    [
        'title' => null,
        'items' => [
            'history-imports' => ['Historial de importaciones', 'bi-clock-history'],
            'config' => ['Configuración', 'bi-gear'],
        ],
    ],
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
    <?php foreach ($sections as $section): ?>
      <?php if ($section['title']): ?>
        <div class="small text-uppercase text-secondary mt-3 mb-1"><?= $section['title'] ?></div>
      <?php endif; ?>
      <nav class="nav flex-column gap-1">
        <?php foreach ($section['items'] as $navRoute => [$label, $icon]): ?>
          <a class="nav-link sidebar-link <?= $route === $navRoute ? 'active' : '' ?>" href="?r=<?= $navRoute ?>"><i class="bi <?= $icon ?> me-2"></i><?= $label ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endforeach; ?>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
