document.getElementById('f').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const err = document.getElementById('e');
  err.textContent = '';
  try {
    await window.ammpDesktop.saveServer(document.getElementById('u').value);
  } catch (e) {
    err.textContent = String(e && e.message ? e.message : e).replace(/^Error invoking remote method '[^']+': (Error: )?/, '');
  }
});
