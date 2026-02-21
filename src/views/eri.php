<?php
$months = ['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO','JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'];
$defaultYear = (int) ($eriDefaultYear ?? date('Y'));
?>
<div class="card">
  <div class="card-body">
    <h5 class="mb-3">📊 ERI – Estado de Resultados Integral</h5>
    <div class="row g-2 align-items-end mb-3">
      <div class="col-md-2">
        <label class="form-label">Año</label>
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
          <th class="text-center">CUENTA / DETALLE</th>
          <?php foreach ($months as $month): ?>
            <th class="text-center"><?= $month ?></th>
          <?php endforeach; ?>
          <th class="text-center">TOTAL</th>
        </tr>
        </thead>
        <tbody id="eri-tbody"></tbody>
      </table>
    </div>
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
  const tipo = <?= json_encode((string) ($activeTipo ?? 'PRESUPUESTO')) ?>;

  function formatNumber(value) {
    return Number(value || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function buildUrl(format = 'json') {
    const anio = encodeURIComponent(yearInput.value || new Date().getFullYear());
    const participacion = encodeURIComponent((Number(partInput.value || 15) / 100).toString());
    const renta = encodeURIComponent((Number(rentaInput.value || 25) / 100).toString());
    return `api/eri/get_eri.php?ANIO=${anio}&TIPO=${encodeURIComponent(tipo)}&TASA_PARTICIPACION=${participacion}&TASA_RENTA=${renta}&format=${format}`;
  }

  function renderRows(rows) {
    tbody.innerHTML = '';
    rows.forEach((row) => {
      const tr = document.createElement('tr');
      tr.classList.add(`eri-${String(row.TYPE || '').toLowerCase()}`);
      const tdLabel = document.createElement('td');
      tdLabel.textContent = row.LABEL || '';
      tr.appendChild(tdLabel);

      if (row.TYPE === 'HEADER') {
        tdLabel.colSpan = 14;
      } else {
        months.forEach((month) => {
          const td = document.createElement('td');
          const value = Number((row.M || {})[month] || 0);
          td.textContent = formatNumber(value);
          td.classList.add('text-end');
          if (value < 0) td.classList.add('eri-negativo');
          tr.appendChild(td);
        });
        const tdTotal = document.createElement('td');
        tdTotal.classList.add('text-end');
        const total = Number(row.TOTAL || 0);
        tdTotal.textContent = formatNumber(total);
        if (total < 0) tdTotal.classList.add('eri-negativo');
        tr.appendChild(tdTotal);
      }

      tbody.appendChild(tr);
    });
  }

  async function loadEri() {
    const response = await fetch(buildUrl('json'));
    const data = await response.json();
    if (!response.ok || !data.SUCCESS) {
      throw new Error(data.MESSAGE || 'No fue posible calcular ERI.');
    }
    renderRows(data.ROWS || []);
    exportLink.href = buildUrl('xlsx');
  }

  document.getElementById('eri-recalcular').addEventListener('click', async () => {
    try {
      await loadEri();
    } catch (e) {
      alert(e.message);
    }
  });

  loadEri().catch((e) => alert(e.message));
})();
</script>
