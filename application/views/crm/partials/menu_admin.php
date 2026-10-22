<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark crm-navbar sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= site_url('crm/panel') ?>">
      <i class="bi bi-chat-dots-fill"></i>
      <span class="fw-bold">CRM Chat</span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
            data-bs-target="#crmNav" aria-controls="crmNav" aria-expanded="false"
            aria-label="Abrir menú">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="crmNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link <?= (isset($seccion) && $seccion === 'panel') ? 'active' : '' ?>"
             href="<?= site_url('crm/panel') ?>">
            <i class="bi bi-inbox"></i> Bandeja
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= (isset($seccion) && $seccion === 'recursos') ? 'active' : '' ?>"
             href="<?= site_url('crm/panel/recursos') ?>">
            <i class="bi bi-collection"></i> Recursos
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= (isset($seccion) && $seccion === 'configuracion') ? 'active' : '' ?>"
             href="<?= site_url('crm/panel/configuracion') ?>">
            <i class="bi bi-sliders"></i> Configuración
          </a>
        </li>
      </ul>
      <ul class="navbar-nav ms-auto">
        <li class="nav-item">
          <button type="button" class="nav-link btn btn-link" id="crm-tema-toggle"
                  title="Modo oscuro / claro">
            <i class="bi bi-moon-stars" id="crm-tema-icono"></i>
          </button>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button"
             data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-person-circle"></i>
            <?= crm_esc(isset($admin['nombre']) ? $admin['nombre'] : 'Admin') ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li>
              <a class="dropdown-item" href="<?= site_url('crm/cambiar_password') ?>">
                <i class="bi bi-key"></i> Cambiar contraseña
              </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item text-danger" href="<?= site_url('crm/logout') ?>">
                <i class="bi bi-box-arrow-right"></i> Cerrar sesión
              </a>
            </li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>