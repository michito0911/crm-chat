<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<script>
  window.CRM = {
    baseUrl:    '<?= base_url() ?>',
    siteUrl:    '<?= site_url() ?>',
    csrfName:   '<?= crm_esc($csrf['csrf_name']) ?>',
    csrfHash:   '<?= crm_esc($csrf['csrf_hash']) ?>'
  };
</script>
<script src="<?= base_url('assets/crm/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('assets/crm/js/tema.js') ?>"></script>
<?php if (!empty($scripts_extra) && is_array($scripts_extra)): ?>
  <?php foreach ($scripts_extra as $src): ?>
    <script src="<?= base_url('assets/crm/js/' . $src) ?>"></script>
  <?php endforeach; ?>
<?php endif; ?>
</body>
</html>