<?php $lastImport = $stats['lastImport'] ?? null; $counts = $stats['counts'] ?? ['today' => 0, 'total' => 0]; ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item active">Dashboard</li></ol></nav>
<div class="row g-3">
  <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><h6>Proyecto activo</h6><div class="display-6"><?= $activeProject ? htmlspecialchars((string) $activeProject['NOMBRE']) : 'N/D' ?></div><div class="text-muted">ID: <?= $activeProjectId ?></div></div></div></div>
  <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><h6>Anexos hoy</h6><div class="display-6"><?= (int) $counts['today'] ?></div></div></div></div>
  <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><h6>Anexos total</h6><div class="display-6"><?= (int) $counts['total'] ?></div></div></div></div>
</div>
<div class="card mt-3 shadow-sm"><div class="card-body">
  <h5>Última importación</h5>
  <?php if ($lastImport): ?>
    <p class="mb-1"><strong>Archivo:</strong> <?= htmlspecialchars((string) $lastImport['ARCHIVO']) ?></p>
    <p class="mb-1"><strong>Hoja:</strong> <?= htmlspecialchars((string) $lastImport['HOJA']) ?></p>
    <p class="mb-1"><strong>Registros:</strong> <?= htmlspecialchars((string) $lastImport['REGISTROS_INSERTADOS']) ?></p>
  <?php else: ?><p class="text-muted mb-0">Aún no hay importaciones registradas.</p><?php endif; ?>
</div></div>
<div class="d-flex flex-wrap gap-2">
  <a href="?r=import-gastos" class="btn btn-primary">Importar Gastos</a>
  <a href="?r=import-nomina" class="btn btn-primary">Importar Nómina</a>
  <a href="?r=anexos" class="btn btn-outline-secondary">Ver Anexos</a>
</div>
