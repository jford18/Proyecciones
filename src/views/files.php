<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Archivos</li><li class="breadcrumb-item active">Historial de archivos</li></ol></nav>
<div class="card shadow-sm"><div class="card-body">
  <h4>Historial de archivos</h4>
  <div class="table-responsive"><table class="table table-striped align-middle">
    <thead><tr><th>Nombre</th><th>Fecha</th><th>Tamaño</th><th>Acciones</th></tr></thead>
    <tbody>
    <?php if (empty($files)): ?>
      <tr><td colspan="4" class="text-muted">No hay archivos cargados.</td></tr>
    <?php else: foreach ($files as $file): ?>
      <tr>
        <td><?= htmlspecialchars((string) $file['name']) ?></td>
        <td><?= htmlspecialchars((string) $file['uploaded_at']) ?></td>
        <td><?= number_format(((int) $file['size']) / 1024, 2) ?> KB</td>
        <td>
          <form method="post" action="?r=select-file" class="m-0">
            <input type="hidden" name="path" value="<?= htmlspecialchars((string) $file['path']) ?>">
            <input type="hidden" name="back_route" value="files">
            <button class="btn btn-sm <?= (($activeFile ?? '') === $file['path']) ? 'btn-success' : 'btn-outline-primary' ?>" type="submit">
              <?= (($activeFile ?? '') === $file['path']) ? 'Activo' : 'Seleccionar' ?>
            </button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div></div>
