<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0d6efd">
<meta name="robots" content="noindex, nofollow">
<title><?= crm_esc(isset($titulo) ? $titulo : 'CRM Chat') ?></title>

<!-- Modo oscuro: aplica ANTES de pintar para evitar flash -->
<script>
(function(){
  try {
    var t = localStorage.getItem('crm_tema');
    if (t === 'dark') {
      document.documentElement.setAttribute('data-bs-theme', 'dark');
    }
  } catch(e) {}
})();
</script>

<link rel="stylesheet" href="<?= base_url('assets/crm/vendor/bootstrap/css/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('assets/crm/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('assets/crm/css/chat.css') ?>">
<link rel="stylesheet" href="<?= base_url('assets/crm/css/admin.css') ?>">
</head>
<body class="<?= crm_esc(isset($body_class) ? $body_class : '') ?>">