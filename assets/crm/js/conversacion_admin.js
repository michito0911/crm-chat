/* =========================================================
 *  CONVERSACIÓN (admin) + selector de recursos + urgencia
 *  - Polling de mensajes nuevos
 *  - Enviar texto / imagen
 *  - Cambiar estado / categoría / modo
 *  - Verificar / quitar verificación de comprobante
 *  - Selector de recursos (modal / bottom-sheet)
 *  - Badge de urgencia (mejora #7)
 * ======================================================= */
(function () {
  'use strict';

  var app = document.querySelector('.crm-conv-app');
  if (!app) return;

  var convId    = parseInt(app.dataset.convId, 10);
  var pollSeg   = parseInt(app.dataset.poll || '4', 10);
  var maxLargo  = parseInt(app.dataset.max || '2000', 10);
  var exigirVer = app.dataset.exigirVerificacion === '1';

  var cont       = document.getElementById('conv-mensajes');
  var txt        = document.getElementById('conv-input-texto');
  var btnEnv     = document.getElementById('conv-btn-enviar');
  var btnAdj     = document.getElementById('conv-btn-adjuntar');
  var fileIn     = document.getElementById('conv-input-file');
  var preview    = document.getElementById('conv-preview');
  var previewImg = document.getElementById('conv-preview-img');
  var previewX   = document.getElementById('conv-preview-cerrar');

  var badgeEstado = document.getElementById('conv-badge-estado');
  var badgeModo   = document.getElementById('conv-badge-modo');
  var badgeVerif  = document.getElementById('conv-badge-verif');
  var badgeVerifT = document.getElementById('conv-badge-verif-txt');
  var selCat      = document.getElementById('conv-categoria');

  var lateral     = document.getElementById('crm-conv-lateral');
  var btnInfo     = document.getElementById('conv-btn-info');
  var btnInfoX    = document.getElementById('conv-info-cerrar');

  var notaTxt     = document.getElementById('conv-nota-texto');
  var notaBtn     = document.getElementById('conv-nota-btn');
  var notasLista  = document.getElementById('conv-notas-lista');
  var notasVacio  = document.getElementById('conv-notas-vacio');

  var modalImg   = new bootstrap.Modal(document.getElementById('conv-img-modal'));
  var modalImgEl = document.getElementById('conv-img-modal-img');

  var modalVerif      = new bootstrap.Modal(document.getElementById('conv-verif-modal'));
  var verifTipo       = document.getElementById('conv-verif-tipo');
  var verifNumero     = document.getElementById('conv-verif-numero');
  var btnVerificar    = document.getElementById('conv-btn-verificar');
  var btnQuitarVerif  = document.getElementById('conv-btn-quitar-verif');
  var verifDetalle    = document.getElementById('conv-verif-detalle');

  var btnRecursos     = document.getElementById('conv-btn-recursos');
  var modalRecursosEl = document.getElementById('conv-recursos-modal');
  var modalRecursos   = new bootstrap.Modal(modalRecursosEl);
  var recAvisoVerif   = document.getElementById('conv-recursos-aviso-verif');
  var recVerifLink    = document.getElementById('conv-recursos-verif-link');
  var recSugWrap      = document.getElementById('conv-recursos-sugeridos-wrap');
  var recSugLista     = document.getElementById('conv-recursos-sugeridos');
  var recEquipo       = document.getElementById('conv-recursos-equipo');
  var recQ            = document.getElementById('conv-recursos-q');
  var recFiltros      = document.getElementById('conv-recursos-filtros');
  var recLista        = document.getElementById('conv-recursos-lista');
  var recCount        = document.getElementById('conv-recursos-count');
  var recIntro        = document.getElementById('conv-recursos-intro');
  var recEnviar       = document.getElementById('conv-recursos-enviar');
  var recN            = document.getElementById('conv-recursos-n');

  var ultimoId  = 0;
  var enviando  = false;
  var archivo   = null;
  var timer     = null;
  var cargando  = false;

  var verificacionActual  = app.dataset.verificacion || 'sin_verificar';
  var yaEnviados          = [];
  var seleccion           = {};
  var recursosCargados    = [];
  var sugeridosCargados   = [];

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

  function post(url, data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return apiFetch(url, { method: 'POST', body: fd });
  }

  function get(url) {
    return apiFetch(url, { method: 'GET' });
  }

  function autoscroll(forzar) {
    var alFondo = cont.scrollHeight - cont.scrollTop - cont.clientHeight < 120;
    if (alFondo || forzar) cont.scrollTop = cont.scrollHeight;
  }

  function esc(s) {
    return (s === null || s === undefined) ? '' : String(s);
  }

  // ============ render de mensajes ============

  function burbuja(m) {
    if (m.tipo === 'sistema') {
      var s = document.createElement('div');
      s.className = 'chat-msg chat-msg-sistema';
      s.textContent = m.texto || '';
      return s;
    }

    var wrap = document.createElement('div');
    wrap.className = 'chat-msg chat-msg-' + m.emisor;

    if (m.tipo === 'imagen' && m.archivo) {
      var img = document.createElement('img');
      img.className = 'chat-msg-img';
      img.src = m.archivo;
      img.alt = 'Imagen';
      img.loading = 'lazy';
      img.addEventListener('click', function () {
        modalImgEl.src = m.archivo;
        modalImg.show();
      });
      wrap.appendChild(img);
    }

    if (m.texto) {
      var t = document.createElement('div');
      t.className = 'chat-msg-texto';
      if (m.texto_html) {
        t.innerHTML = m.texto_html;
      } else {
        t.textContent = m.texto;
      }
      wrap.appendChild(t);
    }

    var h = document.createElement('div');
    h.className = 'chat-msg-hora';
    h.textContent = m.hora || '';
    wrap.appendChild(h);

    return wrap;
  }

  function appendMensajes(lista, forzarScroll) {
    if (!Array.isArray(lista) || !lista.length) return;
    var nuevo = false;
    lista.forEach(function (m) {
      if (m.id <= ultimoId) return;
      cont.appendChild(burbuja(m));
      ultimoId = m.id;
      nuevo = true;
    });
    if (nuevo) autoscroll(forzarScroll);
  }

  // ============ badges ============

  function marcarUrgenteUI() {
    var header = document.querySelector('.crm-conv-app .card-header');
    if (!header) return;
    if (header.querySelector('.crm-badge-urgente')) return;

    var b = document.createElement('span');
    b.className = 'badge bg-danger crm-badge-urgente';
    b.innerHTML = '<i class="bi bi-exclamation-triangle"></i> URGENTE';

    var contenedor = header.querySelector('.crm-conv-badges');
    if (contenedor) {
      contenedor.appendChild(b);
    } else {
      header.appendChild(b);
    }
  }

  function actualizarBadges(estado, modo, verificacion, urgente) {
    if (estado && badgeEstado) {
      badgeEstado.textContent = estado === 'abierta' ? 'Abierta'
        : estado === 'en_atencion' ? 'En atención'
        : estado === 'cerrada' ? 'Cerrada' : estado;
      badgeEstado.className = 'badge ' + (estado === 'abierta' ? 'bg-primary'
        : estado === 'en_atencion' ? 'bg-warning text-dark' : 'bg-secondary');
    }
    if (modo && badgeModo) {
      badgeModo.innerHTML = (modo === 'bot')
        ? '<i class="bi bi-robot"></i> Bot'
        : '<i class="bi bi-person"></i> Humano';
    }
    if (verificacion && badgeVerif && badgeVerifT) {
      verificacionActual = verificacion;
      app.dataset.verificacion = verificacion;
      var txtV = 'Sin verificar', bg = 'bg-secondary';
      if (verificacion === 'pendiente') { txtV = 'Pendiente verificación'; bg = 'bg-warning text-dark'; }
      if (verificacion === 'verificado'){ txtV = 'Verificado';              bg = 'bg-success'; }
      badgeVerif.className = 'badge ' + bg;
      badgeVerifT.textContent = txtV;
      pintarAccionesVerif();
    }
    // Urgencia: independiente del resto
    if (typeof urgente !== 'undefined' && parseInt(urgente, 10) === 1) {
      marcarUrgenteUI();
    }
  }

  function pintarAccionesVerif() {
    if (!btnVerificar) return;
    if (verificacionActual === 'verificado') {
      btnVerificar.classList.add('d-none');
      if (btnQuitarVerif) btnQuitarVerif.classList.remove('d-none');
      if (verifDetalle) verifDetalle.textContent = 'Comprobante verificado.';
    } else {
      btnVerificar.classList.remove('d-none');
      if (btnQuitarVerif) btnQuitarVerif.classList.add('d-none');
      if (verifDetalle) {
        verifDetalle.textContent = (verificacionActual === 'pendiente')
          ? 'El bot marcó que el cliente envió un comprobante. Verifícalo.'
          : 'Aún no has verificado el comprobante.';
      }
    }
    if (recAvisoVerif) {
      var debe = exigirVer && verificacionActual !== 'verificado';
      recAvisoVerif.classList.toggle('d-none', !debe);
      if (recEnviar) recEnviar.disabled = debe;
    }
  }

  // ============ polling ============

  function cargar(desde, forzarScroll) {
    if (cargando) return Promise.resolve();
    cargando = true;
    var url = CRM.siteUrl + 'crm/api/mensajes/' + convId + '?desde=' + (desde || 0);
    return apiFetch(url, { method: 'GET' }).then(function (res) {
      cargando = false;
      if (!res.body || !res.body.ok) return;
      var d = res.body.data;
      appendMensajes(d.mensajes, forzarScroll);
      if (d.ultimo_id > ultimoId) ultimoId = d.ultimo_id;
      actualizarBadges(d.estado, d.modo, d.verificacion_estado, d.urgente);
      if (d.equipo_marca || d.equipo_modelo) {
        actualizarBadgeEquipo(d.equipo_marca, d.equipo_modelo);
      }
      if (d.dni_cliente || d.ruc_cliente || d.comprobante_detectado || d.windows_version) {
        actualizarFichaUI(d);
      }
    }).catch(function () {
      cargando = false;
    });
  }

  function actualizarBadgeEquipo(marca, modelo) {
    var b = document.getElementById('conv-badge-equipo');
    var txt = ((marca || '') + ' ' + (modelo || '')).trim();
    if (!txt) return;
    if (!b) {
      b = document.createElement('span');
      b.className = 'badge bg-light text-dark border';
      b.id = 'conv-badge-equipo';
      var parent = document.querySelector('.crm-conv-badges');
      if (parent) parent.appendChild(b);
    }
    b.innerHTML = '<i class="bi bi-cpu"></i> ' + txt;
  }

  function actualizarFichaUI(d) {
    var header = document.querySelector('.crm-conv-app .card-header');
    if (!header) return;
    var contenedor = header.querySelector('.crm-conv-badges');
    if (!contenedor) return;

    function agregarChip(id, icono, label, valor) {
      if (!valor) return;
      if (contenedor.querySelector('#' + id)) return;
      var span = document.createElement('span');
      span.className = 'badge bg-light text-dark border';
      span.id = id;
      span.innerHTML = '<i class="bi ' + icono + '"></i> ' + label + ' ' + valor;
      contenedor.appendChild(span);
    }

    agregarChip('conv-chip-dni',      'bi-person-badge',   'DNI',        d.dni_cliente);
    agregarChip('conv-chip-ruc',      'bi-building',       'RUC',        d.ruc_cliente);
    agregarChip('conv-chip-comp',     'bi-receipt',        'Comp.',      d.comprobante_detectado);
    agregarChip('conv-chip-windows',  'bi-windows',        '',           d.windows_version);
  }

  function programar() {
    clearTimeout(timer);
    if (document.hidden) return;
    timer = setTimeout(function () {
      cargar(ultimoId, false).finally(programar);
    }, pollSeg * 1000);
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      cargar(ultimoId, false).finally(programar);
    } else {
      clearTimeout(timer);
    }
  });

  // ============ envío de texto / imagen ============

  function enviarTexto() {
    var valor = (txt.value || '').trim();
    if (!valor || enviando) return;
    enviando = true;
    btnEnv.disabled = true;

    post(CRM.siteUrl + 'crm/api/enviar/' + convId, { texto: valor })
      .then(function (res) {
        if (!res.body || !res.body.ok) {
          alert((res.body && res.body.error) || 'No se pudo enviar.');
          return;
        }
        txt.value = '';
        autoAlto();
        appendMensajes(res.body.data.mensajes, true);
        actualizarBadges(res.body.data.estado, res.body.data.modo, null, null);
      })
      .catch(function () { alert('Error de conexión.'); })
      .finally(function () {
        enviando = false;
        btnEnv.disabled = false;
        txt.focus();
      });
  }

  function enviarImagen() {
    if (!archivo || enviando) return;
    enviando = true;
    btnEnv.disabled = true;

    var fd = new FormData();
    fd.append('imagen', archivo);

    apiFetch(CRM.siteUrl + 'crm/api/enviar_imagen/' + convId, { method: 'POST', body: fd })
      .then(function (res) {
        if (!res.body || !res.body.ok) {
          alert((res.body && res.body.error) || 'No se pudo enviar la imagen.');
          return;
        }
        limpiarPreview();
        appendMensajes(res.body.data.mensajes, true);
      })
      .catch(function () { alert('Error de conexión.'); })
      .finally(function () {
        enviando = false;
        btnEnv.disabled = false;
      });
  }

  function limpiarPreview() {
    archivo = null;
    fileIn.value = '';
    previewImg.src = '';
    preview.classList.add('d-none');
  }

  if (btnAdj) {
    btnAdj.addEventListener('click', function () { fileIn.click(); });
  }
  if (fileIn) {
    fileIn.addEventListener('change', function () {
      var f = fileIn.files && fileIn.files[0];
      if (!f) return;
      if (!/^image\/(jpeg|png|webp)$/.test(f.type)) {
        alert('Formato no permitido. Usa JPG, PNG o WEBP.');
        fileIn.value = '';
        return;
      }
      if (f.size > 5 * 1024 * 1024) {
        alert('La imagen supera los 5 MB.');
        fileIn.value = '';
        return;
      }
      archivo = f;
      previewImg.src = URL.createObjectURL(f);
      preview.classList.remove('d-none');
    });
  }
  if (previewX) previewX.addEventListener('click', limpiarPreview);

  function autoAlto() {
    txt.style.height = 'auto';
    txt.style.height = Math.min(txt.scrollHeight, 120) + 'px';
  }

  if (txt) {
    txt.addEventListener('input', autoAlto);
    txt.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        if (archivo) { enviarImagen(); } else { enviarTexto(); }
      }
    });
  }
  if (btnEnv) {
    btnEnv.addEventListener('click', function () {
      if (archivo) { enviarImagen(); } else { enviarTexto(); }
    });
  }

  // ============ acciones (estado, categoría, modo) ============

  document.querySelectorAll('.crm-acc-estado').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var estado = btn.dataset.estado;
      post(CRM.siteUrl + 'crm/api/estado/' + convId, { estado: estado })
        .then(function (res) {
          if (!res.body || !res.body.ok) {
            alert((res.body && res.body.error) || 'No se pudo cambiar el estado.');
            return;
          }
          actualizarBadges(res.body.data.estado, null, null, null);
        })
        .catch(function () { alert('Error de conexión.'); });
    });
  });

  if (selCat) {
    selCat.addEventListener('change', function () {
      var id = parseInt(selCat.value, 10) || 0;
      post(CRM.siteUrl + 'crm/api/categoria/' + convId, { categoria_id: id })
        .then(function (res) {
          if (!res.body || !res.body.ok) {
            alert((res.body && res.body.error) || 'No se pudo cambiar la categoría.');
            return;
          }
          location.reload();
        })
        .catch(function () { alert('Error de conexión.'); });
    });
  }

  document.querySelectorAll('.crm-acc-modo').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var modo = btn.dataset.modo;
      post(CRM.siteUrl + 'crm/api/modo/' + convId, { modo: modo })
        .then(function (res) {
          if (!res.body || !res.body.ok) {
            alert((res.body && res.body.error) || 'No se pudo cambiar el modo.');
            return;
          }
          actualizarBadges(null, res.body.data.modo, null, null);
          if (modo === 'bot') setTimeout(function () { location.reload(); }, 400);
        })
        .catch(function () { alert('Error de conexión.'); });
    });
  });

  // ============ notas ============

  if (notaBtn) {
    notaBtn.addEventListener('click', function () {
      var texto = (notaTxt.value || '').trim();
      if (!texto) return;
      notaBtn.disabled = true;
      post(CRM.siteUrl + 'crm/api/nota/' + convId, { nota: texto })
        .then(function (res) {
          if (!res.body || !res.body.ok) {
            alert((res.body && res.body.error) || 'No se pudo guardar la nota.');
            return;
          }
          if (notasVacio) notasVacio.remove();
          var div = document.createElement('div');
          div.className = 'crm-nota';
          div.dataset.id = res.body.data.id;
          var t = document.createElement('div');
          t.className = 'small';
          t.textContent = res.body.data.nota;
          var f = document.createElement('div');
          f.className = 'text-muted crm-nota-fecha';
          f.textContent = 'hace un momento';
          div.appendChild(t);
          div.appendChild(f);
          notasLista.appendChild(div);
          notaTxt.value = '';
        })
        .catch(function () { alert('Error de conexión.'); })
        .finally(function () { notaBtn.disabled = false; });
    });
  }

  // ============ panel lateral móvil ============

  if (btnInfo && lateral) {
    btnInfo.addEventListener('click', function () { lateral.classList.toggle('abierto'); });
  }
  if (btnInfoX && lateral) {
    btnInfoX.addEventListener('click', function () { lateral.classList.remove('abierto'); });
  }

  // ============ verificación de comprobante ============

  if (btnVerificar) {
    btnVerificar.addEventListener('click', function () {
      verifTipo.value = '';
      verifNumero.value = '';
      modalVerif.show();
    });
  }

  var btnVerifGuardar = document.getElementById('conv-verif-guardar');
  if (btnVerifGuardar) {
    btnVerifGuardar.addEventListener('click', function () {
      btnVerifGuardar.disabled = true;
      post(CRM.siteUrl + 'crm/api/verificar/' + convId, {
        comprobante_tipo:   verifTipo.value,
        comprobante_numero: verifNumero.value
      }).then(function (res) {
        btnVerifGuardar.disabled = false;
        if (!res.body || !res.body.ok) {
          alert((res.body && res.body.error) || 'No se pudo verificar.');
          return;
        }
        modalVerif.hide();
        actualizarBadges(null, null, 'verificado', null);
      }).catch(function () {
        btnVerifGuardar.disabled = false;
        alert('Error de conexión.');
      });
    });
  }

  if (btnQuitarVerif) {
    btnQuitarVerif.addEventListener('click', function () {
      if (!confirm('¿Quitar la verificación del comprobante?')) return;
      post(CRM.siteUrl + 'crm/api/quitar_verificacion/' + convId, {})
        .then(function (res) {
          if (!res.body || !res.body.ok) {
            alert((res.body && res.body.error) || 'No se pudo quitar la verificación.');
            return;
          }
          actualizarBadges(null, null, 'sin_verificar', null);
        })
        .catch(function () { alert('Error de conexión.'); });
    });
  }

  // ============ SELECTOR DE RECURSOS ============

  var filtroTipoActual = '';

  function limpiarSeleccion() {
    seleccion = {};
    actualizarCount();
  }

  function actualizarCount() {
    var n = Object.keys(seleccion).length;
    if (recN) recN.textContent = n;
    if (recCount) recCount.textContent = n + ' recurso' + (n === 1 ? '' : 's') + ' seleccionado' + (n === 1 ? '' : 's');
  }

  function pintarRecursos() {
    recLista.innerHTML = '';
    var q = (recQ.value || '').trim().toLowerCase();
    var visibles = recursosCargados.filter(function (r) {
      if (filtroTipoActual && r.tipo !== filtroTipoActual) return false;
      if (q) {
        var blob = ((r.marca||'') + ' ' + (r.modelo||'') + ' ' + (r.titulo||'') + ' ' + (r.palabras_clave||'')).toLowerCase();
        if (blob.indexOf(q) === -1) return false;
      }
      return true;
    });

    if (!visibles.length) {
      recLista.innerHTML = '<div class="text-center text-muted py-4 small">Sin recursos para estos filtros.</div>';
      return;
    }

    var grupos = {};
    visibles.forEach(function (r) {
      var m = r.marca || '(sin marca)';
      if (!grupos[m]) grupos[m] = [];
      grupos[m].push(r);
    });

    Object.keys(grupos).sort().forEach(function (marca) {
      var h = document.createElement('div');
      h.className = 'crm-recursos-marca';
      h.textContent = marca;
      recLista.appendChild(h);

      grupos[marca].forEach(function (r) {
        recLista.appendChild(filaRecurso(r));
      });
    });
  }

  function iconoTipo(tipo) {
    if (tipo === 'driver') return 'bi-cpu';
    if (tipo === 'video')  return 'bi-play-circle';
    if (tipo === 'manual') return 'bi-file-text';
    return 'bi-box';
  }

  function filaRecurso(r) {
    var row = document.createElement('label');
    row.className = 'crm-recurso-item';
    if (seleccion[r.id]) row.classList.add('seleccionado');

    var chk = document.createElement('input');
    chk.type = 'checkbox';
    chk.className = 'form-check-input me-2';
    chk.checked = !!seleccion[r.id];
    chk.addEventListener('change', function () {
      if (chk.checked) seleccion[r.id] = true;
      else delete seleccion[r.id];
      row.classList.toggle('seleccionado', chk.checked);
      actualizarCount();
    });
    row.appendChild(chk);

    var info = document.createElement('div');
    info.className = 'crm-recurso-info';

    var t1 = document.createElement('div');
    t1.className = 'crm-recurso-titulo';
    t1.innerHTML = '<i class="bi ' + iconoTipo(r.tipo) + '"></i> ' + esc(r.titulo);
    info.appendChild(t1);

    var t2 = document.createElement('div');
    t2.className = 'crm-recurso-sub small text-muted';
    t2.textContent = (r.marca || '') + ' · ' + (r.modelo || '') + ' · ' + (r.tipo || '');
    info.appendChild(t2);

    if (yaEnviados.indexOf(r.id) !== -1) {
      var badge = document.createElement('div');
      badge.className = 'crm-recurso-ya';
      badge.innerHTML = '<i class="bi bi-check2"></i> ya enviado';
      info.appendChild(badge);
    }

    row.appendChild(info);
    return row;
  }

  function pintarSugeridos(equipo, lista) {
    recSugLista.innerHTML = '';
    if (!lista || !lista.length) {
      recSugWrap.classList.add('d-none');
      return;
    }
    recEquipo.textContent = equipo ? '(' + equipo + ')' : '';
    lista.forEach(function (r) {
      var row = document.createElement('label');
      row.className = 'crm-recurso-item crm-recurso-sugerido';
      if (seleccion[r.id]) row.classList.add('seleccionado');

      var chk = document.createElement('input');
      chk.type = 'checkbox';
      chk.className = 'form-check-input me-2';
      chk.checked = !!seleccion[r.id];
      chk.addEventListener('change', function () {
        if (chk.checked) seleccion[r.id] = true;
        else delete seleccion[r.id];
        row.classList.toggle('seleccionado', chk.checked);
        actualizarCount();
      });
      row.appendChild(chk);

      var info = document.createElement('div');
      info.className = 'crm-recurso-info';

      var t1 = document.createElement('div');
      t1.className = 'crm-recurso-titulo';
      t1.innerHTML = '<i class="bi ' + iconoTipo(r.tipo) + '"></i> ' + esc(r.titulo);
      info.appendChild(t1);

      var t2 = document.createElement('div');
      t2.className = 'crm-recurso-sub small text-muted';
      t2.textContent = (r.marca || '') + ' · ' + (r.modelo || '') + ' · ' + (r.tipo || '');
      info.appendChild(t2);

      if (yaEnviados.indexOf(r.id) !== -1) {
        var badge = document.createElement('div');
        badge.className = 'crm-recurso-ya';
        badge.innerHTML = '<i class="bi bi-check2"></i> ya enviado';
        info.appendChild(badge);
      }

      row.appendChild(info);
      recSugLista.appendChild(row);
    });
    recSugWrap.classList.remove('d-none');
  }

  function cargarRecursos() {
    recLista.innerHTML = '<div class="text-center text-muted py-4 small"><span class="spinner-border spinner-border-sm"></span> Cargando…</div>';
    get(CRM.siteUrl + 'crm/api/recursos_listar')
      .then(function (res) {
        if (!res.body || !res.body.ok) {
          recLista.innerHTML = '<div class="alert alert-danger small">Error al cargar recursos.</div>';
          return;
        }
        recursosCargados = res.body.data.recursos || [];
        pintarRecursos();
      }).catch(function () {
        recLista.innerHTML = '<div class="alert alert-danger small">Error de conexión.</div>';
      });
  }

  function cargarSugeridos() {
    get(CRM.siteUrl + 'crm/api/recursos_sugeridos/' + convId)
      .then(function (res) {
        if (!res.body || !res.body.ok) return;
        var d = res.body.data;
        sugeridosCargados = d.sugeridos || [];
        yaEnviados        = (d.ya_enviados || []).map(function (x) { return parseInt(x, 10); });
        var eq = ((d.equipo_marca || '') + ' ' + (d.equipo_modelo || '')).trim();
        pintarSugeridos(eq, sugeridosCargados);
      }).catch(function () { /* silencioso */ });
  }

  function abrirSelectorRecursos() {
    limpiarSeleccion();
    recQ.value = '';
    filtroTipoActual = '';
    Array.prototype.forEach.call(recFiltros.querySelectorAll('button'), function (b) {
      b.classList.toggle('active', (b.dataset.tipo || '') === '');
    });
    recIntro.value = (window.CRM && window.CRM.textoEnvioRecursos) ? window.CRM.textoEnvioRecursos : '';

    pintarAccionesVerif();
    cargarRecursos();
    cargarSugeridos();

    modalRecursos.show();
  }

  if (btnRecursos) {
    btnRecursos.addEventListener('click', abrirSelectorRecursos);
  }

  if (recVerifLink) {
    recVerifLink.addEventListener('click', function () {
      modalRecursos.hide();
      setTimeout(function () { modalVerif.show(); }, 300);
    });
  }

  if (recQ) {
    recQ.addEventListener('input', function () { pintarRecursos(); });
  }
  if (recFiltros) {
    recFiltros.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-tipo]');
      if (!b) return;
      Array.prototype.forEach.call(recFiltros.querySelectorAll('button'), function (x) {
        x.classList.remove('active');
      });
      b.classList.add('active');
      filtroTipoActual = b.dataset.tipo || '';
      pintarRecursos();
    });
  }

  if (recEnviar) {
    recEnviar.addEventListener('click', function () {
      var ids = Object.keys(seleccion);
      if (!ids.length) { alert('Selecciona al menos un recurso.'); return; }

      recEnviar.disabled = true;

      var fd = new FormData();
      ids.forEach(function (rid) { fd.append('recurso_ids[]', rid); });
      fd.append('intro', recIntro.value || '');

      apiFetch(CRM.siteUrl + 'crm/api/enviar_recursos/' + convId, { method: 'POST', body: fd })
        .then(function (res) {
          recEnviar.disabled = false;
          if (!res.body || !res.body.ok) {
            alert((res.body && res.body.error) || 'No se pudieron enviar los recursos.');
            return;
          }
          modalRecursos.hide();
          limpiarSeleccion();
          appendMensajes(res.body.data.mensajes, true);
          actualizarBadges(res.body.data.estado, res.body.data.modo, null, null);
        })
        .catch(function () {
          recEnviar.disabled = false;
          alert('Error de conexión.');
        });
    });
  }

  // ============ init ============

  window.CRM = window.CRM || {};
  window.CRM.textoEnvioRecursos = window.CRM.textoEnvioRecursos
    || 'Hola, ya verificamos tu comprobante. Te compartimos el material para tu equipo:';

  pintarAccionesVerif();
  cargar(0, true).finally(function () {
    autoscroll(true);
    programar();
    if (txt) txt.focus();
  });
})();