<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$body_class = 'chat-body chat-body-inicio';
$this->load->view('crm/partials/head', array('titulo' => $titulo, 'body_class' => $body_class));
?>

<div class="chat-inicio-wrapper">
  <div class="chat-inicio-card shadow">

    <div class="chat-inicio-header">
      <i class="bi bi-headset chat-inicio-logo"></i>
      <h1 class="h4 mb-1">Atención al cliente</h1>
      <p class="text-muted small mb-0">
        Cuéntanos tu consulta. Responderemos lo antes posible.
      </p>
    </div>

    <?php if ($this->session->flashdata('error')): ?>
      <div class="alert alert-danger small mb-3">
        <?= crm_esc($this->session->flashdata('error')) ?>
      </div>
    <?php endif; ?>

<form method="post" action="<?= site_url('chat/iniciar') ?>" autocomplete="off" novalidate>
  <input type="hidden" name="<?= crm_esc($csrf['csrf_name']) ?>" value="<?= crm_esc($csrf['csrf_hash']) ?>">

      <div class="mb-3">
        <label class="form-label fw-semibold" for="nombre">
          Nombre <span class="text-danger">*</span>
        </label>
        <input type="text" class="form-control form-control-lg" id="nombre" name="nombre"
               required maxlength="120" placeholder="Ej: Juan Pérez">
      </div>

      <div class="mb-3">
        <label class="form-label fw-semibold" for="telefono">
          Teléfono <span class="text-muted small">(opcional)</span>
        </label>
        <input type="tel" class="form-control form-control-lg" id="telefono" name="telefono"
               maxlength="30" placeholder="Ej: 999 999 999">
      </div>

      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" value="1" id="acepto_datos" name="acepto_datos" required>
        <label class="form-check-label small text-muted" for="acepto_datos">
          <?= nl2br(crm_esc($texto_privacidad)) ?>
        </label>
      </div>

      <!-- Honeypot anti-spam: no lo ve el usuario, no lo llenar -->
      <div class="crm-honeypot" aria-hidden="true">
        <label>No llenar
          <input type="text" name="website" tabindex="-1" autocomplete="off">
        </label>
      </div>

      <button type="submit" class="btn btn-primary btn-lg w-100">
        <i class="bi bi-chat-dots"></i> Comenzar chat
      </button>
    </form>

    <p class="text-center text-muted small mt-3 mb-0">
      Horario de atención: lunes a sábado, 10:00 a 16:00.
    </p>
  </div>
</div>

<?php $this->load->view('crm/partials/pie', array('csrf' => $csrf)); ?>