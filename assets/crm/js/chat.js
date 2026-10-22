/* =========================================================
 *  CHAT PÚBLICO — cliente
 *  - Polling cada N segundos (pausa si la pestaña no está visible)
 *  - Envío de texto e imagen con vista previa
 *  - Scroll automático
 * ======================================================= */
(function () {
  'use strict';

  var app = document.querySelector('.chat-app');
  if (!app) return;

  var token    = app.dataset.token;
  var pollSeg  = parseInt(app.dataset.poll || '4', 10);
  var maxLargo = parseInt(app.dataset.max || '2000', 10);

  var cont     = document.getElementById('chat-mensajes');
  var txt      = document.getElementById('chat-input-texto');
  var btnEnv   = document.getElementById('chat-btn-enviar');
  var btnAdj   = document.getElementById('chat-btn-adjuntar');
  var fileIn   = document.getElementById('chat-input-file');
  var preview  = document.getElementById('chat-preview');
  var previewI = document.getElementById('chat-preview-img');
  var btnPrevX = document.getElementById('chat-preview-cerrar');
  var estadoEl = document.getElementById('chat-app-estado');

  var modalImg   = new bootstrap.Modal(document.getElementById('chat-img-modal'));
  var modalImgEl = document.getElementById('chat-img-modal-img');

  var ultimoId  = 0;
  var enviando  = false;
  var archivo   = null;
  var timer     = null;
  var cargando  = false;

  // ============ helpers ============

  function autoscroll(forzar) {
    if (!cont) return;
    var alFondo = cont.scrollHeight - cont.scrollTop - cont.clientHeight < 120;
    if (alFondo || forzar) {
      cont.scrollTop = cont.scrollHeight;
    }
  }

  function quitarLoader() {
    var loader = cont.querySelector('.chat-cargando');
    if (loader) loader.remove();
  }

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

  function apiPostForm(url, data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return apiFetch(url, { method: 'POST', body: fd });
  }

  function esc(s) {
    return (s === null || s === undefined) ? '' : String(s);
  }

  // ============ render ============

  function burbuja(m) {
    if (m.tipo === 'sistema') {
      var s = document.createElement('div');
      s.className = 'chat-msg chat-msg-sistema';
      s.textContent = esc(m.texto);
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
      t.textContent = m.texto;
      wrap.appendChild(t);
    }

    var h = document.createElement('div');
    h.className = 'chat-msg-hora';
    h.textContent = m.hora || '';
    wrap.appendChild(h);

    return wrap;
  }

  function appendMensajes(lista) {
    if (!Array.isArray(lista) || !lista.length) return;
    var huboNuevo = false;
    lista.forEach(function (m) {
      if (m.id <= ultimoId) return;
      cont.appendChild(burbuja(m));
      ultimoId = m.id;
      huboNuevo = true;
    });
    if (huboNuevo) autoscroll(false);
  }

  // ============ polling ============

  function cargar(desde) {
    if (cargando) return Promise.resolve();
    cargando = true;
    var url = CRM.siteUrl + 'chat/mensajes?desde=' + (desde || 0) + '&t=' + encodeURIComponent(token);

    return apiFetch(url, { method: 'GET' }).then(function (res) {
      cargando = false;
      quitarLoader();                                  // ← SIEMPRE quita el loader
      if (!res.body || !res.body.ok) return;
      var d = res.body.data;
      if (typeof d.ultimo_id === 'number') {
        appendMensajes(d.mensajes);
        if (d.ultimo_id > ultimoId) ultimoId = d.ultimo_id;
      }
      if (estadoEl) {
        estadoEl.textContent = (d.modo === 'humano') ? 'Un asesor te atiende' : 'Bot activo';
      }
    }).catch(function () {
      cargando = false;
      quitarLoader();
    });
  }

  function programar() {
    clearTimeout(timer);
    if (document.hidden) return;
    timer = setTimeout(function () {
      cargar(ultimoId).finally(programar);
    }, pollSeg * 1000);
  }

  // ============ envío ============

  function enviarTexto() {
    var valor = (txt.value || '').trim();
    if (!valor || enviando) return;

    enviando = true;
    btnEnv.disabled = true;

    apiPostForm(CRM.siteUrl + 'chat/enviar', { texto: valor, t: token })
      .then(function (res) {
        if (!res.body || !res.body.ok) {
          alert((res.body && res.body.error) || 'No se pudo enviar el mensaje.');
          return;
        }
        txt.value = '';
        autoAlto();
        appendMensajes(res.body.data.mensajes);
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
    fd.append('t', token);

    apiFetch(CRM.siteUrl + 'chat/subir_imagen', { method: 'POST', body: fd })
      .then(function (res) {
        if (!res.body || !res.body.ok) {
          alert((res.body && res.body.error) || 'No se pudo enviar la imagen.');
          return;
        }
        limpiarPreview();
        appendMensajes(res.body.data.mensajes);
      })
      .catch(function () { alert('Error de conexión.'); })
      .finally(function () {
        enviando = false;
        btnEnv.disabled = false;
      });
  }

  // ============ preview ============

  function limpiarPreview() {
    archivo = null;
    fileIn.value = '';
    previewI.src = '';
    preview.classList.add('d-none');
  }

  btnAdj.addEventListener('click', function () { fileIn.click(); });

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
    previewI.src = URL.createObjectURL(f);
    preview.classList.remove('d-none');
  });

  btnPrevX.addEventListener('click', limpiarPreview);

  // ============ envío con Enter ============

  function autoAlto() {
    txt.style.height = 'auto';
    txt.style.height = Math.min(txt.scrollHeight, 120) + 'px';
  }

  txt.addEventListener('input', autoAlto);

  txt.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      if (archivo) { enviarImagen(); } else { enviarTexto(); }
    }
  });

  btnEnv.addEventListener('click', function () {
    if (archivo) { enviarImagen(); } else { enviarTexto(); }
  });

  // ============ ciclo de vida ============

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      cargar(ultimoId).finally(programar);
    } else {
      clearTimeout(timer);
    }
  });

  // Primera carga
  cargar(0).finally(function () {
    autoscroll(true);
    programar();
    txt.focus();
  });
})();