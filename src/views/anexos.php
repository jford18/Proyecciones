<?php $data = $anexos; $rows = $data['rows']; $filters = $data['filters']; ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Anexos</li><li class="breadcrumb-item active">Ver anexos</li></ol></nav>
<div class="card shadow-sm"><div class="card-body">
  <form class="row g-2" method="get">
    <input type="hidden" name="r" value="anexos">
    <div class="col-md-2"><label class="form-label">Proyecto</label><select name="proyectoId" class="form-select"><option value="">Todos</option><?php foreach ($projectOptions as $id): ?><option value="<?= $id ?>" <?= ((string) ($filters['proyectoId'] ?? '') === (string) $id) ? 'selected' : '' ?>><?= $id ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Tipo anexo</label><select name="tipoAnexo" class="form-select"><option value="">Todos</option><option value="GASTOS" <?= ($filters['tipoAnexo'] ?? '') === 'GASTOS' ? 'selected' : '' ?>>GASTOS</option><option value="NOMINA" <?= ($filters['tipoAnexo'] ?? '') === 'NOMINA' ? 'selected' : '' ?>>NOMINA</option></select></div>
    <div class="col-md-2"><label class="form-label">Tipo</label><select name="tipo" class="form-select"><option value="">Todos</option><option value="PRESUPUESTO" <?= ($filters['tipo'] ?? '') === 'PRESUPUESTO' ? 'selected' : '' ?>>PRESUPUESTO</option><option value="REAL" <?= ($filters['tipo'] ?? '') === 'REAL' ? 'selected' : '' ?>>REAL</option></select></div>
    <div class="col-md-2"><label class="form-label">Mes</label><select name="mes" class="form-select"><option value="">Todos</option><?php for ($m=1; $m<=12; $m++): ?><option value="<?= $m ?>" <?= ((string) ($filters['mes'] ?? '') === (string) $m) ? 'selected' : '' ?>><?= $m ?></option><?php endfor; ?></select></div>
    <div class="col-md-4 d-flex gap-2 align-items-end"><button class="btn btn-primary" type="submit">Aplicar</button><a class="btn btn-outline-secondary" href="?r=anexos">Limpiar filtros</a></div>
  </form>
</div></div>
<div class="card mt-3 shadow-sm"><div class="card-body">
  <div class="table-responsive table-sticky"><table class="table table-sm table-striped">
    <thead><tr><th>ID</th><th>TIPO_ANEXO</th><th>TIPO</th><th>MES</th><th>PERIODO</th><th>CODIGO</th><th>CONCEPTO</th><th>DESCRIPCION</th><th>VALOR</th><th>ORIGEN_HOJA</th><th>ORIGEN_FILA</th></tr></thead>
    <tbody><?php foreach ($rows as $row): ?><tr>
      <td><?= (int) $row['ID'] ?></td><td><?= htmlspecialchars((string) $row['TIPO_ANEXO']) ?></td><td><?= htmlspecialchars((string) $row['TIPO']) ?></td><td><?= htmlspecialchars((string) $row['MES']) ?></td><td><?= htmlspecialchars((string) $row['PERIODO']) ?></td><td><?= htmlspecialchars((string) $row['CODIGO']) ?></td><td><?= htmlspecialchars((string) $row['CONCEPTO']) ?></td><td><?= htmlspecialchars((string) $row['DESCRIPCION']) ?></td><td>$<?= number_format((float) $row['VALOR'], 2, ',', '.') ?></td><td><?= htmlspecialchars((string) $row['ORIGEN_HOJA']) ?></td><td><?= htmlspecialchars((string) $row['ORIGEN_FILA']) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <div class="d-flex justify-content-between">
    <span class="text-muted">Página <?= $data['page'] ?> de <?= $data['totalPages'] ?> (<?= $data['total'] ?> registros)</span>
    <div class="btn-group">
      <?php $q = $_GET; $q['r'] = 'anexos'; ?>
      <?php if ($data['page'] > 1): $q['page']=$data['page']-1; ?><a class="btn btn-outline-secondary btn-sm" href="?<?= http_build_query($q) ?>">Anterior</a><?php endif; ?>
      <?php if ($data['page'] < $data['totalPages']): $q['page']=$data['page']+1; ?><a class="btn btn-outline-secondary btn-sm" href="?<?= http_build_query($q) ?>">Siguiente</a><?php endif; ?>
    </div>
  </div>
</div></div>
