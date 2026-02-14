<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Archivos</li><li class="breadcrumb-item active">Subir archivo</li></ol></nav>
<div class="card shadow-sm">
  <div class="card-body">
    <h4>Subir Excel</h4>
    <form method="post" action="?r=upload" enctype="multipart/form-data" class="row g-2 align-items-end">
      <div class="col-md-8">
        <label class="form-label">Archivo .xlsx / .xls</label>
        <input class="form-control" type="file" name="excel" accept=".xlsx,.xls" required>
      </div>
      <div class="col-md-4">
        <button class="btn btn-primary w-100" type="submit">Subir</button>
      </div>
    </form>
    <?php if ($activeFile): ?>
      <hr>
      <p class="mb-1"><strong>Archivo activo:</strong> <?= htmlspecialchars(basename((string) $activeFile)) ?></p>
      <p class="text-muted mb-0">Se usará automáticamente para las importaciones.</p>
    <?php endif; ?>
  </div>
</div>
