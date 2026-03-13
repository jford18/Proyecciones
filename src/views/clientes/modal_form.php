<div class="modal fade" id="clienteModal" tabindex="-1" aria-labelledby="clienteModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="clienteForm" enctype="multipart/form-data">
        <div class="modal-header">
          <h5 class="modal-title" id="clienteModalLabel">Nuevo cliente</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="clienteId" name="id" value="">
          <input type="hidden" id="clienteLogoActual" name="logo_actual" value="">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nombre de la empresa *</label>
              <input type="text" class="form-control" name="nombre_empresa" id="nombreEmpresa" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Nombre del gerente</label>
              <input type="text" class="form-control" name="nombre_gerente" id="nombreGerente">
            </div>
            <div class="col-md-6">
              <label class="form-label">RUC *</label>
              <input type="text" class="form-control" name="ruc" id="ruc" maxlength="13" minlength="13" inputmode="numeric" required>
              <div class="form-text">Debe tener exactamente 13 números.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Estado</label>
              <select class="form-select" name="estado" id="estado">
                <option value="ACTIVO">Activo</option>
                <option value="INACTIVO">Inactivo</option>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label">Logo (JPG/PNG, máximo 2MB)</label>
              <input type="file" class="form-control" name="logo" id="logo" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
            </div>
            <div class="col-md-4 text-center">
              <label class="form-label d-block">Preview</label>
              <img id="logoPreview" src="" alt="Preview logo" class="img-thumbnail" style="max-width: 150px; max-height: 100px; display:none; object-fit: contain;">
              <div id="logoPreviewPlaceholder" class="small text-muted mt-2">Sin logo</div>
            </div>
          </div>
          <div id="clienteFormAlert" class="mt-3"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary" id="btnGuardarCliente">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>
