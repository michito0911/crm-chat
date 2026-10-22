<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$body_class = 'admin-body admin-body-panel';
$this->load->view('crm/partials/head', array('titulo' => $titulo, 'body_class' => $body_class));
$this->load->view('crm/partials/menu_admin', array('admin' => $admin, 'seccion' => 'panel'));
?>

<div class="container-fluid py-3 crm-panel"
     data-poll="<?= (int) $poll_seg ?>"
     data-sonido="<?= (int) $sonido ?>">

  <?php if ($this->session->flashdata('ok')): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <?= crm_esc($this->session->flashdata('ok')) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="row g-3">

    <!-- Filtros / categorías -->
    <div class="col-12">
      <div class="crm-filtros bg-white rounded-3 shadow-sm p-3">

        <div class="d-flex align-items-center justify-content-between mb-2">
          <h1 class="h5 mb-0">
            <i class="bi bi-inbox"></i> Conversaciones
            <span class="badge bg-danger ms-2 d-none" id="crm-badge-noleidos">0</span>
          </h1>
          <button type="button" class="btn btn-sm btn-outline-secondary d-md-none"
                  id="crm-btn-filtros" title="Filtros">
            <i class="bi bi-funnel"></i>
          </button>
        </div>

        <!-- Chips de estado -->
        <div class="crm-chips-estado mb-2">
          <button type="button" class="crm-chip active" data-estado="todas">
            Todas
            <span class="badge bg-secondary" id="cnt-todas">0</span>
          </button>
          <button type="button" class="crm-chip" data-estado="abierta">
            Abierta
            <span class="badge bg-primary" id="cnt-abierta">0</span>
          </button>
          <button type="button" class="crm-chip" data-estado="en_atencion">
            En atención
            <span class="badge bg-warning text-dark" id="cnt-en_atencion">0</span>
          </button>
          <button type="button" class="crm-chip" data-estado="cerrada">
            Cerrada
            <span class="badge bg-secondary" id="cnt-cerrada">0</span>
          </button>
        </div>

        <!-- Categorías como chips -->
        <div class="crm-chips-categoria mb-2">
          <button type="button" class="crm-chip-cat active" data-categoria-id="0"
                  style="--cat-color:#0d6efd;">
            <i class="bi bi-grid"></i> Todas
          </button>
          <?php foreach ($categorias as $c): ?>
            <button type="button" class="crm-chip-cat" data-categoria-id="<?= (int) $c['id'] ?>"
                    style="--cat-color:<?= crm_esc($c['color']) ?>;">
              <span class="crm-dot" style="background: <?= crm_esc($c['color']) ?>"></span>
              <?= crm_esc($c['nombre']) ?>
            </button>
          <?php endforeach; ?>
        </div>

        <!-- Buscador -->
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="search" class="form-control" id="crm-busqueda"
                 placeholder="Buscar por nombre, teléfono o texto…">
          <button class="btn btn-outline-secondary" type="button" id="crm-busqueda-limpiar"
                  title="Limpiar">
            <i class="bi bi-x"></i>
          </button>
        </div>
      </div>
    </div>

    <!-- Listado -->
    <div class="col-12">
      <div id="crm-lista" class="crm-lista">
        <div class="text-center text-muted py-5">
          <span class="spinner-border spinner-border-sm"></span> Cargando…
        </div>
      </div>

      <!-- Paginación -->
      <div class="d-flex justify-content-between align-items-center mt-3">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="crm-pag-prev" disabled>
          <i class="bi bi-chevron-left"></i> Anterior
        </button>
        <span class="small text-muted" id="crm-pag-info">—</span>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="crm-pag-next" disabled>
          Siguiente <i class="bi bi-chevron-right"></i>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Audio de notificación -->
<audio id="crm-audio-notif" preload="auto"
       src="<?= base_url('assets/crm/audio/notificacion.mp3') ?>"></audio>

<?php
$this->load->view('crm/partials/pie', array(
    'csrf'          => $csrf,
    'scripts_extra' => array('panel.js'),
));
?>