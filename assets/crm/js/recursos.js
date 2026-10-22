/* =========================================================
 *  GESTIÓN DE RECURSOS — pantalla /crm/panel/recursos
 * ======================================================= */
(function () {
  'use strict';

  var root = document.querySelector('.crm-recursos-admin');
  if (!root) return;

  var tbody      = document.getElementById('rec-tbody');
  var qEl        = document.getElementById('rec-q');
  var tipoEl     = document.getElementById('rec-tipo');
  var marcaEl    = document.getElementById('rec-marca');
  var activoEl   = document.getElementById('rec-activo');
  var pagPrev    = document.getElementById('rec-pag-prev');
  var pagNext    = document.getElementById('rec-pag-next');
  var pagInfo    = document.getElementById('rec-pag-info');

  var modalEl    = document.getElementById('rec-modal');
  var modal      = new bootstrap.Modal(modalEl);
  var modalTit   = document.getElementById('rec-modal-titulo');
  var fId        = document.getElementById('rec-f-id');
  var fMarca     = document.getElementById('rec-f-marca');
  var fModelo    = document.getElementById('rec-f-modelo');
  var fTipo      = document.getElementById('rec-f-tipo');
  var fSistema   = document.getElementById('rec-f-sistema');
  var fTitulo    = document.getElementById('rec-f-titulo');
  var fUrl       = document.getElementById('rec-f-url');
  var fPalabras  = document.getElementById('rec-f-palabras');
  var fNotas     = document.getElementById('rec-f-notas');
  var fActivo    = document.getElementById('rec-f-activo');
  var fProbar    = document.getElementById('rec-f-probar');
  var fGenerar   = document.getElementById('rec-f-generar');
  var fAvisoUrl  = document.getElementById('rec-f-url-aviso');
  var btnGuardar = document.getElementById('rec-f-guardar');

  var impModalEl = document.getElementById('rec-importar-modal');
  var impModal   = new bootstrap.Modal(impModalEl);
  var impTexto   = document.getElementById('rec-imp-texto');
  var impTipo    = document.getElementById('rec-imp-tipo');
  var impPreview = document.getElementById('rec-imp-preview');
  var impResumen = document.getElementById('rec-imp-resumen');
  var impResult  = document.getElementById('rec-imp-resultado');
  var impTbody   = document.getElementById('rec-imp-tbody');
  var impGuardar = document.getElementById('rec-imp-guardar');

  var filtros = { q: '', tipo: '', marca: '', activo: '', pagina: 1 };
  var paginas = 1;
  var filasImport = [];

  /* ============ helpers ============ */

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
  function post(url, data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return apiFetch(url, { method: 'POST', body: fd }).then(function (r){ return r.body; });
  }

  function esc(s) { return (s === null || s === undefined) ? '' : String(s); }

  function iconoTipo(t) {
    if (t === 'driver') return 'bi-cpu';
    if (t === 'video')  return 'bi-play-circle';
    if (t === 'manual') return 'bi-file-text';
    return 'bi-box';
  }

  /* ============ listado ============ */

  function cargarLista() {
    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">' +
      '<span class="spinner-border spinner-border-sm"></span> Cargando…</td></tr>';

    var url = CRM.siteUrl + 'crm/api/recursos_admin_listar'
      + '?q=' + encodeURIComponent(filtros.q)
      + '&tipo=' + encodeURIComponent(filtros.tipo)
      + '&marca=' + encodeURIComponent(filtros.marca)
      + '&activo=' + encodeURIComponent(filtros.activo)
      + '&pagina=' + filtros.pagina;

    get(url).then(function (res) {
      if (!res || !res.ok) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-danger text-center py-4">Error al cargar.</td></tr>';
        return;
      }
      var d = res.data;
      tbody.innerHTML = '';
      if (!d.recursos.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Sin recursos.</td></tr>';
      } else {
        d.recursos.forEach(function (r) { tbody.appendChild(fila(r)); });
      }
      paginas = d.paginas;
      pagInfo.textContent = 'Página ' + d.pagina + ' de ' + d.paginas + ' (' + d.total + ')';
      pagPrev.disabled = d.pagina <= 1;
      pagNext.disabled = d.pagina >= d.paginas;
    }).catch(function () {
      tbody.innerHTML = '<tr><td colspan="7" class="text-danger text-center py-4">Error de conexión.</td></tr>';
    });
  }

  function fila(r) {
    var tr = document.createElement('tr');
    if (!r.activo) tr.classList.add('text-muted');

    var tdMarca = document.createElement('td');
    tdMarca.innerHTML = '<span class="badge bg-light text-dark border">' + esc(r.marca) + '</span>';
    tr.appendChild(tdMarca);

    var tdModelo = document.createElement('td');
    tdModelo.textContent = r.modelo;
    tr.appendChild(tdModelo);

    var tdTipo = document.createElement('td');
    tdTipo.innerHTML = '<i class="bi ' + iconoTipo(r.tipo) + '"></i> ' + esc(r.tipo);
    tr.appendChild(tdTipo);

    var tdTitulo = document.createElement('td');
    tdTitulo.className = 'text-truncate';
    tdTitulo.style.maxWidth = '260px';
    tdTitulo.innerHTML = '<a href="' + esc(r.url) + '" target="_blank" rel="noopener noreferrer">' +
      esc(r.titulo) + '</a>';
    tr.appendChild(tdTitulo);

    var tdEnv = document.createElement('td');
    tdEnv.className = 'text-end';
    tdEnv.textContent = r.veces_enviado;
    tr.appendChild(tdEnv);

    var tdEst = document.createElement('td');
    tdEst.className = 'text-center';
    tdEst.innerHTML = r.activo
      ? '<span class="badge bg-success">Activo</span>'
      : '<span class="badge bg-secondary">Inactivo</span>';
    tr.appendChild(tdEst);

    var tdAcc = document.createElement('td');
    tdAcc.className = 'text-end text-nowrap';
    tdAcc.innerHTML =
      '<button class="btn btn-sm btn-outline-primary me-1" data-acc="editar" title="Editar"><i class="bi bi-pencil"></i></button>' +
      '<button class="btn btn-sm btn-outline-secondary me-1" data-acc="toggle" title="Activar/Desactivar"><i class="bi bi-toggle-on"></i></button>' +
      '<button class="btn btn-sm btn-outline-secondary me-1" data-acc="duplicar" title="Duplicar"><i class="bi bi-files"></i></button>' +
      '<button class="btn btn-sm btn-outline-danger" data-acc="eliminar" title="Eliminar"><i class="bi bi-trash"></i></button>';
    tdAcc.querySelectorAll('button[data-acc]').forEach(function (b) {
      b.addEventListener('click', function () { accion(b.dataset.acc, r); });
    });
    tr.appendChild(tdAcc);

    return tr;
  }

  function accion(acc, r) {
    if (acc === 'editar') {
      modalTit.textContent = 'Editar recurso';
      fId.value       = r.id;
      fMarca.value    = r.marca || '';
      fModelo.value   = r.modelo || '';
      fTipo.value     = r.tipo || 'driver';
      fSistema.value  = r.sistema || '';
      fTitulo.value   = r.titulo || '';
      fUrl.value      = r.url || '';
      fPalabras.value = r.palabras_clave || '';
      fNotas.value    = r.notas || '';
      fActivo.checked = !!r.activo;
      fAvisoUrl.classList.add('d-none');
      modal.show();
    }
    else if (acc === 'toggle') {
      post(CRM.siteUrl + 'crm/api/recursos_admin_toggle/' + r.id, {})
        .then(function (res) {
          if (!res || !res.ok) { alert((res && res.error) || 'Error.'); return; }
          cargarLista();
        });
    }
    else if (acc === 'duplicar') {
      if (!confirm('¿Duplicar este recurso?')) return;
      post(CRM.siteUrl + 'crm/api/recursos_admin_duplicar/' + r.id, {})
        .then(function (res) {
          if (!res || !res.ok) { alert((res && res.error) || 'Error.'); return; }
          cargarLista();
        });
    }
    else if (acc === 'eliminar') {
      if (!confirm('¿Eliminar este recurso?\nSi ya fue enviado, solo podrás desactivarlo.')) return;
      post(CRM.siteUrl + 'crm/api/recursos_admin_eliminar/' + r.id, {})
        .then(function (res) {
          if (!res || !res.ok) { alert((res && res.error) || 'Error.'); return; }
          cargarLista();
        });
    }
  }

  /* ============ guardar ============ */

  document.getElementById('rec-btn-nuevo').addEventListener('click', function () {
    modalTit.textContent = 'Nuevo recurso';
    fId.value = 0;
    fMarca.value = ''; fModelo.value = ''; fTipo.value = 'driver';
    fSistema.value = ''; fTitulo.value = ''; fUrl.value = '';
    fPalabras.value = ''; fNotas.value = ''; fActivo.checked = true;
    fAvisoUrl.classList.add('d-none');
    modal.show();
  });

  fProbar.addEventListener('click', function () {
    var u = (fUrl.value || '').trim();
    if (!/^https?:\/\//i.test(u)) { alert('URL inválida.'); return; }
    window.open(u, '_blank', 'noopener,noreferrer');
  });

  fGenerar.addEventListener('click', function () {
    var base = (fModelo.value || '').trim();
    if (!base) { alert('Primero escribe el modelo.'); return; }

    var variantes = [];
    var min  = base.toLowerCase();
    var sinT = min.replace(/[áàäâã]/g,'a').replace(/[éèëê]/g,'e')
                 .replace(/[íìïî]/g,'i').replace(/[óòöôõ]/g,'o')
                 .replace(/[úùüû]/g,'u').replace(/ñ/g,'n').replace(/ç/g,'c');
    variantes.push(min);
    variantes.push(sinT);
    variantes.push(sinT.replace(/\s+/g, ''));
    variantes.push(sinT.replace(/[-\s]+/g, ''));
    variantes.push(sinT.replace(/-/g, ' ').replace(/\s+/g, ' '));

    var numeros = sinT.match(/\d{2,}[a-z]*/g);
    if (numeros && numeros.length) {
      numeros.forEach(function (n) { variantes.push(n); });
    }

    variantes = variantes.map(function (v) { return v.trim(); })
      .filter(function (v) { return v.length >= 2; });

    // únicos
    var vistos = {};
    variantes = variantes.filter(function (v) {
      if (vistos[v]) return false; vistos[v] = true; return true;
    });

    var actuales = (fPalabras.value || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    var todos = actuales.concat(variantes);
    var vistos2 = {};
    todos = todos.filter(function (v) {
      if (vistos2[v]) return false; vistos2[v] = true; return true;
    });
    fPalabras.value = todos.join(',');
  });

  btnGuardar.addEventListener('click', function () {
    var datos = {
      id:             fId.value || 0,
      marca:          fMarca.value.trim(),
      modelo:         fModelo.value.trim(),
      tipo:           fTipo.value,
      sistema:        fSistema.value.trim(),
      titulo:         fTitulo.value.trim(),
      url:            fUrl.value.trim(),
      palabras_clave: fPalabras.value,
      notas:          fNotas.value,
      activo:         fActivo.checked ? 1 : 0
    };
    if (!datos.marca || !datos.modelo || !datos.titulo || !datos.url) {
      alert('Marca, modelo, título y URL son obligatorios.');
      return;
    }
    btnGuardar.disabled = true;
    post(CRM.siteUrl + 'crm/api/recursos_admin_guardar', datos)
      .then(function (res) {
        btnGuardar.disabled = false;
        if (!res || !res.ok) { alert((res && res.error) || 'Error.'); return; }
        modal.hide();
        cargarLista();
      }).catch(function () {
        btnGuardar.disabled = false;
        alert('Error de conexión.');
      });
  });

  /* ============ importar ============ */

  document.getElementById('rec-btn-importar').addEventListener('click', function () {
    impTexto.value = '';
    impResult.classList.add('d-none');
    impResumen.textContent = '';
    filasImport = [];
    impModal.show();
  });

  impPreview.addEventListener('click', function () {
    var t = (impTexto.value || '').trim();
    if (!t) { alert('Pega el texto primero.'); return; }
    impPreview.disabled = true;

    post(CRM.siteUrl + 'crm/api/recursos_admin_importar', {
      texto: t,
      tipo_defecto: impTipo.value
    }).then(function (res) {
      impPreview.disabled = false;
      if (!res || !res.ok) { alert((res && res.error) || 'Error.'); return; }
      filasImport = res.data.filas || [];
      impResumen.textContent =
        res.data.resumen.nuevas + ' nuevas, ' +
        res.data.resumen.duplicadas + ' duplicadas, ' +
        res.data.resumen.errores + ' errores';
      impTbody.innerHTML = '';
      filasImport.forEach(function (f) {
        var tr = document.createElement('tr');
        var cls = f.estado === 'nueva' ? 'table-success' :
                  f.estado === 'duplicada' ? 'table-warning' : 'table-danger';
        tr.className = cls;
        tr.innerHTML =
          '<td>' + f.n + '</td>' +
          '<td>' + f.estado + '</td>' +
          '<td><code>' + esc(f.linea) + '</code>' +
            (f.error ? '<div class="small text-danger">' + esc(f.error) + '</div>' : '') +
          '</td>';
        impTbody.appendChild(tr);
      });
      impResult.classList.remove('d-none');
      impGuardar.disabled = !filasImport.some(function (f) { return f.estado === 'nueva'; });
    }).catch(function () {
      impPreview.disabled = false;
      alert('Error de conexión.');
    });
  });

  impGuardar.addEventListener('click', function () {
    var nuevas = filasImport.filter(function (f) { return f.estado === 'nueva'; });
    if (!nuevas.length) return;
    if (!confirm('¿Guardar ' + nuevas.length + ' recursos nuevos?')) return;

    impGuardar.disabled = true;
    var i = 0;
    function siguiente() {
      if (i >= nuevas.length) {
        impModal.hide();
        cargarLista();
        return;
      }
      var f = nuevas[i].datos;
      var datos = {
        id: 0,
        marca: f.marca,
        modelo: f.modelo,
        tipo: f.tipo,
        sistema: f.sistema,
        titulo: f.titulo,
        url: f.url,
        palabras_clave: '',
        notas: '',
        activo: 1
      };
      post(CRM.siteUrl + 'crm/api/recursos_admin_guardar', datos).then(function () {
        i++;
        siguiente();
      }).catch(function () {
        alert('Error al guardar la fila ' + (i + 1));
        impGuardar.disabled = false;
      });
    }
    siguiente();
  });

  /* ============ filtros / paginación ============ */

  var t = null;
  qEl.addEventListener('input', function () {
    clearTimeout(t);
    t = setTimeout(function () {
      filtros.q = qEl.value.trim();
      filtros.pagina = 1;
      cargarLista();
    }, 300);
  });
  tipoEl.addEventListener('change',   function () { filtros.tipo   = tipoEl.value;   filtros.pagina = 1; cargarLista(); });
  marcaEl.addEventListener('change',  function () { filtros.marca  = marcaEl.value;  filtros.pagina = 1; cargarLista(); });
  activoEl.addEventListener('change', function () { filtros.activo = activoEl.value; filtros.pagina = 1; cargarLista(); });
  pagPrev.addEventListener('click', function () { if (filtros.pagina > 1) { filtros.pagina--; cargarLista(); } });
  pagNext.addEventListener('click', function () { if (filtros.pagina < paginas) { filtros.pagina++; cargarLista(); } });

  /* ============ init ============ */
  cargarLista();
})();