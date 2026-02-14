function confirmImport(form, tipo, proyectoId, archivo) {
  const modalEl = document.getElementById('confirmModal');
  if (!modalEl) {
    return true;
  }

  const message = `Vas a importar ${tipo} para Proyecto ${proyectoId} usando archivo ${archivo}. ¿Continuar?`;
  modalEl.querySelector('.modal-body').textContent = message;
  const modal = new bootstrap.Modal(modalEl);
  modal.show();

  const confirmBtn = document.getElementById('confirmImportBtn');
  confirmBtn.onclick = () => {
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
      submitBtn.disabled = true;
      const label = submitBtn.dataset.loadingText || 'Procesando...';
      submitBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>${label}`;
    }
    modal.hide();
    form.submit();
  };

  return false;
}
