<?php $workflow = $stats['workflow']; $step1 = $workflow['step1']; $step2 = $workflow['step2']; $step3 = $workflow['step3']; ?>
<h3>Checklist del proyecto (orden obligatorio)</h3>
<div class="row g-3">
  <div class="col-md-4"><div class="card"><div class="card-body"><h5>Paso 1 · Anexos <?= $step1['ok'] ? '✓' : '✗' ?></h5>
    <?php foreach ($step1['detail'] as $tipo => $info): ?><div><?= $tipo ?>: <?= $info['ok'] ? '✓' : '✗' ?> (<?= $info['total'] ?> registros)</div><?php endforeach; ?>
    <a class="btn btn-sm btn-primary mt-2" href="?r=import-gastos">Ir al paso</a>
  </div></div></div>
  <div class="col-md-4"><div class="card"><div class="card-body"><h5>Paso 2 · PG consolidado <?= $step2['ok'] ? '✓' : '✗' ?></h5><div class="text-muted"><?= $step2['timestamp'] ?? 'Sin ejecutar' ?></div><a class="btn btn-sm btn-primary mt-2" href="?r=consolidar-pg">Ir al paso</a></div></div></div>
  <div class="col-md-4"><div class="card"><div class="card-body"><h5>Paso 3 · Flujo generado <?= $step3['ok'] ? '✓' : '✗' ?></h5><div class="text-muted"><?= $step3['timestamp'] ?? 'Sin ejecutar' ?></div><a class="btn btn-sm btn-primary mt-2" href="?r=generar-flujo">Ir al paso</a></div></div></div>
</div>
