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
$result = ($excelExecutionResult && ($excelExecutionResult['template_id'] ?? '') === ($selectedTemplate['id'] ?? '')) ? $excelExecutionResult : ((($excelValidationResult['template_id'] ?? '') === ($selectedTemplate['id'] ?? '')) ? $excelValidationResult : null);
$isIngresosTab = (($selectedTemplate['id'] ?? '') === 'ingresos');
$details = $result['details'] ?? $result['errors'] ?? [];
$warningRows = (int) ($result['counts']['warning_rows'] ?? 0);
$errorRows = (int) ($result['counts']['error_rows'] ?? 0);
$skipRows = (int) ($result['counts']['skipped_formula_rows'] ?? 0);
$hasDetails = $isIngresosTab && ($warningRows > 0 || $errorRows > 0 || $skipRows > 0) && !empty($details);
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
          <button class="btn btn-primary" name="action" value="execute" type="submit" <?= ($isIngresosTab && $errorRows > 0) ? 'disabled title="No puedes importar mientras existan errores estructurales"' : '' ?>>Importar</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($result): ?>
  <div class="card mt-3 shadow-sm"><div class="card-body">
    <h5>Resultado (<?= isset($result['timestamp']) ? 'Importación' : 'Validación' ?>)</h5>
    <p class="mb-2">Archivo: <strong><?= htmlspecialchars((string) ($result['file_name'] ?? '')) ?></strong></p>
    <ul>
      <li>Importables: <strong><?= (int) ($result['counts']['importable_rows'] ?? 0) ?></strong></li>
      <li>Omitidas por fórmula: <strong><?= (int) ($result['counts']['skipped_formula_rows'] ?? 0) ?></strong></li>
      <li>Errores por tipo/fila: <strong><?= (int) ($result['counts']['error_rows'] ?? 0) ?></strong></li>
      <?php if ($isIngresosTab): ?>
        <li>Warnings: <strong><?= (int) ($result['counts']['warning_rows'] ?? 0) ?></strong></li>
      <?php endif; ?>
      <?php if (isset($result['counts']['imported_rows'])): ?>
        <li>Insertadas: <strong><?= (int) $result['counts']['imported_rows'] ?></strong></li>
        <li>Actualizadas: <strong><?= (int) $result['counts']['updated_rows'] ?></strong></li>
        <li>Omitidas: <strong><?= (int) $result['counts']['omitted_rows'] ?></strong></li>
      <?php endif; ?>
    </ul>
    <?php if ($hasDetails): ?>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#ingresosDetailsModal">Ver detalles</button>
    <?php endif; ?>

    <?php if ($isIngresosTab && $errorRows > 0): ?>
      <div class="alert alert-danger mt-3 mb-0">Hay errores estructurales. Debes corregirlos antes de importar.</div>
    <?php elseif ($isIngresosTab && $warningRows > 0): ?>
      <div class="alert alert-warning mt-3 mb-0">Se detectaron warnings. Puedes importar, pero revisa los detalles.</div>
    <?php endif; ?>

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


<?php if ($hasDetails): ?>
<div class="modal fade" id="ingresosDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Detalles de validación - Ingresos</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="btn-group btn-group-sm mb-3" role="group" aria-label="Filtros">
          <button type="button" class="btn btn-outline-secondary details-filter active" data-filter="ALL">Todos</button>
          <button type="button" class="btn btn-outline-danger details-filter" data-filter="ERROR">Errores</button>
          <button type="button" class="btn btn-outline-warning details-filter" data-filter="WARNING">Warnings</button>
          <button type="button" class="btn btn-outline-primary details-filter" data-filter="SKIP">Omitidas por fórmula</button>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-striped">
            <thead><tr><th>Fila</th><th>Columna</th><th>Severidad</th><th>Mensaje</th><th>Valor</th></tr></thead>
            <tbody id="ingresosDetailsBody">
              <?php foreach ($details as $index => $detail): ?>
                <tr class="detail-row" data-severity="<?= htmlspecialchars((string) ($detail['severity'] ?? '')) ?>" data-index="<?= (int) $index ?>" style="display: <?= $index < 50 ? '' : 'none' ?>;">
                  <td><?= (int) ($detail['row_num'] ?? 0) ?></td>
                  <td><?= htmlspecialchars((string) ($detail['column'] ?? '')) ?></td>
                  <td><?= htmlspecialchars((string) ($detail['severity'] ?? '')) ?></td>
                  <td><?= htmlspecialchars((string) ($detail['message'] ?? '')) ?></td>
                  <td><?= htmlspecialchars((string) ($detail['raw_value'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if (count($details) > 50): ?>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="showMoreDetails">Mostrar más</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script>
  (function () {
    const rows = Array.from(document.querySelectorAll('#ingresosDetailsBody .detail-row'));
    const filters = Array.from(document.querySelectorAll('.details-filter'));
    const showMoreBtn = document.getElementById('showMoreDetails');
    let visibleLimit = 50;
    let activeFilter = 'ALL';

    function refreshRows() {
      let shown = 0;
      rows.forEach((row) => {
        const severity = row.dataset.severity || '';
        const matches = activeFilter === 'ALL' || severity === activeFilter;
        if (matches && shown < visibleLimit) {
          row.style.display = '';
          shown++;
        } else {
          row.style.display = 'none';
        }
      });
      if (showMoreBtn) {
        const totalMatched = rows.filter((row) => activeFilter === 'ALL' || (row.dataset.severity || '') === activeFilter).length;
        showMoreBtn.style.display = shown < totalMatched ? '' : 'none';
      }
    }

    filters.forEach((btn) => {
      btn.addEventListener('click', () => {
        filters.forEach((item) => item.classList.remove('active'));
        btn.classList.add('active');
        activeFilter = btn.dataset.filter || 'ALL';
        visibleLimit = 50;
        refreshRows();
      });
    });

    if (showMoreBtn) {
      showMoreBtn.addEventListener('click', () => {
        visibleLimit += 50;
        refreshRows();
      });
    }

    refreshRows();

    const importForm = document.querySelector('form[action*="import-excel"]');
    if (importForm) {
      importForm.addEventListener('submit', (event) => {
        const submitter = event.submitter;
        if (!submitter || submitter.value !== 'execute') {
          return;
        }
        const hasErrors = <?= $errorRows > 0 ? 'true' : 'false' ?>;
        const hasWarnings = <?= $warningRows > 0 ? 'true' : 'false' ?>;
        if (hasErrors) {
          event.preventDefault();
          return;
        }
        if (hasWarnings && !window.confirm('Hay warnings, ¿deseas continuar?')) {
          event.preventDefault();
        }
      });
    }
  })();
</script>
<?php endif; ?>
