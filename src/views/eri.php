<?php
$months = ['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO','JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'];
$defaultYear = (int) ($eriDefaultYear ?? date('Y'));
?>
<div class="card">
  <div class="card-body">
    <h5 class="mb-3">📊 ERI – Estado de Resultados Integral</h5>
    <div class="row g-2 align-items-end mb-3">
      <div class="col-md-2">
        <label class="form-label">Año/Periodo</label>
        <input id="eri-anio" class="form-control" type="number" value="<?= $defaultYear ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">% Participación trabajadores</label>
        <input id="eri-participacion" class="form-control" type="number" min="0" step="0.01" value="15">
      </div>
      <div class="col-md-3">
        <label class="form-label">% Impuesto renta</label>
        <input id="eri-renta" class="form-control" type="number" min="0" step="0.01" value="25">
      </div>
      <div class="col-md-6 d-flex gap-2 flex-wrap">
        <button id="eri-recalcular" class="btn btn-primary">Recalcular</button>
        <a id="eri-exportar" class="btn btn-outline-success" href="#" target="_blank" rel="noopener">Exportar Excel</a>
        <button id="eri-comparativo-open" class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#eriComparativoModal">COMPARATIVO</button>
      </div>
    </div>

    <div class="table-sticky eri-table-wrap">
      <table class="table table-sm table-bordered align-middle eri-table">
        <thead>
        <tr>
          <th>CÓDIGO</th><th>DESCRIPCIÓN</th>
          <?php foreach ($months as $month): ?>
            <th class="text-center"><?= $month ?></th><th class="text-center">%</th>
          <?php endforeach; ?>
          <th class="text-center eri-sticky-total">TOTAL</th>
          <th class="text-center eri-sticky-pct">%</th>
        </tr>
        </thead>
        <tbody id="eri-tbody"></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="eriComparativoModal" tabindex="-1" aria-labelledby="eriComparativoModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="eriComparativoModalLabel">Comparativo de importaciones</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div id="eri-comparativo-alert" class="mb-2"></div>
        <div class="row g-2 align-items-end mb-3">
          <div class="col-md-3">
            <label class="form-label">Tipo A</label>
            <input id="eri-comp-tipo-a" class="form-control" value="REAL">
          </div>
          <div class="col-md-3">
            <label class="form-label">Tipo B</label>
            <input id="eri-comp-tipo-b" class="form-control" value="PRESUPUESTO">
          </div>
          <div class="col-md-3 form-check mt-4 pt-2">
            <input id="eri-comp-only-diff" class="form-check-input" type="checkbox" checked>
            <label class="form-check-label" for="eri-comp-only-diff">Solo diferencias</label>
          </div>
          <div class="col-md-3 d-flex gap-2">
            <button id="eri-comp-compare" type="button" class="btn btn-primary">Comparar</button>
            <button id="eri-comp-export-csv" type="button" class="btn btn-outline-success">Exportar diferencias</button>
          </div>
        </div>

        <div class="d-flex gap-2 mb-3 flex-wrap">
          <a id="eri-comp-view-a" class="btn btn-sm btn-outline-primary disabled" href="#" target="_blank" rel="noopener">Ver como Excel (A)</a>
          <a id="eri-comp-view-b" class="btn btn-sm btn-outline-primary disabled" href="#" target="_blank" rel="noopener">Ver como Excel (B)</a>
        </div>

        <div id="eri-comp-resumen" class="row g-2 mb-3"></div>

        <div class="table-responsive" style="max-height: 60vh; overflow:auto;">
          <table class="table table-sm table-bordered align-middle" id="eri-comp-table">
            <thead></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="offcanvas offcanvas-end" tabindex="-1" id="eriOrigenDrawer" aria-labelledby="eriOrigenDrawerLabel">
  <div class="offcanvas-header">
    <h5 id="eriOrigenDrawerLabel" class="offcanvas-title">ORIGEN DEL VALOR</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
  </div>
  <div class="offcanvas-body" id="eri-origen-body">
    <p class="text-muted">Selecciona una celda numérica para ver su trazabilidad.</p>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  const months = <?= json_encode($months, JSON_UNESCAPED_UNICODE) ?>;
  const tbody = document.getElementById('eri-tbody');
  const yearInput = document.getElementById('eri-anio');
  const partInput = document.getElementById('eri-participacion');
  const rentaInput = document.getElementById('eri-renta');
  const exportLink = document.getElementById('eri-exportar');
  const drawerBody = document.getElementById('eri-origen-body');
  const drawer = new bootstrap.Offcanvas('#eriOrigenDrawer');
  const compAlert = document.getElementById('eri-comparativo-alert');
  const compTipoA = document.getElementById('eri-comp-tipo-a');
  const compTipoB = document.getElementById('eri-comp-tipo-b');
  const compOnlyDiff = document.getElementById('eri-comp-only-diff');
  const compCompareBtn = document.getElementById('eri-comp-compare');
  const compExportCsvBtn = document.getElementById('eri-comp-export-csv');
  const compViewA = document.getElementById('eri-comp-view-a');
  const compViewB = document.getElementById('eri-comp-view-b');
  const compResumen = document.getElementById('eri-comp-resumen');
  const compTable = document.getElementById('eri-comp-table');
  const compTableHead = compTable ? compTable.querySelector('thead') : null;
  const compTableBody = compTable ? compTable.querySelector('tbody') : null;
  const isDebugMode = new URLSearchParams(window.location.search).get('debug') === '1' || ['localhost', '127.0.0.1'].includes(window.location.hostname);
  let currentRows = [];
  let currentComparativo = null;
  let currentMeta = null;

  const fmt = (value) => Number(value || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const fmtPct = (value) => `${Number(value || 0).toFixed(2)}%`;
  const round2 = (value) => Math.round((Number(value || 0) + Number.EPSILON) * 100) / 100;

  const parseNumberSafe = (value) => {
    if (value == null) return 0;
    if (typeof value === 'number') return Number.isFinite(value) ? value : 0;

    let text = String(value).trim();
    if (!text || text === '-') return 0;

    let isNegative = false;
    if (/^\(.*\)$/.test(text)) {
      isNegative = true;
      text = text.slice(1, -1).trim();
    }

    text = text.replace(/\s+/g, '').replace(/[^0-9,.-]/g, '');
    if (!text || text === '-' || text === ',' || text === '.') return 0;

    const hasComma = text.includes(',');
    const hasDot = text.includes('.');
    if (hasComma && hasDot) {
      if (text.lastIndexOf(',') > text.lastIndexOf('.')) {
        text = text.replace(/\./g, '').replace(',', '.');
      } else {
        text = text.replace(/,/g, '');
      }
    } else if (hasComma) {
      const commaCount = (text.match(/,/g) || []).length;
      text = commaCount > 1 ? text.replace(/,/g, '') : text.replace(',', '.');
    } else if (hasDot) {
      const dotCount = (text.match(/\./g) || []).length;
      if (dotCount > 1) {
        text = text.replace(/\./g, '');
      } else {
        const decimals = text.split('.').pop() || '';
        if (decimals.length === 3) {
          text = text.replace(/\./g, '');
        }
      }
    }

    const parsed = Number(text);
    if (!Number.isFinite(parsed)) return 0;
    return isNegative ? -Math.abs(parsed) : parsed;
  };

  const asNegative = (value) => {
    const n = parseNumberSafe(value);
    return n > 0 ? -n : n;
  };

  const normalizeLabel = (value) => String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .trim()
    .toUpperCase();

  const recalcEriCierre = (rows, inputs = {}) => {
    if (!Array.isArray(rows) || rows.length === 0) return rows;

    const tasaPart = parseNumberSafe(inputs.participacion) / 100;
    const tasaImp = parseNumberSafe(inputs.renta) / 100;
    const indexByDescription = rows.reduce((acc, row, idx) => {
      acc[idx] = normalizeLabel(row?.DESCRIPCION);
      return acc;
    }, {});

    const findRow = (matcher) => rows.find((_, idx) => matcher(indexByDescription[idx] || ''));
    const rowAntes = findRow((label) => label.includes('RESULTADO ANTES DE PARTICIPACION'));
    const rowPart = findRow((label) => label.includes('PARTICIPACION A TRABAJADORES'));
    const rowImp = findRow((label) => label.includes('IMPUESTO A LA RENTA'));
    const rowResultado = findRow((label) => label.includes('RESULTADO DEL PERIODO'));

    if (!rowAntes || !rowPart || !rowImp || !rowResultado) {
      return rows;
    }

    const partIndex = rows.indexOf(rowPart);
    const impIndex = rows.indexOf(rowImp);
    const partLabel = indexByDescription[partIndex] || '';
    const impLabel = indexByDescription[impIndex] || '';
    const shouldForcePart = partLabel.includes('(-)') || partLabel.includes('PARTICIPACION');
    const shouldForceImp = impLabel.includes('(-)') || impLabel.includes('IMPUESTO');

    rowResultado.__eriWarnings = {};

    months.forEach((month) => {
      const A = parseNumberSafe(rowAntes[month]);
      let Praw = parseNumberSafe(rowPart[month]);
      let Iraw = parseNumberSafe(rowImp[month]);

      if (Number.isFinite(tasaPart) && tasaPart >= 0 && Number.isFinite(tasaImp) && tasaImp >= 0) {
        Praw = A > 0 ? -round2(A * tasaPart) : 0;
        const base = A + Praw;
        Iraw = base > 0 ? -round2(base * tasaImp) : 0;
      }

      const P = shouldForcePart ? asNegative(Praw) : Praw;
      const I = shouldForceImp ? asNegative(Iraw) : Iraw;
      const R = round2(A + P + I);

      rowPart[month] = round2(P);
      rowImp[month] = round2(I);
      rowResultado[month] = R;

      const diff = Math.abs(R - (A + P + I));
      if (diff > 0.02) {
        console.warn('[ERI][VALIDATION] mismatch', { mes: month, A, P, I, R, diff });
        if (isDebugMode) {
          rowResultado.__eriWarnings[month] = true;
        }
      }
    });

    return rows;
  };

  const buildUrl = (format = 'json') => `api/eri/get_eri.php?periodo=${encodeURIComponent(yearInput.value || new Date().getFullYear())}&tasa_part=${encodeURIComponent((Number(partInput.value || 15) / 100).toString())}&tasa_renta=${encodeURIComponent((Number(rentaInput.value || 25) / 100).toString())}&format=${format}`;

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[ch]));

  const renderFormula = (formula = {}) => {
    if (formula.tipo !== 'SUMA' || !Array.isArray(formula.componentes)) {
      return `<p class="mb-0">${escapeHtml(formula.explicacion || '')}</p>`;
    }
    const lines = formula.componentes.map((item) => `<div class="d-flex justify-content-between"><span>${escapeHtml(item.codigo)}</span><strong>${fmt(item.valor || 0)}</strong></div>`).join('');
    const total = formula.componentes.reduce((acc, item) => acc + Number(item.valor || 0), 0);
    return `${lines}<hr class="my-2"><div class="d-flex justify-content-between"><span>TOTAL</span><strong>${fmt(total)}</strong></div>`;
  };

  const openTrace = async (codigo, descripcion, monthIndex, valorEri) => {
    const anio = Number(yearInput.value || new Date().getFullYear());
    const tipo = new URLSearchParams(window.location.search).get('tipo') || 'PRESUPUESTO';
    drawerBody.innerHTML = '<div class="text-muted">Cargando trazabilidad...</div>';
    drawer.show();

    const url = `api/eri/origen.php?anio=${encodeURIComponent(anio)}&codigo=${encodeURIComponent(codigo)}&mes=${encodeURIComponent(monthIndex)}&tipo=${encodeURIComponent(tipo)}`;
    const response = await fetch(url);
    const data = await response.json();
    if (!response.ok || !data.ok) {
      throw new Error(data.message || 'No se pudo obtener la trazabilidad.');
    }

    drawerBody.innerHTML = `
      <h6 class="mb-2">🔹 Resumen</h6>
      <ul class="list-unstyled small mb-3">
        <li><strong>Cuenta:</strong> ${escapeHtml(descripcion || data.descripcion || '-')}</li>
        <li><strong>Código:</strong> ${escapeHtml(data.codigo)}</li>
        <li><strong>Mes:</strong> ${escapeHtml(data.detalle?.mes_nombre || '')}</li>
        <li><strong>Valor ERI:</strong> ${fmt(valorEri)}</li>
        <li><strong>Tab origen:</strong> ${escapeHtml(data.origen?.tab || '')}</li>
        <li><strong>Hoja Excel original:</strong> ${escapeHtml(data.origen?.hoja_excel || '')}</li>
      </ul>

      <h6 class="mb-2">🔹 Detalle</h6>
      <div class="table-responsive mb-3">
        <table class="table table-sm table-bordered mb-0">
          <tbody>
            <tr><th>Archivo importado</th><td>${escapeHtml(data.detalle?.archivo || '')}</td></tr>
            <tr><th>Hoja</th><td>${escapeHtml(data.detalle?.hoja || '')}</td></tr>
            <tr><th>Código origen</th><td>${escapeHtml(data.codigo || '')}</td></tr>
            <tr><th>Mes</th><td>${escapeHtml(data.detalle?.mes_nombre || '')}</td></tr>
            <tr><th>Valor original</th><td>${fmt(data.detalle?.valor_original || 0)}</td></tr>
          </tbody>
        </table>
      </div>
      <a class="btn btn-sm btn-outline-primary mb-3" target="_blank" rel="noopener" href="${escapeHtml(data.detalle?.ver_excel_url || '#')}">👉 Ver como Excel</a>

      <h6 class="mb-2">🔹 Explicación automática</h6>
      <div class="small">${renderFormula(data.formula || {})}</div>
    `;
  };

  const renderRows = (rows) => {
    tbody.innerHTML = '';

    const rowTotalByIndex = rows.map((row) => months.reduce((acc, month) => acc + Number(row[month] || 0), 0));
    const blockTotals = {};

    rows.forEach((row, index) => {
      const code = String(row.CODE || '').trim();
      const block = code.charAt(0);
      if (!/[4-9]/.test(block)) {
        return;
      }
      if (String(row.TYPE || '').toUpperCase() === 'TOTAL') {
        blockTotals[block] = rowTotalByIndex[index];
      }
    });

    rows.forEach((row, index) => {
      const code = String(row.CODE || '').trim();
      const block = code.charAt(0);
      if (!/[4-9]/.test(block) || blockTotals[block] != null) {
        return;
      }
      blockTotals[block] = rows.reduce((acc, current, currentIndex) => {
        const currentCode = String(current.CODE || '').trim();
        const isSameBlock = currentCode.charAt(0) === block;
        const isDetail = String(current.TYPE || '').toUpperCase() === 'DETAIL';
        return acc + (isSameBlock && isDetail ? rowTotalByIndex[currentIndex] : 0);
      }, 0);
    });

    rows.forEach((row) => {
      const tr = document.createElement('tr');
      tr.classList.add(`eri-${String(row.TYPE || '').toLowerCase()}`);

      const tdCode = document.createElement('td');
      tdCode.textContent = row.CODE || '';
      tr.appendChild(tdCode);

      const tdDesc = document.createElement('td');
      tdDesc.textContent = row.DESCRIPCION || '';
      tr.appendChild(tdDesc);

      months.forEach((month) => {
        const tdVal = document.createElement('td');
        const value = Number(row[month] || 0);
        tdVal.classList.add('text-end', 'eri-cell-trace');
        const warningBadge = row.__eriWarnings?.[month] && isDebugMode
          ? '<span class="badge text-bg-warning ms-1" title="Revisar cálculo / datos">!</span>'
          : '';
        tdVal.innerHTML = `<span>${fmt(value)}</span>${warningBadge}<span class="eri-trace-icon" title="Ver origen">🔎</span>`;
        if (row.CODE) {
          tdVal.dataset.code = row.CODE;
          tdVal.dataset.desc = row.DESCRIPCION || '';
          tdVal.dataset.month = String(months.indexOf(month) + 1);
          tdVal.dataset.value = String(value);
        }
        tr.appendChild(tdVal);

        const tdPct = document.createElement('td');
        tdPct.textContent = fmt(row[`${month}_PCT`] || 0);
        tdPct.classList.add('text-end');
        tr.appendChild(tdPct);
      });

      const rowTotal = months.reduce((acc, month) => acc + Number(row[month] || 0), 0);
      const block = String(row.CODE || '').trim().charAt(0);
      const denominator = /[4-9]/.test(block) ? Number(blockTotals[block] || 0) : 0;
      const rowPct = denominator === 0 ? 0 : (rowTotal / denominator) * 100;

      const tdTotal = document.createElement('td');
      tdTotal.textContent = fmt(rowTotal);
      tdTotal.classList.add('text-end', 'eri-sticky-total');
      tr.appendChild(tdTotal);

      const tdTotalPct = document.createElement('td');
      tdTotalPct.textContent = fmtPct(rowPct);
      tdTotalPct.classList.add('text-end', 'eri-sticky-pct');
      tr.appendChild(tdTotalPct);

      tbody.appendChild(tr);
    });
  };

  tbody.addEventListener('click', (event) => {
    const cell = event.target.closest('td.eri-cell-trace[data-code]');
    if (!cell) return;
    openTrace(cell.dataset.code, cell.dataset.desc || '', Number(cell.dataset.month || 0), Number(cell.dataset.value || 0)).catch((e) => {
      drawerBody.innerHTML = `<div class="alert alert-danger py-2">${escapeHtml(e.message)}</div>`;
    });
  });


  const renderComparativoSummary = (resumen = {}) => {
    const cards = [
      ['Total claves', resumen.total_claves ?? 0],
      ['Con diferencias', resumen.con_diferencias ?? 0],
      ['Filas en tabla', Array.isArray(currentComparativo) ? currentComparativo.length : 0],
    ];
    compResumen.innerHTML = cards.map(([label, value]) => `
      <div class="col-md-2 col-sm-4 col-6">
        <div class="card shadow-sm h-100">
          <div class="card-body p-2 text-center">
            <div class="small text-muted">${escapeHtml(label)}</div>
            <div class="fw-bold">${escapeHtml(value)}</div>
          </div>
        </div>
      </div>`).join('');
  };

  const setViewExcelLinks = (meta = {}) => {
    const tab = String(meta?.tab || 'ingresos').toLowerCase();
    const tipoA = meta?.tipo_a || 'REAL';
    const tipoB = meta?.tipo_b || 'PRESUPUESTO';
    const logA = Number(meta?.import_log_id_a || 0);
    const logB = Number(meta?.import_log_id_b || 0);
    const hrefA = `?r=import-excel&action=view-excel&tab=${encodeURIComponent(tab)}&tipo=${encodeURIComponent(tipoA)}&import_log_id=${encodeURIComponent(logA)}`;
    const hrefB = `?r=import-excel&action=view-excel&tab=${encodeURIComponent(tab)}&tipo=${encodeURIComponent(tipoB)}&import_log_id=${encodeURIComponent(logB)}`;

    compViewA.classList.add('disabled');
    compViewB.classList.add('disabled');

    if (logA > 0) {
      compViewA.href = hrefA;
      compViewA.classList.remove('disabled');
    }
    if (logB > 0) {
      compViewB.href = hrefB;
      compViewB.classList.remove('disabled');
    }
  };

  const renderComparativoTable = (rows = []) => {
    if (!compTableHead || !compTableBody) return;

    compTableHead.innerHTML = '<tr><th>CLAVE</th><th>DESCRIPCION</th><th>CAMPO</th><th>A</th><th>B</th><th>DELTA</th></tr>';
    compTableBody.innerHTML = rows.map((row) => {
      const deltaValue = parseNumberSafe(row?.DELTA ?? 0);
      const deltaClass = Math.abs(deltaValue) < 0.000001 ? 'text-muted' : 'fw-bold eri-comp-cell-diff';
      return `<tr>
        <td>${escapeHtml(row?.CLAVE ?? '')}</td>
        <td>${escapeHtml(row?.DESCRIPCION ?? '')}</td>
        <td>${escapeHtml(row?.CAMPO ?? '')}</td>
        <td class="text-end">${escapeHtml(fmt(row?.VALOR_A ?? 0))}</td>
        <td class="text-end">${escapeHtml(fmt(row?.VALOR_B ?? 0))}</td>
        <td class="text-end ${deltaClass}">${escapeHtml(fmt(deltaValue))}</td>
      </tr>`;
    }).join('') || '<tr><td colspan="6" class="text-muted">Sin datos para mostrar.</td></tr>';
  };

  const exportComparativoCsv = async () => {
    if (!currentMeta) {
      alert('No hay comparativo para exportar.');
      return;
    }
    const params = new URLSearchParams({
      tab: currentMeta.tab || 'ERI',
      tipo_a: currentMeta.tipo_a || 'REAL',
      tipo_b: currentMeta.tipo_b || 'PRESUPUESTO',
      solo_diferencias: compOnlyDiff.checked ? '1' : '0',
      import_log_id_a: String(currentMeta.import_log_id_a || ''),
      import_log_id_b: String(currentMeta.import_log_id_b || ''),
    });

    const url = `api/importaciones/exportar_diferencias.php?${params.toString()}`;
    const response = await fetch(url, { headers: { 'Accept': 'text/csv,application/json' } });
    const contentType = String(response.headers.get('content-type') || '').toLowerCase();

    if (contentType.includes('application/json')) {
      const raw = await response.text();
      let payload = null;
      try {
        payload = JSON.parse(raw);
      } catch (error) {
        console.error('[COMPARATIVO][EXPORT] Respuesta inválida', { raw });
      }
      throw new Error(payload?.message || `Error HTTP ${response.status} al exportar diferencias.`);
    }

    if (!response.ok) {
      throw new Error(`Error HTTP ${response.status} al exportar diferencias.`);
    }

    const blob = await response.blob();
    const blobUrl = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = blobUrl;
    a.download = `comparativo_${(currentMeta.tab || 'tab').toLowerCase()}_${Date.now()}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(blobUrl);
  };

  const fetchJsonSafely = async (url) => {
    const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
    const raw = await response.text();
    let data;
    try {
      data = JSON.parse(raw);
    } catch (error) {
      console.error('[COMPARATIVO] Respuesta no JSON', { url, raw });
      throw new Error('El servidor devolvió una respuesta inválida. Revisa consola para más detalle.');
    }

    if (!response.ok || !data?.ok) {
      throw new Error(data?.message || `Error HTTP ${response.status}`);
    }
    return data;
  };

  const runComparativo = async () => {
    compAlert.innerHTML = '';
    const tipoA = String(compTipoA.value || 'REAL').trim().toUpperCase();
    const tipoB = String(compTipoB.value || 'PRESUPUESTO').trim().toUpperCase();
    const onlyDiff = compOnlyDiff.checked ? '1' : '0';
    const url = `api/importaciones/comparativo.php?tab=ERI&tipo_a=${encodeURIComponent(tipoA)}&tipo_b=${encodeURIComponent(tipoB)}&solo_diferencias=${onlyDiff}`;
    const data = await fetchJsonSafely(url);

    const allRows = Array.isArray(data?.diferencias) ? data.diferencias : [];
    currentComparativo = compOnlyDiff.checked
      ? allRows.filter((row) => Math.abs(parseNumberSafe(row?.DELTA ?? 0)) > 0.000001)
      : allRows;
    currentMeta = data?.meta || null;
    renderComparativoSummary(data.resumen || {});
    renderComparativoTable(currentComparativo);
    setViewExcelLinks(currentMeta || {});
  };

  const load = async () => {
    const response = await fetch(buildUrl('json'));
    const data = await response.json();
    if (!response.ok || !(data.success || data.SUCCESS)) {
      throw new Error(data.message || data.MESSAGE || 'No fue posible calcular ERI.');
    }
    currentRows = recalcEriCierre(data.rows || data.ROWS || [], {
      participacion: partInput.value,
      renta: rentaInput.value,
    });
    renderRows(currentRows);
    exportLink.href = buildUrl('xlsx');
  };

  document.getElementById('eri-recalcular').addEventListener('click', () => load().catch((e) => alert(e.message)));
  [partInput, rentaInput].forEach((input) => {
    input.addEventListener('input', () => {
      if (!Array.isArray(currentRows) || currentRows.length === 0) return;
      recalcEriCierre(currentRows, {
        participacion: partInput.value,
        renta: rentaInput.value,
      });
      renderRows(currentRows);
    });
  });

  if (compCompareBtn) {
    compCompareBtn.addEventListener('click', () => runComparativo().catch((e) => {
      compAlert.innerHTML = `<div class="alert alert-danger py-2 mb-0">${escapeHtml(e.message)}</div>`;
    }));
  }
  if (compOnlyDiff) {
    compOnlyDiff.addEventListener('change', () => runComparativo().catch((e) => {
      compAlert.innerHTML = `<div class="alert alert-danger py-2 mb-0">${escapeHtml(e.message)}</div>`;
    }));
  }
  if (compExportCsvBtn) {
    compExportCsvBtn.addEventListener('click', () => exportComparativoCsv().catch((e) => {
      compAlert.innerHTML = `<div class="alert alert-danger py-2 mb-0">${escapeHtml(e.message)}</div>`;
    }));
  }

  load().catch((e) => alert(e.message));
})();
</script>
