/* =========================================================
 *  Respuestas rápidas — CRUD en la pestaña de configuración
 * ======================================================= */
(function () {
  'use strict';

  var tbody = document.getElementById('rr-tbody');
  if (!tbody) return;

  var modalEl  = document.getElementById('crm-modal-edit');
  var modal    = new bootstrap.Modal(modalEl);
  var modalTit = document.getElementById('crm-modal-edit-titulo');
  var modalBody= document.getElementById('crm-modal-edit-body');
  var modalBtn = document.getElementById('crm-modal-edit-guardar');

  var accionGuardar = null;
  var datos = [];

  function apiFetch(url, options) {
    options = options || {};
    options.credentials = 'same-origin';
    options.headers = options.headers || {};
    options.headers['X-Requested-With'] = 'XMLHttpRequest';
    if (options.body instanceof FormData) {
      options.body.append(CRM.csrfName, CRM.csrfHash);
    }
    return fetch(url, options).then(function (r) {
      return r.json().then(function (j) { return { status: r.status, body: j }; });
    });
  }
  function get(url)  { return apiFetch(url, { method: 'GET' }).then(function (r){ return r.body; }); }
  function post(url, d) {
    var fd = new FormData();
    Object.keys(d).forEach(function (k) { fd.append(k, d[k]); });
    return apiFetch(url, { method: 'POST', body: fd }).then(function (r){ return r.body; });
  }
  function esc(s) { return (s === null || s === undefined) ? '' : String(s); }

  function toast(msg, tipo) {
    var cont = document.querySelector('.crm-toast-container');
    if (!cont) {
      cont = document.createElement('div');
      cont.className = 'crm-toast-container';
      document.body.appendChild(cont);
    }
    var el = document.createElement('div');
    el.className = 'alert alert-' + (tipo || 'success') + ' shadow-sm py-2 px-3 mb-2';
    el.textContent = msg;
    cont.appendChild(el);
    setTimeout(function(){ el.remove(); }, 2600);
  }

  function cargar() {
    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">' +
      '<span class="spinner-border spinner-border-sm"></span> Cargando…</td></tr>';
    get(CRM.siteUrl + 'crm/api/rr_listar').then(function (res) {
      if (!res || !res.ok) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-danger text-center py-3">Error</td></tr>';
        return;
      }
      datos = res.data.respuestas || [];
      tbody.innerHTML = '';
      if (!datos.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Sin respuestas rápidas.</td></tr>';
        return;
      }
      datos.forEach(function (r) { tbody.appendChild(fila(r)); });
    }).catch(function () {
      tbody.innerHTML = '<tr><td colspan="5" class="text-danger text-center py-3">Error de conexión.</td></tr>';
    });
  }

  function fila(r) {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><code>/' + esc(r.atajo) + '</code></td>' +
      '<td>' + esc(r.titulo) + '</td>' +
      '<td>' + esc(r.orden) + '</td>' +
      '<td><span class="badge ' + (parseInt(r.activa, 10) ? 'bg-success' : 'bg-secondary') + '">' +
        (parseInt(r.activa, 10) ? 'Sí' : 'No') + '</span></td>' +
      '<td class="text-end">' +
        '<button class="btn btn-sm btn-outline-primary me-1" data-acc="editar"><i class="bi bi-pencil"></i></button>' +
        '<button class="btn btn-sm btn-outline-danger" data-acc="eliminar"><i class="bi bi-trash"></i></button>' +
      '</td>';
    tr.querySelector('[data-acc="editar"]').addEventListener('click', function () { editar(r); });
    tr.querySelector('[data-acc="eliminar"]').addEventListener('click', function () { eliminar(r); });
    return tr;
  }

  function editar(r) {
    r = r || { id: 0, atajo: '', titulo: '', texto: '', activa: 1, orden: 100 };
    modalTit.textContent = r.id ? 'Editar respuesta rápida' : 'Nueva respuesta rápida';
    modalBody.innerHTML =
      '<div class="row g-2">' +
      '<div class="col-6"><label class="form-label small">Atajo</label>' +
      '<div class="input-group input-group-sm"><span class="input-group-text">/</span>' +
      '<input class="form-control" id="rr-atajo" maxlength="40" value="' + esc(r.atajo) + '"></div></div>' +
      '<div class="col-6"><label class="form-label small">Orden</label>' +
      '<input type="number" class="form-control form-control-sm" id="rr-orden" value="' + esc(r.orden) + '"></div>' +
      '<div class="col-12"><label class="form-label small">Título</label>' +
      '<input class="form-control form-control-sm" id="rr-titulo" maxlength="120" value="' + esc(r.titulo) + '"></div>' +
      '<div class="col-12"><label class="form-label small">Texto</label>' +
      '<textarea class="form-control form-control-sm" id="rr-texto" rows="5">' + esc(r.texto) + '</textarea></div>' +
      '<div class="col-12"><div class="form-check">' +
      '<input class="form-check-input" type="checkbox" id="rr-activa" ' + (parseInt(r.activa, 10) ? 'checked' : '') + '>' +
      '<label class="form-check-label small" for="rr-activa">Activa</label></div></div>' +
      '</div>';

    accionGuardar = function () {
      return post(CRM.siteUrl + 'crm/api/rr_guardar', {
        id: r.id || 0,
        atajo: document.getElementById('rr-atajo').value,
        titulo: document.getElementById('rr-titulo').value,
        texto: document.getElementById('rr-texto').value,
        activa: document.getElementById('rr-activa').checked ? 1 : 0,
        orden: document.getElementById('rr-orden').value
      });
    };
    modal.show();
  }

  function eliminar(r) {
    if (!confirm('¿Eliminar la respuesta "/' + r.atajo + '"?')) return;
    post(CRM.siteUrl + 'crm/api/rr_eliminar', { id: r.id }).then(function (res) {
      if (!res || !res.ok) { alert((res && res.error) || 'Error.'); return; }
      toast('Eliminada.');
      cargar();
    });
  }

  // Botón "Nueva"
  document.getElementById('rr-btn-nueva').addEventListener('click', function () { editar(null); });

  // Guardar desde modal
  modalBtn.addEventListener('click', function () {
    if (typeof accionGuardar !== 'function') return;
    modalBtn.disabled = true;
    accionGuardar().then(function (res) {
      modalBtn.disabled = false;
      if (!res || !res.ok) { alert((res && res.error) || 'Error.'); return; }
      modal.hide();
      toast('Guardada.');
      cargar();
    }).catch(function () {
      modalBtn.disabled = false;
      alert('Error de conexión.');
    });
  });

  cargar();
})();