const form = document.getElementById('upload-form');
const result = document.getElementById('upload-result');

if (form) {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = new FormData(form);
    const res = await fetch('?r=upload-excel', { method: 'POST', body: data });
    const json = await res.json();
    if (json.ok) {
      result.textContent = `Subido: ${json.path}`;
    } else {
      result.textContent = `Error: ${json.message}`;
    }
  });
}
