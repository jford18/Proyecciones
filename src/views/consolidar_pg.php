<h4>PASO 2 · Consolidar PG</h4>
<p>Consolida ANEXO_DETALLE hacia cuentas puente PG para el tipo seleccionado.</p>
<form method="post" action="?r=consolidar-pg">
  <button class="btn btn-primary" type="submit">Consolidar PG</button>
</form>
<?php if (!empty($pgPreview)): ?>
<div class="card mt-3"><div class="card-body"><h6>Resultado</h6><pre><?= htmlspecialchars((string) json_encode($pgPreview, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre></div></div>
<?php endif; ?>
