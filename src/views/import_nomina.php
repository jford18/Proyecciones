<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Importaciones</li><li class="breadcrumb-item active">Importar NÓMINA</li></ol></nav>
<div class="card shadow-sm"><div class="card-body">
  <h4>Selecciona archivo Excel (NÓMINA)</h4>
  <p class="text-muted">Carga un archivo <strong>.xlsx</strong> para importar la hoja <strong>NOMINA</strong>.</p>

  <form method="post" action="?r=import-nomina" enctype="multipart/form-data" class="row g-3">
    <div class="col-md-4">
      <label class="form-label">Proyecto</label>
      <select class="form-select" name="proyecto_id" required>
        <?php foreach ($projectOptions as $project): ?>
          <?php $projectId = (int) $project['ID']; ?>
          <option value="<?= $projectId ?>" <?= $activeProjectId === $projectId ? 'selected' : '' ?>><?= htmlspecialchars((string) $project['NOMBRE']) ?> (ID: <?= $projectId ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-8">
      <label class="form-label">Archivo Excel</label>
      <input class="form-control" type="file" name="excel" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
      <div class="form-text">Máximo 10 MB. Se validará extensión, MIME y hoja esperada.</div>
    </div>
    <div class="col-12">
      <button class="btn btn-primary" type="submit">Importar ahora</button>
    </div>
  </form>
</div></div>

<?php if ($importResult && ($importResult['type'] ?? '') === 'NOMINA'): ?>
  <div class="card mt-3 shadow-sm"><div class="card-body">
    <h5>Resultado de importación</h5>
    <div class="alert alert-success mb-2">Archivo procesado: <strong><?= htmlspecialchars((string) ($importResult['fileName'] ?? '')) ?></strong></div>
    <div class="alert alert-primary mb-2">Registros insertados: <strong><?= (int) ($importResult['inserted'] ?? 0) ?></strong></div>
    <div class="alert alert-warning mb-3">Warnings omitidos: <strong><?= (int) ($importResult['warnings'] ?? 0) ?></strong>.</div>
    <a class="btn btn-outline-secondary btn-sm" href="?r=anexos&tipoAnexo=NOMINA&proyectoId=<?= $activeProjectId ?>">Ver anexos importados</a>
  </div></div>
<?php endif; ?>
