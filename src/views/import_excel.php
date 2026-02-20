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
$initialResult = ($excelExecutionResult && ($excelExecutionResult['template_id'] ?? '') === ($selectedTemplate['id'] ?? '')) ? $excelExecutionResult : ((($excelValidationResult['template_id'] ?? '') === ($selectedTemplate['id'] ?? '')) ? $excelValidationResult : null);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Importaciones</li><li class="breadcrumb-item active">Importar Excel</li></ol></nav>

<div class="card shadow-sm">
  <div class="card-body">
    <h4>Importar Excel por pestaña</h4>
    <p class="text-muted mb-3">Flujo AJAX: <strong>Validar</strong> y <strong>Importar</strong> consumen endpoints JSON sin recargar la página.</p>

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
      <form id="excelImportForm" enctype="multipart/form-data" class="row g-3">
        <input type="hidden" id="templateId" name="template_id" value="<?= htmlspecialchars((string) $selectedTemplate['id']) ?>">
        <div class="col-md-8">
          <label class="form-label">Archivo Excel</label>
          <input class="form-control" id="excelFile" type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
        </div>
        <div class="col-md-4 d-flex align-items-end gap-2">
          <button class="btn btn-outline-primary" id="validateBtn" type="button">Validar</button>
          <button class="btn btn-primary" id="executeBtn" type="button">Importar</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card mt-3 shadow-sm" id="resultCard" style="display:none;">
  <div class="card-body">
    <h5 id="resultTitle">Resultado</h5>
    <p class="mb-2" id="resultFile"></p>
    <ul id="resultSummary"></ul>
    <div id="resultAlert"></div>

    <h6 class="mt-3">Detalles</h6>
    <div class="table-responsive">
      <table class="table table-sm table-striped">
        <thead><tr><th>Fila</th><th>Columna</th><th>Severidad</th><th>Mensaje</th><th>Valor</th></tr></thead>
        <tbody id="detailsBody"></tbody>
      </table>
    </div>

    <h6 class="mt-3">Preview</h6>
    <div class="table-responsive">
      <table class="table table-sm table-striped">
        <thead><tr><th>Periodo</th><th>Código</th><th>Nombre cuenta</th><th>Total recalculado</th></tr></thead>
        <tbody id="previewBody"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
  (function () {
    const tipo = <?= json_encode((string) ($activeTipo ?? 'PRESUPUESTO'), JSON_UNESCAPED_UNICODE) ?>;
    const tab = <?= json_encode((string) ($selectedTemplate['id'] ?? 'ingresos'), JSON_UNESCAPED_UNICODE) ?>;
    const form = document.getElementById('excelImportForm');
    const fileInput = document.getElementById('excelFile');
    const validateBtn = document.getElementById('validateBtn');
    const executeBtn = document.getElementById('executeBtn');
    const resultCard = document.getElementById('resultCard');
    const resultTitle = document.getElementById('resultTitle');
    const resultFile = document.getElementById('resultFile');
    const resultSummary = document.getElementById('resultSummary');
    const detailsBody = document.getElementById('detailsBody');
    const previewBody = document.getElementById('previewBody');
    const resultAlert = document.getElementById('resultAlert');

    function escapeHtml(text) {
      return String(text ?? '').replace(/[&<>'"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[ch]));
    }

    function buildFormData() {
      const file = fileInput?.files?.[0];
      if (file) {
        console.log('[IMPORT_FILE]', file.name, file.size);
      }
      if (!file) {
        throw new Error('Debes seleccionar un archivo Excel antes de continuar.');
      }
      const fd = new FormData();
      fd.append('file', file);
      fd.append('template_id', tab);
      fd.append('tipo', tipo);
      return fd;
    }

    function renderResult(payload, mode) {
      resultCard.style.display = '';
      resultTitle.textContent = mode === 'execute' ? 'Resultado (Importación)' : 'Resultado (Validación)';
      resultFile.innerHTML = `Archivo: <strong>${escapeHtml(payload.file_name || '')}</strong>`;
      const counts = payload.counts || {};
      const inserted = payload.inserted_count ?? counts.imported_rows ?? 0;
      const updated = payload.updated_count ?? counts.updated_rows ?? 0;
      const skipped = payload.skipped_count ?? counts.omitted_rows ?? 0;
      const warning = payload.warning_count ?? counts.warning_rows ?? 0;
      const errorRows = counts.error_rows ?? 0;

      resultSummary.innerHTML = `
        <li>Importables: <strong>${counts.importable_rows ?? counts.importables ?? 0}</strong></li>
        <li>Warnings: <strong>${warning}</strong></li>
        <li>Errores: <strong>${errorRows}</strong></li>
        <li>Insertadas: <strong>${inserted}</strong></li>
        <li>Actualizadas: <strong>${updated}</strong></li>
        <li>Omitidas: <strong>${skipped}</strong></li>
      `;

      if (mode === 'execute' && inserted + updated === 0 && (counts.importable_rows ?? 0) > 0) {
        resultAlert.innerHTML = '<div class="alert alert-danger mb-0">No se guardaron filas importables.</div>';
      } else {
        resultAlert.innerHTML = '';
      }

      const details = Array.isArray(payload.details) ? payload.details : [];
      detailsBody.innerHTML = details.slice(0, 100).map((detail) => `
        <tr>
          <td>${escapeHtml(detail.row_num ?? '')}</td>
          <td>${escapeHtml(detail.column ?? '')}</td>
          <td>${escapeHtml(detail.severity ?? '')}</td>
          <td>${escapeHtml(detail.message ?? '')}</td>
          <td>${escapeHtml(detail.raw_value ?? '')}</td>
        </tr>
      `).join('') || '<tr><td colspan="5" class="text-muted">Sin detalles.</td></tr>';

      const preview = Array.isArray(payload.preview) ? payload.preview : [];
      previewBody.innerHTML = preview.map((row) => `
        <tr>
          <td>${escapeHtml(row.periodo ?? '')}</td>
          <td>${escapeHtml(row.codigo ?? '')}</td>
          <td>${escapeHtml(row.nombre_cuenta ?? '')}</td>
          <td>${Number(row.total ?? 0).toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
        </tr>
      `).join('') || '<tr><td colspan="4" class="text-muted">Sin preview.</td></tr>';
    }

    async function callImport(action, mode) {
      const endpointUrl = `?r=import-excel&action=${encodeURIComponent(action)}&tab=${encodeURIComponent(tab)}&tipo=${encodeURIComponent(tipo)}`;
      try {
        const fd = buildFormData();
        const controller = new AbortController();
        const timeoutMs = 60000;
        const timeoutId = window.setTimeout(() => controller.abort(), timeoutMs);
        const response = await fetch(endpointUrl, {
          method: 'POST',
          body: fd,
          signal: controller.signal,
        });
        window.clearTimeout(timeoutId);
        const contentType = String(response.headers.get('content-type') || '').toLowerCase();
        const rawBody = await response.text();
        let payload = null;
        if (contentType.includes('application/json')) {
          payload = rawBody ? JSON.parse(rawBody) : {};
        } else {
          console.error(`[IMPORT_${mode.toUpperCase()}][${tab.toUpperCase()}] non-json response:`, rawBody);
          const snippet = String(rawBody || '').slice(0, 140).replace(/\s+/g, ' ').trim();
          throw new Error(`Endpoint devolvió HTML o texto no JSON (HTTP ${response.status}). URL: ${endpointUrl}. Body: ${snippet || '[vacío]'}`);
        }

        console.log(`[IMPORT_${mode.toUpperCase()}][${tab.toUpperCase()}] response:`, payload);
        if (!response.ok || payload.ok === false) {
          const message = payload?.message || `Error HTTP ${response.status}`;
          throw new Error(`${message}. URL: ${endpointUrl}`);
        }
        renderResult(payload, mode);
      } catch (error) {
        if (error?.name === 'AbortError') {
          error = new Error('La importación sigue procesando. Intenta nuevamente o revisa logs del servidor.');
        }
        resultCard.style.display = '';
        resultTitle.textContent = 'Error';
        resultFile.textContent = '';
        resultSummary.innerHTML = '';
        detailsBody.innerHTML = '';
        previewBody.innerHTML = '';
        resultAlert.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(error.message || 'Error inesperado.')}</div>`;
      }
    }

    if (form) {
      form.addEventListener('submit', (event) => event.preventDefault());
    }
    if (validateBtn) {
      validateBtn.addEventListener('click', (event) => {
        event.preventDefault();
        callImport('validate', 'validate');
      });
    }
    if (executeBtn) {
      executeBtn.addEventListener('click', (event) => {
        event.preventDefault();
        callImport('execute', 'execute');
      });
    }

    const initialResult = <?= json_encode($initialResult, JSON_UNESCAPED_UNICODE) ?>;
    if (initialResult && typeof initialResult === 'object') {
      renderResult(initialResult, initialResult.timestamp ? 'execute' : 'validate');
    }
  })();
</script>
