<?php
$excelView = $excelView ?? [];
$tipoView = (string) ($excelView['tipo'] ?? ($activeTipo ?? 'PRESUPUESTO'));
$anioView = $excelView['anio'] ?? null;
$tabView = (string) ($excelView['tab'] ?? ($_GET['tab'] ?? 'ingresos'));
$tabLabel = ucfirst($tabView);
$columnsView = is_array($excelView['columns'] ?? null) ? $excelView['columns'] : ['PERIODO','CODIGO','NOMBRE_CUENTA','ENE','FEB','MAR','ABR','MAY','JUN','JUL','AGO','SEP','OCT','NOV','DIC','TOTAL'];
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Importaciones</li><li class="breadcrumb-item"><a href="?r=import-excel&tab=<?= urlencode($tabView) ?>&tipo=<?= urlencode($tipoView) ?>">Importar Excel</a></li><li class="breadcrumb-item active">Ver como Excel</li></ol></nav>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
      <h4 class="mb-0"><?= htmlspecialchars($tabLabel) ?> - Ver como Excel</h4>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="?r=import-excel&tab=<?= urlencode($tabView) ?>&tipo=<?= urlencode($tipoView) ?>">Volver</a>
        <a class="btn btn-success" id="downloadExcelBtn" href="?r=import-excel&action=export_xlsx&tab=<?= urlencode($tabView) ?>&tipo=<?= urlencode($tipoView) ?><?= $anioView ? '&anio=' . (int) $anioView : '' ?>">Descargar Excel</a>
      </div>
    </div>

    <form class="row g-2 align-items-end mb-3" method="get">
      <input type="hidden" name="r" value="import-excel">
      <input type="hidden" name="action" value="view_excel">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($tabView) ?>">
      <input type="hidden" name="tipo" value="<?= htmlspecialchars($tipoView) ?>">
      <div class="col-md-3">
        <label class="form-label">Año</label>
        <input class="form-control" type="number" name="anio" min="1900" max="2999" value="<?= $anioView ? (int) $anioView : '' ?>" placeholder="Ej: 2026">
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary w-100" type="submit">Cargar</button>
      </div>
    </form>

    <div id="excelGridAlert"></div>

    <div class="excel-grid-wrap">
      <table class="table table-sm table-bordered mb-0" id="excelGridTable">
        <thead>
          <tr>
            <?php foreach ($columnsView as $index => $column): ?>
              <th class="<?= $index <= 1 ? 'sticky-col sticky-col-' . ($index + 1) : '' ?>"><?= htmlspecialchars((string) $column) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody id="excelGridBody">
          <tr><td colspan="<?= count($columnsView) ?>" class="text-muted">Cargando...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<style>
.excel-grid-wrap { overflow: auto; max-height: 70vh; border: 1px solid #d9dee5; }
#excelGridTable { min-width: 1400px; }
#excelGridTable thead th { position: sticky; top: 0; z-index: 5; background: #f3f6fa; white-space: nowrap; }
#excelGridTable td, #excelGridTable th { white-space: nowrap; }
#excelGridTable td.num { text-align: right; }
#excelGridTable td.text { text-align: left; }
#excelGridTable th.sticky-col, #excelGridTable td.sticky-col { position: sticky; z-index: 4; background: #fff; }
#excelGridTable th.sticky-col-1, #excelGridTable td.sticky-col-1 { left: 0; min-width: 110px; }
#excelGridTable th.sticky-col-2, #excelGridTable td.sticky-col-2 { left: 110px; min-width: 130px; }
#excelGridTable th.sticky-col-3, #excelGridTable td.sticky-col-3 { left: 240px; min-width: 360px; }
#excelGridTable thead th.sticky-col { z-index: 7; background: #e8edf4; }
</style>

<script>
(function () {
  const tipo = <?= json_encode($tipoView, JSON_UNESCAPED_UNICODE) ?>;
  const anio = <?= json_encode($anioView, JSON_UNESCAPED_UNICODE) ?>;
  const tab = <?= json_encode($tabView, JSON_UNESCAPED_UNICODE) ?>;
  const body = document.getElementById('excelGridBody');
  const alertBox = document.getElementById('excelGridAlert');
  const columns = <?= json_encode($columnsView, JSON_UNESCAPED_UNICODE) ?>;

  function esc(text) {
    return String(text ?? '').replace(/[&<>'"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[ch]));
  }

  function formatEs(value) {
    return Number(value ?? 0).toLocaleString('es-EC', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function levelPadding(codigo) {
    const len = String(codigo ?? '').trim().length;
    if (len === 5) return 12;
    if (len === 7) return 24;
    if (len === 9) return 36;
    return 0;
  }

  async function loadRows() {
    const url = `?r=import-excel&action=preview_db&tab=${encodeURIComponent(tab)}&tipo=${encodeURIComponent(tipo)}${anio ? `&anio=${encodeURIComponent(anio)}` : ''}`;
    try {
      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      const payload = await res.json();
      if (!res.ok || payload.ok !== true) {
        throw new Error(payload.message || 'No fue posible cargar la grilla.');
      }

      const rows = Array.isArray(payload.rows) ? payload.rows : [];
      alertBox.innerHTML = `<div class="alert alert-info py-2">Tipo: <strong>${esc(payload.tipo)}</strong> · Año: <strong>${esc(payload.anio)}</strong> · Registros: <strong>${rows.length}</strong></div>`;

      body.innerHTML = rows.map((row) => {
        const pad = levelPadding(row.CODIGO);
        const periodo = esc(row.PERIODO ?? payload.anio ?? '');
        return `
          <tr>
            <td class="sticky-col sticky-col-1 text">${periodo}</td>
            <td class="sticky-col sticky-col-2 text">${esc(row.CODIGO ?? '')}</td>
            <td class="sticky-col sticky-col-3 text" style="padding-left:${pad}px;">${esc(row.NOMBRE_CUENTA ?? '')}</td>
            <td class="num">${formatEs(row.ENE)}</td>
            <td class="num">${formatEs(row.FEB)}</td>
            <td class="num">${formatEs(row.MAR)}</td>
            <td class="num">${formatEs(row.ABR)}</td>
            <td class="num">${formatEs(row.MAY)}</td>
            <td class="num">${formatEs(row.JUN)}</td>
            <td class="num">${formatEs(row.JUL)}</td>
            <td class="num">${formatEs(row.AGO)}</td>
            <td class="num">${formatEs(row.SEP)}</td>
            <td class="num">${formatEs(row.OCT)}</td>
            <td class="num">${formatEs(row.NOV)}</td>
            <td class="num">${formatEs(row.DIC)}</td>
            <td class="num"><strong>${formatEs(row.TOTAL)}</strong></td>
          </tr>
        `;
      }).join('') || `<tr><td colspan="${columns.length}" class="text-muted">Sin datos para el criterio seleccionado.</td></tr>`;
    } catch (error) {
      alertBox.innerHTML = `<div class="alert alert-warning">${esc(error.message || 'Error cargando datos.')}</div>`;
      body.innerHTML = `<tr><td colspan="${columns.length}" class="text-muted">No se pudo cargar la grilla.</td></tr>`;
    }
  }

  loadRows();
})();
</script>
