<?php
/** @var string $contentView */
/** @var array $viewData */
extract($viewData);

$sections = [
    ['title' => null, 'items' => ['dashboard' => ['Dashboard', 'bi-speedometer2']]],
    ['title' => 'Importaciones', 'items' => [
        'import-gastos' => ['PASO 1.1 GASTOS', 'bi-cash-stack'],
        'import-nomina' => ['PASO 1.2 NÓMINA', 'bi-people'],
        'import-cobranza' => ['PASO 1.3 COBRANZA', 'bi-wallet2'],
        'import-activos' => ['PASO 1.4 ACTIVOS', 'bi-building'],
        'import-excel' => ['Importar Excel (7 pestañas)', 'bi-file-earmark-spreadsheet'],
        'consolidar-pg' => ['PASO 2 Consolidar PG', 'bi-diagram-3'],
        'generar-flujo' => ['PASO 3 Generar FLUJO', 'bi-calculator'],
    ]],
    ['title' => 'Reportes', 'items' => ['flujo' => ['Ver FLUJO', 'bi-table'], 'anexos' => ['Ver ANEXOS', 'bi-list-columns']]],
    ['title' => null, 'items' => ['history-imports' => ['Historial importaciones', 'bi-clock-history'], 'config' => ['Configurar mapeo', 'bi-gear']]],
];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Proyecciones</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar p-3">
    <h5 class="text-white mb-3">Proyecciones</h5>
    <?php foreach ($sections as $section): ?>
      <?php if ($section['title']): ?><div class="small text-uppercase text-secondary mt-3 mb-1"><?= $section['title'] ?></div><?php endif; ?>
      <nav class="nav flex-column gap-1"><?php foreach ($section['items'] as $navRoute => [$label, $icon]): ?><a class="nav-link sidebar-link <?= $route === $navRoute ? 'active' : '' ?>" href="?r=<?= $navRoute ?>"><i class="bi <?= $icon ?> me-2"></i><?= $label ?></a><?php endforeach; ?></nav>
    <?php endforeach; ?>
  </aside>
  <main class="content-area">
    <header class="topbar d-flex justify-content-between gap-2 p-3">
      <form method="post" action="?r=set-project" class="d-flex align-items-center gap-2 m-0">
        <input type="hidden" name="back_route" value="<?= htmlspecialchars((string) $route) ?>">
        <select class="form-select form-select-sm" name="project_id" onchange="this.form.submit()"><?php foreach ($projectOptions as $project): $projectId = (int) $project['ID']; ?><option value="<?= $projectId ?>" <?= $activeProjectId === $projectId ? 'selected' : '' ?>><?= htmlspecialchars((string) $project['NOMBRE']) ?></option><?php endforeach; ?></select>
      </form>
      <form method="get" class="d-flex align-items-center gap-2"><input type="hidden" name="r" value="<?= htmlspecialchars((string) $route) ?>"><label class="small text-muted">Tipo</label><select class="form-select form-select-sm" name="tipo" onchange="this.form.submit()"><option value="PRESUPUESTO" <?= $activeTipo === 'PRESUPUESTO' ? 'selected' : '' ?>>PRESUPUESTO</option><option value="REAL" <?= $activeTipo === 'REAL' ? 'selected' : '' ?>>REAL</option></select></form>
    </header>
    <div class="container-fluid p-3">
      <?php if ($flash): $map=['success'=>'success','error'=>'danger','info'=>'info']; ?><div class="alert alert-<?= $map[$flash['type']] ?? 'secondary' ?>"><?= htmlspecialchars((string) $flash['text']) ?></div><?php endif; ?>
      <?php include $contentView; ?>
    </div>
  </main>
</div>
</body>
</html>
