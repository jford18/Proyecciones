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
      <div class="col-md-4 d-flex gap-2">
        <button id="eri-recalcular" class="btn btn-primary">Recalcular</button>
        <a id="eri-exportar" class="btn btn-outline-success" href="#" target="_blank" rel="noopener">Exportar Excel</a>
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
        </tr>
        </thead>
        <tbody id="eri-tbody"></tbody>
      </table>
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

  const fmt = (value) => Number(value || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

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
        tdVal.innerHTML = `<span>${fmt(value)}</span><span class="eri-trace-icon" title="Ver origen">🔎</span>`;
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

  const load = async () => {
    const response = await fetch(buildUrl('json'));
    const data = await response.json();
    if (!response.ok || !(data.success || data.SUCCESS)) {
      throw new Error(data.message || data.MESSAGE || 'No fue posible calcular ERI.');
    }
    renderRows(data.rows || data.ROWS || []);
    exportLink.href = buildUrl('xlsx');
  };

  document.getElementById('eri-recalcular').addEventListener('click', () => load().catch((e) => alert(e.message)));
  load().catch((e) => alert(e.message));
})();
</script>
