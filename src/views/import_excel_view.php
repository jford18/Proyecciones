<?php
$excelView = $excelView ?? [];
$tipoView = (string) ($excelView['tipo'] ?? ($_GET['tipo'] ?? 'PRESUPUESTO'));
$tabView = (string) ($excelView['tab'] ?? ($_GET['tab'] ?? 'ingresos'));
$tabLabel = ucwords(str_replace('_', ' ', $tabView));
$fileNameView = (string) ($excelView['file_name'] ?? '');
$sheetNameView = (string) ($excelView['sheet_name'] ?? '');
$jsonPathView = (string) ($excelView['json_path'] ?? '');
$headersView = is_array($excelView['headers'] ?? null) ? $excelView['headers'] : [];
$rowsView = is_array($excelView['rows'] ?? null) ? $excelView['rows'] : [];
$messageView = (string) ($excelView['message'] ?? '');
$totalRows = count($rowsView);
$numericHeaders = ['ENE','FEB','MAR','ABR','MAY','JUN','JUL','AGO','SEP','OCT','NOV','DIC','TOTAL','TOTAL_RECALCULADO'];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Ver como Excel</title>
  <style>
    body{font-family:Segoe UI,Arial,sans-serif;margin:16px;background:#f6f8fb;color:#1f2937}
    .card{background:#fff;border:1px solid #d9dee5;border-radius:8px;padding:16px}
    .meta{font-size:14px;color:#4b5563;margin:0 0 10px}
    .alert{padding:10px 12px;border-radius:6px;border:1px solid #f3cf68;background:#fff8db;color:#7a5d00;margin:12px 0}
    .excel-grid-wrap{overflow:auto;max-height:76vh;border:1px solid #d9dee5;background:#fff}
    table{border-collapse:collapse;min-width:1400px;width:max-content;font-family:Consolas,Monaco,monospace;font-size:12px}
    th,td{border:1px solid #d9dee5;padding:6px 8px;white-space:nowrap}
    thead th{position:sticky;top:0;background:#eef3f8;z-index:2}
    td.num{text-align:right}
    td.txt{text-align:left}
  </style>
</head>
<body>
  <div class="card">
    <h3 style="margin-top:0;"><?= htmlspecialchars($tabLabel) ?> · Ver como Excel</h3>
    <p class="meta">Archivo: <strong><?= htmlspecialchars($fileNameView ?: '-') ?></strong> · Hoja: <strong><?= htmlspecialchars($sheetNameView ?: '-') ?></strong> · Tipo: <strong><?= htmlspecialchars($tipoView) ?></strong></p>
    <?php if ($jsonPathView !== ''): ?>
      <p class="meta">JSON_PATH: <code><?= htmlspecialchars($jsonPathView) ?></code> · Filas: <strong><?= $totalRows ?></strong></p>
    <?php endif; ?>

    <?php if ($messageView !== ''): ?>
      <div class="alert"><?= htmlspecialchars($messageView) ?></div>
    <?php endif; ?>

    <?php if ($headersView !== []): ?>
      <div class="excel-grid-wrap">
        <table>
          <thead>
            <tr>
              <?php foreach ($headersView as $header): ?>
                <th><?= htmlspecialchars((string) $header) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php if ($rowsView === []): ?>
              <tr><td colspan="<?= count($headersView) ?>" class="txt">Sin filas para mostrar.</td></tr>
            <?php else: ?>
              <?php foreach ($rowsView as $row): ?>
                <tr>
                  <?php foreach ($headersView as $header):
                    $value = $row[$header] ?? '';
                    $isNumeric = in_array(strtoupper((string) $header), $numericHeaders, true);
                  ?>
                    <td class="<?= $isNumeric ? 'num' : 'txt' ?>">
                      <?= $isNumeric && is_numeric($value) ? htmlspecialchars(number_format((float) $value, 2, ',', '.')) : htmlspecialchars((string) $value) ?>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
