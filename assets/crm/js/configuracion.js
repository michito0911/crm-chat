/* =========================================================
 *  CONFIGURACIÓN DEL CRM
 *  - CRUD de categorías, palabras clave y respuestas
 *  - Guardado de textos y límites generales
 * ======================================================= */
(function () {
  'use strict';

  var root = document.querySelector('.crm-config');
  if (!root) return;

  var CATEGORIAS = [];
  var PALABRAS   = [];
  var RESPUESTAS = [];

  var modalEl    = document.getElementById('crm-modal-edit');
  var modal      = new bootstrap.Modal(modalEl);
  var modalTit   = document.getElementById('crm-modal-edit-titulo');
  var modalBody  = document.getElementById('crm-modal-edit-body');
  var modalGuard = document.getElementById('crm-modal-edit-guardar');
  var modalAccion = null;

  // ============ helpers ============

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

  function get(url) { return apiFetch(url, { method: 'GET' }).then(function (r) { return r.body; }); }

  function post(url, data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return apiFetch(url, { method: 'POST', body: fd }).then(function (r) { return r.body; });
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
    el.style.minWidth = '240px';
    el.textContent = msg;
    cont.appendChild(el);
    setTimeout(function () { el.remove(); }, 2600);
  }

  // ============ CATEGORÍAS ============

  function cargarCategorias() {
    return get(CRM.siteUrl + 'crm/api/categorias').then(function (res) {
      if (!res || !res.ok) return;
      CATEGORIAS = res.data.categorias || [];
      renderCategorias();
      llenarSelectsCategorias();
    });
  }

  function llenarSelectsCategorias() {
    ['pal-filtro-cat', 'res-filtro-cat'].forEach(function (id) {
      var sel = document.getElementById(id);
      if (!sel) return;
      var v = sel.value;
      while (sel.options.length > 1) sel.remove(1);
      CATEGORIAS.forEach(function (c) {
        var o = document.createElement('option');
        o.value = c.id;
        o.textContent = c.nombre;
        sel.appendChild(o);
      });
      if (v) sel.value = v;
    });
  }

  function renderCategorias() {
    var tbody = document.getElementById('cat-tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    CATEGORIAS.forEach(function (c) {
      var tr = document.createElement('tr');

      var tdColor = document.createElement('td');
      var sw = document.createElement('span');
      sw.className = 'crm-color-swatch';
      sw.style.background = c.color;
      tdColor.appendChild(sw);
      tr.appendChild(tdColor);

      var tdNombre = document.createElement('td');
      tdNombre.textContent = c.nombre;
      if (parseInt(c.es_sistema, 10) === 1) {
        var b = document.createElement('span');
        b.className = 'badge bg-secondary ms-2';
        b.textContent = 'sistema';
        tdNombre.appendChild(b);
      }
      tr.appendChild(tdNombre);

      var tdOrden = document.createElement('td');
      tdOrden.textContent = c.orden;
      tr.appendChild(tdOrden);

      var tdActiva = document.createElement('td');
      var chk = document.createElement('span');
      chk.className = 'badge ' + (parseInt(c.activa, 10) ? 'bg-success' : 'bg-secondary');
      chk.textContent = parseInt(c.activa, 10) ? 'Sí' : 'No';
      tdActiva.appendChild(chk);
      tr.appendChild(tdActiva);

      var tdAcc = document.createElement('td');
      tdAcc.className = 'text-end';

      var btnEd = document.createElement('button');
      btnEd.className = 'btn btn-sm btn-outline-primary me-1';
      btnEd.innerHTML = '<i class="bi bi-pencil"></i>';
      btnEd.addEventListener('click', function () { editarCategoria(c); });
      tdAcc.appendChild(btnEd);

      if (parseInt(c.es_sistema, 10) !== 1) {
        var btnDel = document.createElement('button');
        btnDel.className = 'btn btn-sm btn-outline-danger';
        btnDel.innerHTML = '<i class="bi bi-trash"></i>';
        btnDel.addEventListener('click', function () { eliminarCategoria(c); });
        tdAcc.appendChild(btnDel);
      }

      tr.appendChild(tdAcc);
      tbody.appendChild(tr);
    });
  }

  function editarCategoria(c) {
    c = c || { id: 0, nombre: '', color: '#6c757d', orden: 100, activa: 1 };
    modalTit.textContent = c.id ? 'Editar categoría' : 'Nueva categoría';
    modalBody.innerHTML =
      '<div class="mb-2"><label class="form-label small">Nombre</label>' +
      '<input class="form-control" id="m-nombre" value="' + esc(c.nombre) + '" maxlength="80"></div>' +
      '<div class="row g-2 mb-2">' +
      '<div class="col-6"><label class="form-label small">Color</label>' +
      '<input type="color" class="form-control form-control-color" id="m-color" value="' + (c.color || '#6c757d') + '"></div>' +
      '<div class="col-6"><label class="form-label small">Orden</label>' +
      '<input type="number" class="form-control" id="m-orden" value="' + esc(c.orden) + '"></div>' +
      '</div>' +
      '<div class="form-check"><input class="form-check-input" type="checkbox" id="m-activa" ' + (parseInt(c.activa, 10) ? 'checked' : '') + '><label class="form-check-label" for="m-activa">Activa</label></div>';

    modalAccion = function () {
      return post(CRM.siteUrl + 'crm/api/categorias_guardar', {
        id: c.id || 0,
        nombre: document.getElementById('m-nombre').value,
        color: document.getElementById('m-color').value,
        orden: document.getElementById('m-orden').value,
        activa: document.getElementById('m-activa').checked ? 1 : 0
      });
    };
    modal.show();
  }

  function eliminarCategoria(c) {
    if (!confirm('¿Eliminar la categoría "' + c.nombre + '"?\nSe eliminarán también sus palabras y respuestas.')) return;
    post(CRM.siteUrl + 'crm/api/categorias_eliminar', { id: c.id }).then(function (res) {
      if (!res || !res.ok) { alert((res && res.error) || 'No se pudo eliminar.'); return; }
      toast('Categoría eliminada.');
      cargarCategorias().then(cargarPalabras).then(cargarRespuestas);
    });
  }

  // ============ PALABRAS CLAVE ============

  function cargarPalabras() {
    var filtro = document.getElementById('pal-filtro-cat');
    var cat = filtro ? filtro.value : '0';
    var url = CRM.siteUrl + 'crm/api/palabras';
    if (cat && cat !== '0') url += '?categoria_id=' + cat;
    return get(url).then(function (res) {
      if (!res || !res.ok) return;
      PALABRAS = res.data.palabras || [];
      renderPalabras();
    });
  }

  function nombreCategoria(id) {
    for (var i = 0; i < CATEGORIAS.length; i++) {
      if (parseInt(CATEGORIAS[i].id, 10) === parseInt(id, 10)) return CATEGORIAS[i].nombre;
    }
    return '—';
  }

  function renderPalabras() {
    var tbody = document.getElementById('pal-tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    PALABRAS.forEach(function (p) {
      var tr = document.createElement('tr');

      var tdC = document.createElement('td');
      tdC.textContent = nombreCategoria(p.categoria_id);
      tr.appendChild(tdC);

      var tdP = document.createElement('td');
      tdP.textContent = p.palabra;
      tr.appendChild(tdP);

      var tdW = document.createElement('td');
      var b = document.createElement('span');
      b.className = 'badge bg-info text-dark';
      b.textContent = p.peso;
      tdW.appendChild(b);
      tr.appendChild(tdW);

      var tdA = document.createElement('td');
      tdA.className = 'text-end';

      var ed = document.createElement('button');
      ed.className = 'btn btn-sm btn-outline-primary me-1';
      ed.innerHTML = '<i class="bi bi-pencil"></i>';
      ed.addEventListener('click', function () { editarPalabra(p); });
      tdA.appendChild(ed);

      var del = document.createElement('button');
      del.className = 'btn btn-sm btn-outline-danger';
      del.innerHTML = '<i class="bi bi-trash"></i>';
      del.addEventListener('click', function () { eliminarPalabra(p); });
      tdA.appendChild(del);

      tr.appendChild(tdA);
      tbody.appendChild(tr);
    });
  }

  function editarPalabra(p) {
    p = p || { id: 0, categoria_id: CATEGORIAS.length ? CATEGORIAS[0].id : 0, palabra: '', peso: 3 };
    modalTit.textContent = p.id ? 'Editar palabra' : 'Nueva palabra';
    var opts = CATEGORIAS.map(function (c) {
      return '<option value="' + c.id + '"' + (parseInt(c.id, 10) === parseInt(p.categoria_id, 10) ? ' selected' : '') + '>' + esc(c.nombre) + '</option>';
    }).join('');

    modalBody.innerHTML =
      '<div class="mb-2"><label class="form-label small">Categoría</label>' +
      '<select class="form-select" id="mp-cat">' + opts + '</select></div>' +
      '<div class="mb-2"><label class="form-label small">Palabra o frase</label>' +
      '<input class="form-control" id="mp-pal" value="' + esc(p.palabra) + '" maxlength="120"></div>' +
      '<div class="mb-2"><label class="form-label small">Peso (1 a 5)</label>' +
      '<input type="number" class="form-control" id="mp-peso" min="1" max="5" value="' + esc(p.peso) + '"></div>';

    modalAccion = function () {
      return post(CRM.siteUrl + 'crm/api/palabras_guardar', {
        id: p.id || 0,
        categoria_id: document.getElementById('mp-cat').value,
        palabra: document.getElementById('mp-pal').value,
        peso: document.getElementById('mp-peso').value
      });
    };
    modal.show();
  }

  function eliminarPalabra(p) {
    if (!confirm('¿Eliminar "' + p.palabra + '"?')) return;
    post(CRM.siteUrl + 'crm/api/palabras_eliminar', { id: p.id }).then(function (res) {
      if (!res || !res.ok) { alert((res && res.error) || 'No se pudo eliminar.'); return; }
      toast('Palabra eliminada.');
      cargarPalabras();
    });
  }

  // ============ RESPUESTAS ============

  function cargarRespuestas() {
    var filtro = document.getElementById('res-filtro-cat');
    var cat = filtro ? filtro.value : '0';
    var url = CRM.siteUrl + 'crm/api/respuestas';
    if (cat && cat !== '0') url += '?categoria_id=' + cat;
    return get(url).then(function (res) {
      if (!res || !res.ok) return;
      RESPUESTAS = res.data.respuestas || [];
      renderRespuestas();
    });
  }

  function renderRespuestas() {
    var cont = document.getElementById('res-lista');
    if (!cont) return;
    cont.innerHTML = '';
    if (!RESPUESTAS.length) {
      cont.innerHTML = '<div class="text-muted small fst-italic">Sin respuestas configuradas.</div>';
      return;
    }
    RESPUESTAS.forEach(function (r) {
      var col = document.createElement('div');
      col.className = 'col-12 col-lg-6';
      var card = document.createElement('div');
      card.className = 'crm-resp-card';

      var cab = document.createElement('div');
      cab.className = 'd-flex justify-content-between align-items-center mb-2';
      var t = document.createElement('div');
      t.className = 'fw-bold small';
      t.textContent = nombreCategoria(r.categoria_id);
      cab.appendChild(t);
      var bs = document.createElement('span');
      bs.className = 'badge ' + (parseInt(r.activa, 10) ? 'bg-success' : 'bg-secondary');
      bs.textContent = parseInt(r.activa, 10) ? 'Activa' : 'Inactiva';
      cab.appendChild(bs);
      card.appendChild(cab);

      var ta = document.createElement('textarea');
      ta.className = 'form-control form-control-sm mb-2';
      ta.value = r.texto;
      card.appendChild(ta);

      var acciones = document.createElement('div');
      acciones.className = 'd-flex justify-content-end gap-1';

      var btnGuardar = document.createElement('button');
      btnGuardar.className = 'btn btn-sm btn-primary';
      btnGuardar.innerHTML = '<i class="bi bi-check2"></i> Guardar';
      btnGuardar.addEventListener('click', function () {
        post(CRM.siteUrl + 'crm/api/respuestas_guardar', {
          id: r.id, categoria_id: r.categoria_id,
          texto: ta.value, activa: parseInt(r.activa, 10)
        }).then(function (res) {
          if (!res || !res.ok) { alert((res && res.error) || 'No se pudo guardar.'); return; }
          toast('Respuesta guardada.');
        });
      });
      acciones.appendChild(btnGuardar);

      var btnDel = document.createElement('button');
      btnDel.className = 'btn btn-sm btn-outline-danger';
      btnDel.innerHTML = '<i class="bi bi-trash"></i>';
      btnDel.addEventListener('click', function () {
        if (!confirm('¿Eliminar esta respuesta?')) return;
        post(CRM.siteUrl + 'crm/api/respuestas_eliminar', { id: r.id }).then(function (res) {
          if (!res || !res.ok) { alert((res && res.error) || 'No se pudo eliminar.'); return; }
          toast('Respuesta eliminada.');
          cargarRespuestas();
        });
      });
      acciones.appendChild(btnDel);
      card.appendChild(acciones);

      col.appendChild(card);
      cont.appendChild(col);
    });
  }

  function nuevaRespuesta() {
    modalTit.textContent = 'Nueva respuesta automática';
    var opts = CATEGORIAS.map(function (c) {
      return '<option value="' + c.id + '">' + esc(c.nombre) + '</option>';
    }).join('');
    modalBody.innerHTML =
      '<div class="mb-2"><label class="form-label small">Categoría</label>' +
      '<select class="form-select" id="mr-cat">' + opts + '</select></div>' +
      '<div class="mb-2"><label class="form-label small">Texto</label>' +
      '<textarea class="form-control" id="mr-texto" rows="5"></textarea></div>' +
      '<div class="form-check"><input class="form-check-input" type="checkbox" id="mr-activa" checked>' +
      '<label class="form-check-label" for="mr-activa">Activa</label></div>';

    modalAccion = function () {
      return post(CRM.siteUrl + 'crm/api/respuestas_guardar', {
        id: 0,
        categoria_id: document.getElementById('mr-cat').value,
        texto: document.getElementById('mr-texto').value,
        activa: document.getElementById('mr-activa').checked ? 1 : 0
      });
    };
    modal.show();
  }

  // ============ GUARDAR MODAL ============

  modalGuard.addEventListener('click', function () {
    if (typeof modalAccion !== 'function') return;
    modalGuard.disabled = true;
    modalAccion().then(function (res) {
      modalGuard.disabled = false;
      if (!res || !res.ok) { alert((res && res.error) || 'No se pudo guardar.'); return; }
      modal.hide();
      toast('Guardado.');
      cargarCategorias().then(function () {
        cargarPalabras();
        cargarRespuestas();
      });
    }).catch(function () {
      modalGuard.disabled = false;
      alert('Error de conexión.');
    });
  });

  // ============ CONFIGURACIÓN GENERAL ============

  function cargarGeneral() {
    return get(CRM.siteUrl + 'crm/api/configuracion').then(function (res) {
      if (!res || !res.ok) return;
      var c = res.data.config || {};
      setVal('cfg-texto_saludo_inicial', c.texto_saludo_inicial);
      setVal('cfg-texto_fuera_horario', c.texto_fuera_horario);
      setVal('cfg-texto_sin_clasificar', c.texto_sin_clasificar);
      setVal('cfg-texto_imagen_sin_texto', c.texto_imagen_sin_texto);
      setVal('cfg-texto_privacidad', c.texto_privacidad);
      setVal('cfg-horario_lv_inicio', c.horario_lv_inicio);
      setVal('cfg-horario_lv_fin', c.horario_lv_fin);
      setVal('cfg-horario_sa_inicio', c.horario_sa_inicio);
      setVal('cfg-horario_sa_fin', c.horario_sa_fin);
      setChk('cfg-horario_do_cerrado', c.horario_do_cerrado);
      setVal('cfg-max_largo_msj', c.max_largo_msj);
      setVal('cfg-max_mensajes_conversacion', c.max_mensajes_conversacion);
      setVal('cfg-max_msj_hora_conv', c.max_msj_hora_conv);
      setVal('cfg-max_msj_hora_ip', c.max_msj_hora_ip);
      setVal('cfg-max_img_mb', c.max_img_mb);
      setVal('cfg-img_ancho_max', c.img_ancho_max);
      setVal('cfg-polling_cliente_seg', c.polling_cliente_seg);
      setVal('cfg-polling_admin_seg', c.polling_admin_seg);
      setVal('cfg-bot_repetir_min', c.bot_repetir_min);
      setChk('cfg-sonido_admin', c.sonido_admin);
    });
  }

  function setVal(id, v) {
    var el = document.getElementById(id);
    if (el) el.value = (v === null || v === undefined) ? '' : v;
  }

  function setChk(id, v) {
    var el = document.getElementById(id);
    if (el) el.checked = (v === '1' || v === 1 || v === true || v === 'true');
  }

  function guardarGeneral() {
    var btn = document.getElementById('cfg-btn-guardar');
    if (btn) btn.disabled = true;

    var data = {
      horario_lv_inicio: (document.getElementById('cfg-horario_lv_inicio') || {}).value || '',
      horario_lv_fin:    (document.getElementById('cfg-horario_lv_fin')    || {}).value || '',
      horario_sa_inicio: (document.getElementById('cfg-horario_sa_inicio') || {}).value || '',
      horario_sa_fin:    (document.getElementById('cfg-horario_sa_fin')    || {}).value || '',
      horario_do_cerrado: document.getElementById('cfg-horario_do_cerrado').checked ? 1 : 0,
      texto_saludo_inicial:    (document.getElementById('cfg-texto_saludo_inicial')    || {}).value || '',
      texto_fuera_horario:     (document.getElementById('cfg-texto_fuera_horario')     || {}).value || '',
      texto_sin_clasificar:    (document.getElementById('cfg-texto_sin_clasificar')    || {}).value || '',
      texto_imagen_sin_texto:  (document.getElementById('cfg-texto_imagen_sin_texto')  || {}).value || '',
      texto_privacidad:        (document.getElementById('cfg-texto_privacidad')        || {}).value || '',
      max_mensajes_conversacion: (document.getElementById('cfg-max_mensajes_conversacion') || {}).value || 0,
      max_msj_hora_conv:      (document.getElementById('cfg-max_msj_hora_conv') || {}).value || 0,
      max_msj_hora_ip:        (document.getElementById('cfg-max_msj_hora_ip')   || {}).value || 0,
      max_img_mb:             (document.getElementById('cfg-max_img_mb')        || {}).value || 0,
      img_ancho_max:          (document.getElementById('cfg-img_ancho_max')     || {}).value || 0,
      max_largo_msj:          (document.getElementById('cfg-max_largo_msj')     || {}).value || 0,
      polling_cliente_seg:    (document.getElementById('cfg-polling_cliente_seg') || {}).value || 4,
      polling_admin_seg:      (document.getElementById('cfg-polling_admin_seg')   || {}).value || 4,
      bot_repetir_min:        (document.getElementById('cfg-bot_repetir_min')   || {}).value || 10,
      sonido_admin:           document.getElementById('cfg-sonido_admin').checked ? 1 : 0
    };

    post(CRM.siteUrl + 'crm/api/configuracion', data).then(function (res) {
      if (btn) btn.disabled = false;
      if (!res || !res.ok) { alert((res && res.error) || 'No se pudo guardar.'); return; }
      toast('Configuración guardada.');
    }).catch(function () {
      if (btn) btn.disabled = false;
      alert('Error de conexión.');
    });
  }

  // ============ EVENTOS ============

  var btnNuevaCat = document.getElementById('cat-btn-nueva');
  if (btnNuevaCat) btnNuevaCat.addEventListener('click', function () { editarCategoria(null); });

  var btnNuevaPal = document.getElementById('pal-btn-nueva');
  if (btnNuevaPal) btnNuevaPal.addEventListener('click', function () { editarPalabra(null); });

  var btnNuevaRes = document.getElementById('res-btn-nueva');
  if (btnNuevaRes) btnNuevaRes.addEventListener('click', nuevaRespuesta);

  var filtroPal = document.getElementById('pal-filtro-cat');
  if (filtroPal) filtroPal.addEventListener('change', cargarPalabras);

  var filtroRes = document.getElementById('res-filtro-cat');
  if (filtroRes) filtroRes.addEventListener('change', cargarRespuestas);

  var btnGuardarGen = document.getElementById('cfg-btn-guardar');
  if (btnGuardarGen) btnGuardarGen.addEventListener('click', guardarGeneral);

  // ============ INIT ============

  cargarCategorias().then(function () {
    cargarPalabras();
    cargarRespuestas();
  });
  cargarGeneral();
})();