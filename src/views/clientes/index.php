<?php /** @var array $clientes */ ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Mantenimiento</li><li class="breadcrumb-item active">Clientes</li></ol></nav>

<div class="card shadow-sm">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">Mantenimiento de Clientes</h4>
      <button class="btn btn-primary" id="btnNuevoCliente" type="button"><i class="bi bi-plus-circle me-1"></i>Nuevo cliente</button>
    </div>

    <div id="clientesAlert" class="mb-3"></div>

    <div class="table-responsive">
      <table class="table table-striped align-middle" id="clientesTable" style="width:100%">
        <thead>
          <tr>
            <th>Empresa</th>
            <th>Gerente</th>
            <th>RUC</th>
            <th>Logo</th>
            <th>Estado</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($clientes as $cliente): ?>
            <tr data-id="<?= (int) $cliente['id'] ?>">
              <td><?= htmlspecialchars((string) $cliente['nombre_empresa']) ?></td>
              <td><?= htmlspecialchars((string) ($cliente['nombre_gerente'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string) $cliente['ruc']) ?></td>
              <td>
                <?php if (!empty($cliente['logo'])): ?>
                  <img src="<?= htmlspecialchars((string) $cliente['logo']) ?>" alt="Logo" class="img-thumbnail" style="width:64px;height:48px;object-fit:contain;">
                <?php else: ?>
                  <span class="text-muted small">Sin logo</span>
                <?php endif; ?>
              </td>
              <td>
                <?php $activo = strtoupper((string) ($cliente['estado'] ?? 'ACTIVO')) === 'ACTIVO'; ?>
                <span class="badge <?= $activo ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $activo ? 'Activo' : 'Inactivo' ?></span>
              </td>
              <td>
                <button type="button" class="btn btn-sm btn-outline-primary btn-editar" data-cliente='<?= htmlspecialchars(json_encode($cliente, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'>Editar</button>
                <button type="button" class="btn btn-sm btn-outline-danger btn-eliminar" data-id="<?= (int) $cliente['id'] ?>">Eliminar</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/modal_form.php'; ?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
  (() => {
    const table = $('#clientesTable').DataTable({
      responsive: true,
      language: {
        search: 'Buscar:',
        lengthMenu: 'Mostrar _MENU_ registros',
        info: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
        infoEmpty: 'Sin registros',
        paginate: { previous: 'Anterior', next: 'Siguiente' },
        zeroRecords: 'No se encontraron clientes'
      }
    });

    const modalEl = document.getElementById('clienteModal');
    const modal = new bootstrap.Modal(modalEl);
    const form = document.getElementById('clienteForm');
    const title = document.getElementById('clienteModalLabel');
    const alertBox = document.getElementById('clienteFormAlert');
    const pageAlert = document.getElementById('clientesAlert');
    const logoInput = document.getElementById('logo');
    const logoPreview = document.getElementById('logoPreview');
    const logoPlaceholder = document.getElementById('logoPreviewPlaceholder');

    function setAlert(target, text, type = 'danger') {
      target.innerHTML = `<div class="alert alert-${type} mb-0">${text}</div>`;
    }

    function clearAlert(target) {
      target.innerHTML = '';
    }

    function openNew() {
      form.reset();
      document.getElementById('clienteId').value = '';
      document.getElementById('clienteLogoActual').value = '';
      title.textContent = 'Nuevo cliente';
      logoPreview.style.display = 'none';
      logoPreview.src = '';
      logoPlaceholder.style.display = 'block';
      clearAlert(alertBox);
      modal.show();
    }

    function openEdit(cliente) {
      form.reset();
      clearAlert(alertBox);
      title.textContent = 'Editar cliente';
      document.getElementById('clienteId').value = cliente.id || '';
      document.getElementById('nombreEmpresa').value = cliente.nombre_empresa || '';
      document.getElementById('nombreGerente').value = cliente.nombre_gerente || '';
      document.getElementById('ruc').value = cliente.ruc || '';
      document.getElementById('estado').value = String(cliente.estado || 'ACTIVO').toUpperCase();
      document.getElementById('clienteLogoActual').value = cliente.logo || '';
      if (cliente.logo) {
        logoPreview.src = cliente.logo;
        logoPreview.style.display = 'inline-block';
        logoPlaceholder.style.display = 'none';
      } else {
        logoPreview.src = '';
        logoPreview.style.display = 'none';
        logoPlaceholder.style.display = 'block';
      }
      modal.show();
    }

    document.getElementById('btnNuevoCliente').addEventListener('click', openNew);

    document.getElementById('clientesTable').addEventListener('click', async (event) => {
      const editBtn = event.target.closest('.btn-editar');
      if (editBtn) {
        try {
          openEdit(JSON.parse(editBtn.dataset.cliente || '{}'));
        } catch (_) {}
        return;
      }

      const deleteBtn = event.target.closest('.btn-eliminar');
      if (!deleteBtn) return;
      const id = deleteBtn.dataset.id;
      if (!id) return;

      if (!confirm('¿Deseas eliminar este cliente? Esta acción no se puede deshacer.')) {
        return;
      }

      clearAlert(pageAlert);
      const response = await fetch(`?r=clientes/eliminar`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: new URLSearchParams({ id })
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        setAlert(pageAlert, payload.message || 'No se pudo eliminar el cliente.');
        return;
      }
      setAlert(pageAlert, payload.message || 'Cliente eliminado.', 'success');
      location.reload();
    });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearAlert(alertBox);
      const id = document.getElementById('clienteId').value.trim();
      const endpoint = id ? '?r=clientes/editar' : '?r=clientes/crear';
      const fd = new FormData(form);
      if (id) {
        fd.set('id', id);
      }
      const response = await fetch(endpoint, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        setAlert(alertBox, payload.message || 'No se pudo guardar el cliente.');
        return;
      }
      modal.hide();
      setAlert(pageAlert, payload.message || 'Cliente guardado correctamente.', 'success');
      location.reload();
    });

    logoInput.addEventListener('change', () => {
      const file = logoInput.files && logoInput.files[0];
      if (!file) {
        logoPreview.src = '';
        logoPreview.style.display = 'none';
        logoPlaceholder.style.display = 'block';
        return;
      }
      const reader = new FileReader();
      reader.onload = (e) => {
        logoPreview.src = e.target?.result || '';
        logoPreview.style.display = 'inline-block';
        logoPlaceholder.style.display = 'none';
      };
      reader.readAsDataURL(file);
    });
  })();
</script>
