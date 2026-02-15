<h4>Reporte FLUJO (principal)</h4>
<table class="table table-sm table-striped"><thead><tr><th>Sección</th><th>Línea</th><?php for($m=1;$m<=12;$m++): ?><th><?= $m ?></th><?php endfor; ?></tr></thead><tbody>
<?php foreach ($flujo as $row): ?>
<tr><td><?= htmlspecialchars((string) $row['SECCION']) ?></td><td><?= htmlspecialchars((string) $row['NOMBRE']) ?></td><?php for($m=1;$m<=12;$m++): ?><td><?= number_format((float) ($row['meses'][$m] ?? 0), 2, ',', '.') ?></td><?php endfor; ?></tr>
<?php endforeach; ?>
</tbody></table>
