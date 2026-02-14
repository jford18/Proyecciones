<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item active">Historial de importaciones</li></ol></nav>
<div class="card shadow-sm"><div class="card-body">
  <h4>Historial de importaciones</h4>
  <p class="text-muted">Últimas importaciones registradas para el proyecto activo.</p>
  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead><tr><th>Fecha</th><th>Archivo</th><th>Hoja</th><th>Insertados</th><th>Mensaje</th></tr></thead>
      <tbody>
      <?php if (empty($logs)): ?>
        <tr><td colspan="5" class="text-muted">No hay importaciones registradas.</td></tr>
      <?php else: foreach ($logs as $log): ?>
        <tr>
          <td><?= htmlspecialchars((string) ($log['CREADO_EN'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string) ($log['ARCHIVO'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string) ($log['HOJA'] ?? '')) ?></td>
          <td><?= (int) ($log['REGISTROS_INSERTADOS'] ?? 0) ?></td>
          <td><?= htmlspecialchars((string) ($log['MENSAJE'] ?? '')) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div></div>
