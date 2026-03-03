<?php $workflow = $stats['workflow']; $step1 = $workflow['step1']; ?>
<h3>Resumen del proyecto</h3>
<div class="row g-3">
  <div class="col-md-4"><div class="card"><div class="card-body"><h5>Importar Excel (7 pestañas)</h5>
    <?php foreach ($step1['detail'] as $tipo => $info): ?><div><?= $tipo ?>: <?= $info['ok'] ? '✓' : '✗' ?> (<?= $info['total'] ?> registros)</div><?php endforeach; ?>
    <a class="btn btn-sm btn-primary mt-2" href="?r=import-excel">Abrir módulo</a>
  </div></div></div>
  <div class="col-md-4"><div class="card"><div class="card-body"><h5>ERI – Estado de Resultados Integral</h5><div class="text-muted">Vista consolidada</div><a class="btn btn-sm btn-primary mt-2" href="?r=eri">Abrir ERI</a></div></div></div>
  <div class="col-md-4"><div class="card"><div class="card-body"><h5>ERI Presupuesto / ERI Real</h5><div class="text-muted">Atajos de consulta</div><div class="d-flex gap-2 mt-2"><a class="btn btn-sm btn-outline-primary" href="?r=eri_presupuesto">Presupuesto</a><a class="btn btn-sm btn-outline-primary" href="?r=eri_real">Real</a></div></div></div></div>
</div>
