/* =========================================================
 *  PANEL ADMIN — Bandeja de conversaciones
 *  - Polling de no leídos con sonido y contador en el título
 *  - Lista con filtros (estado, categoría, búsqueda)
 *  - Paginación
 * ======================================================= */
(function () {
  'use strict';

  var root = document.querySelector('.crm-panel');
  if (!root) return;

  var pollSeg   = parseInt(root.dataset.poll || '4', 10);
  var sonidoOn  = root.dataset.sonido === '1';

  var lista       = document.getElementById('crm-lista');
  var badgeTop    = document.getElementById('crm-badge-noleidos');
  var audio       = document.getElementById('crm-audio-notif');

  var cntTodas    = document.getElementById('cnt-todas');
  var cntAbierta  = document.getElementById('cnt-abierta');
  var cntAtencion = document.getElementById('cnt-en_atencion');
  var cntCerrada  = document.getElementById('cnt-cerrada');

  var busqueda    = document.getElementById('crm-busqueda');
  var busqLimpiar = document.getElementById('crm-busqueda-limpiar');

  var btnPrev     = document.getElementById('crm-pag-prev');
  var btnNext     = document.getElementById('crm-pag-next');
  var pagInfo     = document.getElementById('crm-pag-info');

  var filtros = {
    estado: 'todas',
    categoria_id: 0,
    q: '',
    pagina: 1
  };
  var paginas = 1;
  var noLeidosAnterior = -1;
  var tituloOriginal = document.title;

  // ============ helpers ============

  function apiGet(url) {
    return fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); });
  }

  function sonar() {
    if (!sonidoOn || !audio) return;
    audio.currentTime = 0;
    var p = audio.play();
    if (p && p.catch) p.catch(function () { /* sin permisos, silencio */ });
  }

  function actualizarTitulo(n) {
    if (n > 0) {
      document.title = '(' + n + ') ' + tituloOriginal;
    } else {
      document.title = tituloOriginal;
    }
  }

  function esc(s) {
    return (s === null || s === undefined) ? '' : String(s);
  }

  // ============ render de la lista ============

  function renderItem(c) {
    var a = document.createElement('a');
    a.className = 'crm-conv-item' + (c.no_leidos_admin > 0 ? ' unread' : '');
    a.href = CRM.siteUrl + 'crm/panel/conversacion/' + c.id;

    var av = document.createElement('div');
    av.className = 'crm-conv-avatar';
    av.textContent = (c.nombre || '?').trim().charAt(0).toUpperCase();
    a.appendChild(av);

    var info = document.createElement('div');
    info.className = 'crm-conv-info';

    var fila1 = document.createElement('div');
    fila1.className = 'd-flex align-items-center gap-2';

    var nombre = document.createElement('div');
    nombre.className = 'crm-conv-nombre text-truncate';
    nombre.textContent = esc(c.nombre);
    fila1.appendChild(nombre);

    if (c.urgente) {
      var bU = document.createElement('span');
      bU.className = 'badge bg-danger crm-badge-urgente';
      bU.innerHTML = '<i class="bi bi-exclamation-triangle"></i> URGENTE';
      fila1.appendChild(bU);
    }

    if (c.categoria_nombre) {
      var badgeCat = document.createElement('span');
      badgeCat.className = 'crm-conv-badge-cat';
      badgeCat.style.background = c.categoria_color || '#6c757d';
      badgeCat.textContent = c.categoria_nombre;
      fila1.appendChild(badgeCat);
    }

    if (c.pendiente_revision) {
      var bPend = document.createElement('span');
      bPend.className = 'badge bg-danger';
      bPend.textContent = 'Pendiente';
      fila1.appendChild(bPend);
    }

    info.appendChild(fila1);

    var fila2 = document.createElement('div');
    fila2.className = 'crm-conv-preview';
    var prefijo = (c.modo === 'bot' ? '🤖 ' : '👤 ');
    fila2.textContent = prefijo + esc(c.vista_previa || '');
    info.appendChild(fila2);

    a.appendChild(info);

    var meta = document.createElement('div');
    meta.className = 'crm-conv-meta';

    var hora = document.createElement('div');
    hora.className = 'crm-conv-hora';
    hora.textContent = esc(c.ultimo_relativo);
    meta.appendChild(hora);

    if (c.no_leidos_admin > 0) {
      var b = document.createElement('div');
      b.className = 'crm-conv-badge-unread';
      b.textContent = c.no_leidos_admin;
      meta.appendChild(b);
    }
    a.appendChild(meta);

    return a;
  }

  function cargarLista() {
    var url = CRM.siteUrl + 'crm/api/conversaciones'
      + '?estado=' + encodeURIComponent(filtros.estado)
      + '&categoria_id=' + filtros.categoria_id
      + '&q=' + encodeURIComponent(filtros.q)
      + '&pagina=' + filtros.pagina;

    lista.innerHTML = '<div class="text-center text-muted py-5">' +
      '<span class="spinner-border spinner-border-sm"></span> Cargando…</div>';

    apiGet(url).then(function (res) {
      if (!res || !res.ok) {
        lista.innerHTML = '<div class="alert alert-danger">Error al cargar.</div>';
        return;
      }
      var d = res.data;
      lista.innerHTML = '';
      if (!d.conversaciones.length) {
        var vacio = document.createElement('div');
        vacio.className = 'text-center text-muted py-5';
        vacio.textContent = 'Sin conversaciones para estos filtros.';
        lista.appendChild(vacio);
      } else {
        d.conversaciones.forEach(function (c) {
          lista.appendChild(renderItem(c));
        });
      }
      paginas = d.paginas;
      pagInfo.textContent = 'Página ' + d.pagina + ' de ' + d.paginas;
      btnPrev.disabled = d.pagina <= 1;
      btnNext.disabled = d.pagina >= d.paginas;
    }).catch(function () {
      lista.innerHTML = '<div class="alert alert-danger">Error de conexión.</div>';
    });
  }

  function cargarConteos() {
    apiGet(CRM.siteUrl + 'crm/api/conversaciones?estado=todas&pagina=1&q=')
      .then(function (res) {
        // Los conteos se obtienen aparte con no_leidos
      });
    // Conteos por estado vienen del server al inicio (esta página se
    // refrescará al recargar). Para el polling, solo el total de no leídos.
  }

  // ============ polling ============

  function consultarNoLeidos() {
    apiGet(CRM.siteUrl + 'crm/api/no_leidos').then(function (res) {
      if (!res || !res.ok) return;
      var total = res.data.total || 0;
      if (badgeTop) {
        badgeTop.textContent = total;
        badgeTop.classList.toggle('d-none', total === 0);
      }
      actualizarTitulo(total);
      if (noLeidosAnterior >= 0 && total > noLeidosAnterior) {
        sonar();
        cargarLista();
      }
      noLeidosAnterior = total;
    });
  }

  function programarNoLeidos() {
    if (document.hidden) return;
    setTimeout(function () {
      consultarNoLeidos();
      programarNoLeidos();
    }, pollSeg * 1000);
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      consultarNoLeidos();
    }
  });

  // ============ eventos ============

  document.querySelectorAll('.crm-chip').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.crm-chip').forEach(function (b) {
        b.classList.remove('active');
      });
      btn.classList.add('active');
      filtros.estado = btn.dataset.estado;
      filtros.pagina = 1;
      cargarLista();
    });
  });

  document.querySelectorAll('.crm-chip-cat').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.crm-chip-cat').forEach(function (b) {
        b.classList.remove('active');
      });
      btn.classList.add('active');
      filtros.categoria_id = parseInt(btn.dataset.categoriaId, 10) || 0;
      filtros.pagina = 1;
      cargarLista();
    });
  });

  var timerBusq = null;
  busqueda.addEventListener('input', function () {
    clearTimeout(timerBusq);
    timerBusq = setTimeout(function () {
      filtros.q = busqueda.value.trim();
      filtros.pagina = 1;
      cargarLista();
    }, 350);
  });

  busqLimpiar.addEventListener('click', function () {
    busqueda.value = '';
    filtros.q = '';
    filtros.pagina = 1;
    cargarLista();
  });

  btnPrev.addEventListener('click', function () {
    if (filtros.pagina > 1) {
      filtros.pagina--;
      cargarLista();
    }
  });

  btnNext.addEventListener('click', function () {
    if (filtros.pagina < paginas) {
      filtros.pagina++;
      cargarLista();
    }
  });

  // ============ init ============

  cargarLista();
  consultarNoLeidos();
  programarNoLeidos();
})();