<?php
$activeTab = (string) ($_GET['tab'] ?? ($excelValidationResult['template_id'] ?? $excelExecutionResult['template_id'] ?? 'ingresos'));
$selectedTemplate = null;
foreach ($excelTemplates as $template) {
    if ($template['id'] === $activeTab) {
        $selectedTemplate = $template;
        break;
    }
}
$selectedTemplate ??= $excelTemplates[0] ?? null;
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Importaciones</li><li class="breadcrumb-item active">Importar Excel</li></ol></nav>

<div class="card shadow-sm">
  <div class="card-body">
    <h4>Importar Excel por pestaña</h4>
    <p class="text-muted mb-3">Seleccione una de las 7 pestañas oficiales, cargue un único archivo Excel y ejecute <strong>Validar</strong> o <strong>Importar</strong> para esa hoja específica.</p>

    <ul class="nav nav-tabs mb-3">
      <?php foreach ($excelTemplates as $template): ?>
        <li class="nav-item">
          <a class="nav-link <?= $template['id'] === $selectedTemplate['id'] ? 'active' : '' ?>" href="?r=import-excel&tab=<?= urlencode($template['id']) ?>">
            <?= htmlspecialchars((string) $template['label']) ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($selectedTemplate): ?>
      <div class="alert alert-secondary py-2">
        Hoja objetivo: <strong><?= htmlspecialchars((string) $selectedTemplate['sheet_name']) ?></strong>
      </div>
      <form method="post" action="?r=import-excel&tab=<?= urlencode($selectedTemplate['id']) ?>" enctype="multipart/form-data" class="row g-3">
        <input type="hidden" name="template_id" value="<?= htmlspecialchars((string) $selectedTemplate['id']) ?>">
        <div class="col-md-8">
          <label class="form-label">Archivo Excel</label>
          <input class="form-control" type="file" name="excel" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
        </div>
        <div class="col-md-4 d-flex align-items-end gap-2">
          <button class="btn btn-outline-primary" name="action" value="validate" type="submit">Validar</button>
          <button class="btn btn-primary" name="action" value="execute" type="submit">Importar</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php $result = ($excelExecutionResult && ($excelExecutionResult['template_id'] ?? '') === $selectedTemplate['id']) ? $excelExecutionResult : ((($excelValidationResult['template_id'] ?? '') === $selectedTemplate['id']) ? $excelValidationResult : null); ?>
<?php if ($result): ?>
  <div class="card mt-3 shadow-sm"><div class="card-body">
    <h5>Resultado (<?= isset($result['timestamp']) ? 'Importación' : 'Validación' ?>)</h5>
    <p class="mb-2">Archivo: <strong><?= htmlspecialchars((string) ($result['file_name'] ?? '')) ?></strong></p>
    <ul>
      <li>Importables: <strong><?= (int) ($result['counts']['importable_rows'] ?? 0) ?></strong></li>
      <li>Omitidas por fórmula: <strong><?= (int) ($result['counts']['skipped_formula_rows'] ?? 0) ?></strong></li>
      <li>Errores por tipo/fila: <strong><?= (int) ($result['counts']['error_rows'] ?? 0) ?></strong></li>
      <?php if (isset($result['counts']['imported_rows'])): ?>
        <li>Insertadas: <strong><?= (int) $result['counts']['imported_rows'] ?></strong></li>
        <li>Actualizadas: <strong><?= (int) $result['counts']['updated_rows'] ?></strong></li>
        <li>Omitidas: <strong><?= (int) $result['counts']['omitted_rows'] ?></strong></li>
      <?php endif; ?>
    </ul>

    <?php if (!empty($result['preview'])): ?>
      <h6>Preview (máximo 20 filas importables)</h6>
      <div class="table-responsive"><table class="table table-sm table-striped">
        <thead><tr><th>Periodo</th><th>Código</th><th>Nombre cuenta</th><th>Total recalculado</th></tr></thead>
        <tbody>
          <?php foreach ($result['preview'] as $row): ?>
            <tr>
              <td><?= htmlspecialchars((string) ($row['periodo'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string) ($row['codigo'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string) ($row['nombre_cuenta'] ?? '')) ?></td>
              <td><?= number_format((float) ($row['total'] ?? 0), 2, ',', '.') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div></div>
<?php endif; ?>
