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

<script>
(() => {
  const months = <?= json_encode($months, JSON_UNESCAPED_UNICODE) ?>;
  const tbody = document.getElementById('eri-tbody');
  const yearInput = document.getElementById('eri-anio');
  const partInput = document.getElementById('eri-participacion');
  const rentaInput = document.getElementById('eri-renta');
  const exportLink = document.getElementById('eri-exportar');

  const fmt = (value) => Number(value || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  const buildUrl = (format = 'json') => `api/eri/get_eri.php?periodo=${encodeURIComponent(yearInput.value || new Date().getFullYear())}&tasa_part=${encodeURIComponent((Number(partInput.value || 15) / 100).toString())}&tasa_renta=${encodeURIComponent((Number(rentaInput.value || 25) / 100).toString())}&format=${format}`;

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
        tdVal.textContent = fmt(value);
        tdVal.classList.add('text-end');
        tr.appendChild(tdVal);

        const tdPct = document.createElement('td');
        tdPct.textContent = fmt(row[`${month}_PCT`] || 0);
        tdPct.classList.add('text-end');
        tr.appendChild(tdPct);
      });
      tbody.appendChild(tr);
    });
  };

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
