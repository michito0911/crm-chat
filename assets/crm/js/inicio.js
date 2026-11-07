/* =========================================================
 *  INICIO DEL CHAT — recupera el token guardado en este navegador
 *  (el teléfono por sí solo ya no permite entrar a una conversación)
 * ======================================================= */
(function () {
  'use strict';
  var campo = document.getElementById('inicio-token');
  if (!campo) return;
  try {
    var t = window.localStorage.getItem('crm_chat_token');
    if (t && /^[a-f0-9]{64}$/.test(t)) {
      campo.value = t;
    }
  } catch (e) { /* almacenamiento no disponible: se creará una conversación nueva */ }
})();
