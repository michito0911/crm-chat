<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$body_class = 'admin-body admin-body-login';
$this->load->view('crm/partials/head', array('titulo' => $titulo, 'body_class' => $body_class));
?>

<div class="admin-login-wrapper">
  <div class="admin-login-card shadow">

    <div class="text-center mb-4">
      <i class="bi bi-shield-lock-fill admin-login-logo"></i>
      <h1 class="h4 mt-2 mb-1">
        <?= !empty($forzado) ? 'Cambia tu contraseña' : 'Cambiar contraseña' ?>
      </h1>
      <?php if (!empty($forzado)): ?>
        <p class="text-danger small mb-0">
          Es la primera vez que ingresas. Debes establecer una contraseña nueva (mínimo 12 caracteres).
        </p>
      <?php else: ?>
        <p class="text-muted small mb-0">Mínimo 12 caracteres.</p>
      <?php endif; ?>
    </div>

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger small py-2 mb-3">
        <i class="bi bi-exclamation-triangle"></i> <?= crm_esc($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= site_url('crm/cambiar_password') ?>" autocomplete="off">
      <input type="hidden" name="<?= crm_esc($csrf['csrf_name']) ?>" value="<?= crm_esc($csrf['csrf_hash']) ?>">

      <div class="mb-3">
        <label class="form-label" for="password_actual">Contraseña actual</label>
        <input type="password" class="form-control" id="password_actual"
               name="password_actual" required>
      </div>

      <div class="mb-3">
        <label class="form-label" for="password_nueva">Contraseña nueva</label>
        <input type="password" class="form-control" id="password_nueva"
               name="password_nueva" required minlength="12" maxlength="200">
      </div>

      <div class="mb-3">
        <label class="form-label" for="password_repite">Repite la contraseña nueva</label>
        <input type="password" class="form-control" id="password_repite"
               name="password_repite" required minlength="12" maxlength="200">
      </div>

      <div class="d-flex gap-2">
        <?php if (empty($forzado)): ?>
          <a href="<?= site_url('crm/panel') ?>" class="btn btn-light flex-fill">
            Cancelar
          </a>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary flex-fill">
          <i class="bi bi-check2"></i> Guardar
        </button>
      </div>
    </form>
  </div>
</div>

<?php $this->load->view('crm/partials/pie', array('csrf' => $csrf)); ?>