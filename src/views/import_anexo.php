<?php $titulo = $anexoTipo; ?>
<h4>PASO <?= htmlspecialchars((string) $stepLabel) ?> · Importar <?= htmlspecialchars((string) $titulo) ?></h4>
<form method="post" action="?r=import-<?= strtolower($titulo) ?>" enctype="multipart/form-data" class="row g-3">
  <div class="col-md-4"><label class="form-label">Proyecto</label><select class="form-select" name="proyecto_id"><?php foreach ($projectOptions as $project): $projectId = (int) $project['ID']; ?><option value="<?= $projectId ?>" <?= $activeProjectId === $projectId ? 'selected' : '' ?>><?= htmlspecialchars((string) $project['NOMBRE']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label">Tipo</label><select class="form-select" name="tipo"><option value="PRESUPUESTO">PRESUPUESTO</option><option value="REAL">REAL</option></select></div>
  <div class="col-md-4"><label class="form-label">Excel</label><input class="form-control" type="file" name="excel" required accept=".xlsx"></div>
  <div class="col-12"><button class="btn btn-primary" type="submit">Importar</button></div>
</form>
