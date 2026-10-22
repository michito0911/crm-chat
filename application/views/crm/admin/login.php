<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$body_class = 'admin-body admin-body-login';
$this->load->view('crm/partials/head', array('titulo' => $titulo, 'body_class' => $body_class));
?>

<div class="admin-login-wrapper">
  <div class="admin-login-card shadow">

    <div class="text-center mb-4">
      <i class="bi bi-chat-dots-fill admin-login-logo"></i>
      <h1 class="h4 mt-2 mb-1">CRM Chat</h1>
      <p class="text-muted small mb-0">Acceso al panel de administración</p>
    </div>

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger small py-2 mb-3">
        <i class="bi bi-exclamation-triangle"></i> <?= crm_esc($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= site_url('crm/login') ?>" autocomplete="off">
      <input type="hidden" name="<?= crm_esc($csrf['csrf_name']) ?>" value="<?= crm_esc($csrf['csrf_hash']) ?>">

      <div class="mb-3">
        <label class="form-label" for="usuario">Usuario</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person"></i></span>
          <input type="text" class="form-control" id="usuario" name="usuario"
                 required maxlength="60" autofocus>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="password">Contraseña</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-lock"></i></span>
          <input type="password" class="form-control" id="password" name="password"
                 required maxlength="200">
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100">
        <i class="bi bi-box-arrow-in-right"></i> Ingresar
      </button>
    </form>
  </div>
</div>

<?php $this->load->view('crm/partials/pie', array('csrf' => $csrf)); ?>