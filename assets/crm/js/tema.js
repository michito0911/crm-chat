/* =========================================================
 *  Modo oscuro / claro (panel admin)
 * ======================================================= */
(function () {
  'use strict';

  function aplicarTema(tema) {
    if (tema === 'dark') {
      document.documentElement.setAttribute('data-bs-theme', 'dark');
    } else {
      document.documentElement.removeAttribute('data-bs-theme');
    }
    var icono = document.getElementById('crm-tema-icono');
    if (icono) {
      icono.className = (tema === 'dark') ? 'bi bi-sun' : 'bi bi-moon-stars';
    }
  }

  function temaActual() {
    try {
      return localStorage.getItem('crm_tema') || 'light';
    } catch(e) { return 'light'; }
  }

  document.addEventListener('DOMContentLoaded', function () {
    aplicarTema(temaActual());

    var btn = document.getElementById('crm-tema-toggle');
    if (btn) {
      btn.addEventListener('click', function () {
        var nuevo = (temaActual() === 'dark') ? 'light' : 'dark';
        try { localStorage.setItem('crm_tema', nuevo); } catch(e) {}
        aplicarTema(nuevo);
      });
    }
  });
})();