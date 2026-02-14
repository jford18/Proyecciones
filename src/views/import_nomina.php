<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Importaciones</li><li class="breadcrumb-item active">Importar NÓMINA</li></ol></nav>
<div class="card shadow-sm"><div class="card-body">
  <h4>Importar NÓMINA</h4>
  <p class="text-muted">Hoja esperada: <strong>NOMINA</strong>. Se detecta mes/año desde el encabezado y se consolidan totales por concepto.</p>

  <?php if (!$activeFile): ?>
    <div class="alert alert-info">Primero sube o selecciona un Excel. <a href="?r=upload">Ir a subir archivo</a>.</div>
  <?php else: ?>
    <div class="row g-2 mb-3">
      <div class="col-md-6"><label class="form-label">Proyecto activo</label><input class="form-control" readonly value="Proyecto <?= $activeProjectId ?>"></div>
      <div class="col-md-6"><label class="form-label">Archivo activo</label><div class="input-group"><input class="form-control" readonly value="<?= htmlspecialchars(basename((string) $activeFile)) ?>"><a class="btn btn-outline-secondary" href="?r=files">Cambiar</a></div></div>
    </div>
    <form method="post" action="?r=import-nomina" onsubmit="return confirmImport(this, 'NÓMINA', '<?= $activeProjectId ?>', '<?= htmlspecialchars(basename((string) $activeFile), ENT_QUOTES) ?>')">
      <button class="btn btn-primary" type="submit" data-loading-text="Importando...">Importar ahora</button>
    </form>
  <?php endif; ?>
</div></div>

<?php if ($importResult && ($importResult['type'] ?? '') === 'NOMINA'): ?>
<div class="card mt-3 shadow-sm"><div class="card-body">
  <h5>Resultado</h5>
  <p class="mb-1">Registros insertados: <strong><?= (int) ($importResult['inserted'] ?? 0) ?></strong></p>
  <p class="mb-3">Warnings: celdas vacías o no numéricas omitidas.</p>
  <a class="btn btn-outline-secondary btn-sm" href="?r=anexos&tipoAnexo=NOMINA&proyectoId=<?= $activeProjectId ?>">Ver anexos importados</a>
</div></div>
<?php endif; ?>
