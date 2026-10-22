<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$body_class = 'chat-body chat-body-conv';
$this->load->view('crm/partials/head', array('titulo' => 'Chat', 'body_class' => $body_class));
?>

<div class="chat-app"
     data-token="<?= crm_esc($token) ?>"
     data-poll="<?= (int) $poll_seg ?>"
     data-max="<?= (int) $max_largo_msj ?>">

  <header class="chat-app-header">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-headset chat-app-header-icon"></i>
      <div>
        <div class="fw-bold">Atención al cliente</div>
        <div class="small text-white-50" id="chat-app-estado">Conectado</div>
      </div>
    </div>
  </header>

  <main class="chat-app-mensajes" id="chat-mensajes" aria-live="polite">
    <div class="chat-cargando text-center text-muted small py-3">
      <span class="spinner-border spinner-border-sm"></span> Cargando…
    </div>
  </main>

  <footer class="chat-app-input">

    <div class="chat-preview d-none" id="chat-preview">
      <img id="chat-preview-img" alt="Vista previa">
      <button type="button" class="chat-preview-cerrar" id="chat-preview-cerrar"
              title="Quitar imagen">×</button>
    </div>

    <div class="chat-input-fila">
      <button type="button" class="btn btn-light chat-btn-adjuntar" id="chat-btn-adjuntar"
              title="Adjuntar imagen" aria-label="Adjuntar imagen">
        <i class="bi bi-image"></i>
      </button>
      <input type="file" id="chat-input-file" accept="image/jpeg,image/png,image/webp" hidden>

      <textarea id="chat-input-texto" class="form-control"
                rows="1" placeholder="Escribe tu mensaje…"
                maxlength="<?= (int) $max_largo_msj ?>"></textarea>

      <button type="button" class="btn btn-primary chat-btn-enviar" id="chat-btn-enviar"
              title="Enviar" aria-label="Enviar mensaje">
        <i class="bi bi-send"></i>
      </button>
    </div>
  </footer>
</div>

<!-- Modal para ver imagen ampliada -->
<div class="modal fade" id="chat-img-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content bg-dark">
      <div class="modal-body p-0 text-center">
        <img id="chat-img-modal-img" src="" alt="" class="img-fluid">
      </div>
    </div>
  </div>
</div>

<?php
$this->load->view('crm/partials/pie', array(
    'csrf'          => $csrf,
    'scripts_extra' => array('chat.js'),
));
?>