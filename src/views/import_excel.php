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
        <input type="hidden" id="validatedJsonPath" name="json_path" value="">
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

    <div class="d-flex align-items-center justify-content-between mt-3 gap-2">
      <h6 class="mb-0">Detalles</h6>
      <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-success" id="viewExcelBtn" style="display:none;" href="#">Ver como Excel</a>
        <a class="btn btn-sm btn-success" id="downloadExcelBtn" style="display:none;" href="#">Descargar Excel</a>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="openDetailsBtn" data-bs-toggle="modal" data-bs-target="#importDetailsModal">Ver detalles</button>
      </div>
    </div>

    <h6 class="mt-3">Preview</h6>
    <div class="table-responsive">
      <table class="table table-sm table-striped">
        <thead id="previewHead"><tr><th>Periodo</th><th>Código</th><th>Nombre cuenta</th><th>Total recalculado</th></tr></thead>
        <tbody id="previewBody"></tbody>
      </table>
    </div>
  </div>
</div>


<div class="modal fade" id="importDetailsModal" tabindex="-1" aria-labelledby="importDetailsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="importDetailsModalLabel">Detalles de importación</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <ul class="nav nav-tabs mb-3" id="detailsTabs">
          <li class="nav-item"><button class="nav-link active" type="button" data-filter="all">Todos</button></li>
          <li class="nav-item"><button class="nav-link" type="button" data-filter="ERROR">Errores</button></li>
          <li class="nav-item"><button class="nav-link" type="button" data-filter="WARNING">Warnings</button></li>
          <li class="nav-item"><button class="nav-link" type="button" data-filter="FORMULA">Omitidas por fórmula</button></li>
        </ul>
        <div class="table-responsive">
          <table class="table table-sm table-striped">
            <thead><tr><th>Fila</th><th>Columna</th><th>Severidad</th><th>Mensaje</th><th>Valor</th></tr></thead>
            <tbody id="detailsBody"></tbody>
          </table>
        </div>
        <div class="text-center mt-2">
          <button class="btn btn-sm btn-outline-primary" id="showMoreDetailsBtn" type="button" style="display:none;">Mostrar más</button>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="excelGridModal" tabindex="-1" aria-labelledby="excelGridModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen-lg-down modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="excelGridModalLabel">Ver como Excel</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div id="excelGridAlert" class="mb-2"></div>
        <div class="table-responsive" style="max-height: 70vh; overflow: auto;">
          <table class="table table-sm table-bordered align-middle" id="excelGridTable">
            <thead class="table-light" style="position: sticky; top: 0; z-index: 1;"></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
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
    const previewHead = document.getElementById('previewHead');
    const resultAlert = document.getElementById('resultAlert');
    const detailsTabs = document.getElementById('detailsTabs');
    const showMoreDetailsBtn = document.getElementById('showMoreDetailsBtn');
    const viewExcelBtn = document.getElementById('viewExcelBtn');
    const downloadExcelBtn = document.getElementById('downloadExcelBtn');
    const excelGridModalEl = document.getElementById('excelGridModal');
    const excelGridAlert = document.getElementById('excelGridAlert');
    const excelGridTable = document.getElementById('excelGridTable');
    const excelGridHead = excelGridTable ? excelGridTable.querySelector('thead') : null;
    const excelGridBody = excelGridTable ? excelGridTable.querySelector('tbody') : null;
    const validatedJsonPathInput = document.getElementById('validatedJsonPath');
    let lastValidatedJsonPath = '';
    let allDetails = [];
    let detailsFilter = 'all';
    let detailsLimit = 200;

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
      const persistedJsonPath = (validatedJsonPathInput?.value || lastValidatedJsonPath || '').trim();
      if (persistedJsonPath !== '') {
        fd.append('json_path', persistedJsonPath);
      }
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


      if (viewExcelBtn && downloadExcelBtn) {
        const selectedAnio = payload.anio ?? payload?.preview?.[0]?.periodo ?? '';
        const hasRowsInPreview = Array.isArray(payload.preview) && payload.preview.length > 0;
        const hasMutations = Number(payload.inserted_count ?? 0) > 0 || Number(payload.updated_count ?? 0) > 0;
        const hasExcelPreview = Boolean(
          payload.ok === true
          && (payload.json_path && String(payload.json_path).trim() !== '')
          && (hasRowsInPreview || hasMutations)
        );
        const queryAnio = selectedAnio ? `&anio=${encodeURIComponent(selectedAnio)}` : '';
        const base = `?r=import-excel&tab=${encodeURIComponent(tab)}&tipo=${encodeURIComponent(tipo)}${queryAnio}`;
        viewExcelBtn.href = `${base}&action=view-excel&_t=${Date.now()}`;
        downloadExcelBtn.href = `${base}&action=export_xlsx`;
        viewExcelBtn.dataset.anio = String(selectedAnio || '');
        viewExcelBtn.style.display = hasExcelPreview ? '' : 'none';
        downloadExcelBtn.style.display = tab === 'eeff_reales_eri' ? 'none' : '';
      }

      if (mode === 'execute' && inserted + updated === 0 && (counts.importable_rows ?? 0) > 0) {
        resultAlert.innerHTML = '<div class="alert alert-danger mb-0">No se guardaron filas importables.</div>';
      } else {
        resultAlert.innerHTML = '';
      }

      allDetails = Array.isArray(payload.details) ? payload.details : [];
      detailsFilter = 'all';
      detailsLimit = 200;
      renderDetails();

      const preview = Array.isArray(payload.preview) ? payload.preview : [];
      if (tab === 'eeff_reales_eri') {
        if (previewHead) {
          previewHead.innerHTML = '<tr><th>Código</th><th>Descripción</th><th>Enero</th><th>Total</th></tr>';
        }
        previewBody.innerHTML = preview.map((row) => `
          <tr>
            <td>${escapeHtml(row.codigo ?? row.CODIGO ?? '')}</td>
            <td>${escapeHtml(row.descripcion ?? row.DESCRIPCION ?? '')}</td>
            <td>${Number((row.enero ?? row.ENERO) ?? 0).toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
            <td>${Number((row.total ?? row.TOTAL) ?? 0).toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
          </tr>
        `).join('') || '<tr><td colspan="4" class="text-muted">Sin preview.</td></tr>';
      } else {
        if (previewHead) {
          previewHead.innerHTML = '<tr><th>Periodo</th><th>Código</th><th>Nombre cuenta</th><th>Total recalculado</th></tr>';
        }
        previewBody.innerHTML = preview.map((row) => `
          <tr>
            <td>${escapeHtml(row.periodo ?? row.PERIODO ?? '')}</td>
            <td>${escapeHtml(row.codigo ?? row.CODIGO ?? '')}</td>
            <td>${escapeHtml(row.nombre_cuenta ?? row.NOMBRE_CUENTA ?? '')}</td>
            <td>${Number((row.total_recalculado ?? row.TOTAL_RECALCULADO ?? row.total ?? row.TOTAL) ?? 0).toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
          </tr>
        `).join('') || '<tr><td colspan="4" class="text-muted">Sin preview.</td></tr>';
      }
    }

    function renderDetails() {
      let filtered = allDetails;
      if (detailsFilter === 'ERROR') {
        filtered = allDetails.filter((detail) => String(detail.severity || '').toUpperCase() === 'ERROR');
      } else if (detailsFilter === 'WARNING') {
        filtered = allDetails.filter((detail) => String(detail.severity || '').toUpperCase() === 'WARNING');
      } else if (detailsFilter === 'FORMULA') {
        filtered = allDetails.filter((detail) => ['NO_CALCULATED_VALUES', 'FORMULA_NOT_CALCULABLE', 'FORMULA_CALCULATED'].includes(String(detail.code || '').toUpperCase()));
      }

      const visible = filtered.slice(0, detailsLimit);
      detailsBody.innerHTML = visible.map((detail) => `
        <tr>
          <td>${escapeHtml(detail.row_num ?? '')}</td>
          <td>${escapeHtml(detail.column ?? '')}</td>
          <td>${escapeHtml(detail.severity ?? '')}</td>
          <td>${escapeHtml(detail.message ?? '')}</td>
          <td>${escapeHtml(detail.raw_value ?? '')}</td>
        </tr>
      `).join('') || '<tr><td colspan="5" class="text-muted">Sin detalles.</td></tr>';

      showMoreDetailsBtn.style.display = filtered.length > detailsLimit ? '' : 'none';
    }


    function formatNumber(value) {
      return Number(value || 0).toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function renderExcelGrid(payload) {
      const headers = Array.isArray(payload.headers) ? payload.headers : [];
      const rows = Array.isArray(payload.rows) ? payload.rows : [];
      if (!excelGridHead || !excelGridBody) {
        return;
      }

      excelGridHead.innerHTML = `<tr>${headers.map((h) => `<th class="text-nowrap">${escapeHtml(h)}</th>`).join('')}</tr>`;
      excelGridBody.innerHTML = rows.map((row) => {
        return `<tr>${headers.map((h) => {
          const value = row[h] ?? '';
          const isNumeric = ['ENE','FEB','MAR','ABR','MAY','JUN','JUL','AGO','SEP','OCT','NOV','DIC','TOTAL','TOTAL_RECALCULADO','VALOR'].includes(h);
          return `<td class="${isNumeric ? 'text-end' : 'text-nowrap'}">${isNumeric ? formatNumber(value) : escapeHtml(value)}</td>`;
        }).join('')}</tr>`;
      }).join('') || '<tr><td class="text-muted">Sin filas para mostrar.</td></tr>';
    }

    async function openExcelGridPreview() {
      try {
        const selectedAnio = viewExcelBtn?.dataset?.anio || '';
        const queryAnio = selectedAnio ? `&anio=${encodeURIComponent(selectedAnio)}` : '';
        const endpointUrl = `?r=import-excel&action=preview_db&tab=${encodeURIComponent(tab)}&tipo=${encodeURIComponent(tipo)}${queryAnio}`;
        const response = await fetch(endpointUrl, { headers: { 'Accept': 'application/json' } });
        const raw = await response.text();
        const payload = raw ? JSON.parse(raw) : {};
        if (!response.ok || payload.ok === false) {
          throw new Error(payload.message || `Error HTTP ${response.status}`);
        }

        excelGridAlert.innerHTML = '';
        renderExcelGrid({ headers: payload.columns || [], rows: payload.rows || [] });
        if (window.bootstrap && excelGridModalEl) {
          window.bootstrap.Modal.getOrCreateInstance(excelGridModalEl).show();
        }
      } catch (error) {
        if (excelGridAlert) {
          excelGridAlert.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(error.message || 'No se pudo cargar preview')}</div>`;
        }
      }
    }

    async function callImport(action, mode) {
      const endpointUrl = tab === 'eeff_reales_eri'
        ? `?r=import-excel/${action === 'validate' ? 'validar-eeff-reales-eri' : 'importar-eeff-reales-eri'}&tab=${encodeURIComponent(tab)}&tipo=${encodeURIComponent(tipo)}`
        : `?r=import-excel&action=${encodeURIComponent(action)}&tab=${encodeURIComponent(tab)}&tipo=${encodeURIComponent(tipo)}`;
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
          throw new Error(`Error al importar (respuesta no JSON). Endpoint devolvió HTML o texto no JSON (HTTP ${response.status}). URL: ${endpointUrl}. Body: ${snippet || '[vacío]'}`);
        }

        console.log(`[IMPORT_${mode.toUpperCase()}][${tab.toUpperCase()}] response:`, payload);
        if (!response.ok || payload.ok === false) {
          const message = payload?.message || `Error HTTP ${response.status}`;
          throw new Error(`${message}. URL: ${endpointUrl}`);
        }
        if (mode === 'validate') {
          lastValidatedJsonPath = String(payload?.json_path || '').trim();
          if (validatedJsonPathInput) {
            validatedJsonPathInput.value = lastValidatedJsonPath;
          }
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
    if (viewExcelBtn) {
      viewExcelBtn.addEventListener('click', (event) => {
        event.preventDefault();
        const href = viewExcelBtn.getAttribute('href') || '';
        if (!href) {
          return;
        }
        window.open(href, '_blank', 'noopener');
      });
    }

    if (executeBtn) {
      executeBtn.addEventListener('click', (event) => {
        event.preventDefault();
        callImport('execute', 'execute');
      });
    }


    if (detailsTabs) {
      detailsTabs.addEventListener('click', (event) => {
        const btn = event.target.closest('button[data-filter]');
        if (!btn) {
          return;
        }
        detailsTabs.querySelectorAll('button[data-filter]').forEach((item) => item.classList.remove('active'));
        btn.classList.add('active');
        detailsFilter = btn.dataset.filter || 'all';
        detailsLimit = 200;
        renderDetails();
      });
    }

    if (showMoreDetailsBtn) {
      showMoreDetailsBtn.addEventListener('click', () => {
        detailsLimit += 200;
        renderDetails();
      });
    }

    const initialResult = <?= json_encode($initialResult, JSON_UNESCAPED_UNICODE) ?>;
    if (initialResult && typeof initialResult === 'object') {
      renderResult(initialResult, initialResult.timestamp ? 'execute' : 'validate');
    }
  })();
</script>
