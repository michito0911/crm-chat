<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$body_class = 'admin-body admin-body-config';
$this->load->view('crm/partials/head', array('titulo' => $titulo, 'body_class' => $body_class));
$this->load->view('crm/partials/menu_admin', array('admin' => $admin, 'seccion' => 'configuracion'));
?>

<div class="container-fluid py-3 crm-config">

  <h1 class="h5 mb-3"><i class="bi bi-sliders"></i> Configuración del CRM</h1>

  <ul class="nav nav-tabs" id="crm-tabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="tab-cat-btn" data-bs-toggle="tab"
              data-bs-target="#tab-cat" type="button" role="tab">
        <i class="bi bi-tags"></i> Categorías
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-pal-btn" data-bs-toggle="tab"
              data-bs-target="#tab-pal" type="button" role="tab">
        <i class="bi bi-key"></i> Palabras clave
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-res-btn" data-bs-toggle="tab"
              data-bs-target="#tab-res" type="button" role="tab">
        <i class="bi bi-chat-quote"></i> Respuestas
      </button>
    </li>
        <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-rr-btn" data-bs-toggle="tab"
              data-bs-target="#tab-rr" type="button" role="tab">
        <i class="bi bi-lightning-charge"></i> Respuestas rápidas
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-gen-btn" data-bs-toggle="tab"
              data-bs-target="#tab-gen" type="button" role="tab">
        <i class="bi bi-gear"></i> General
      </button>
    </li>
  </ul>

  <div class="tab-content bg-white border border-top-0 rounded-bottom p-3 shadow-sm">

    <!-- ================= CATEGORÍAS ================= -->
    <div class="tab-pane fade show active" id="tab-cat" role="tabpanel">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 mb-0">Categorías</h2>
        <button type="button" class="btn btn-sm btn-primary" id="cat-btn-nueva">
          <i class="bi bi-plus-lg"></i> Nueva categoría
        </button>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:40px">Color</th>
              <th>Nombre</th>
              <th style="width:80px">Orden</th>
              <th style="width:90px">Activa</th>
              <th style="width:140px" class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody id="cat-tbody"></tbody>
        </table>
      </div>
    </div>

    <!-- ================= PALABRAS CLAVE ================= -->
    <div class="tab-pane fade" id="tab-pal" role="tabpanel">
      <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h2 class="h6 mb-0">Palabras clave por categoría</h2>
        <div class="d-flex gap-2 align-items-center">
          <select class="form-select form-select-sm" id="pal-filtro-cat" style="min-width:200px">
            <option value="0">Todas las categorías</option>
            <?php foreach ($categorias as $c): ?>
              <option value="<?= (int) $c['id'] ?>"><?= crm_esc($c['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="btn btn-sm btn-primary" id="pal-btn-nueva">
            <i class="bi bi-plus-lg"></i> Nueva
          </button>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>Categoría</th>
              <th>Palabra / frase</th>
              <th style="width:90px">Peso</th>
              <th style="width:140px" class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody id="pal-tbody"></tbody>
        </table>
      </div>
      <div class="small text-muted mt-2">
        El peso va de 1 a 5. Palabras o frases de varias palabras (ej. <em>no imprime</em>)
        se comparan como palabra/frase completa.
      </div>
    </div>

    <!-- ================= RESPUESTAS ================= -->
    <div class="tab-pane fade" id="tab-res" role="tabpanel">
      <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h2 class="h6 mb-0">Respuestas automáticas por categoría</h2>
        <div class="d-flex gap-2 align-items-center">
          <select class="form-select form-select-sm" id="res-filtro-cat" style="min-width:200px">
            <option value="0">Todas las categorías</option>
            <?php foreach ($categorias as $c): ?>
              <option value="<?= (int) $c['id'] ?>"><?= crm_esc($c['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="btn btn-sm btn-primary" id="res-btn-nueva">
            <i class="bi bi-plus-lg"></i> Nueva
          </button>
        </div>
      </div>

      <div id="res-lista" class="row g-2"></div>
    </div>
        <!-- ================= RESPUESTAS RÁPIDAS ================= -->
    <div class="tab-pane fade" id="tab-rr" role="tabpanel">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 mb-0">Respuestas rápidas con atajos</h2>
        <button type="button" class="btn btn-sm btn-primary" id="rr-btn-nueva">
          <i class="bi bi-plus-lg"></i> Nueva respuesta
        </button>
      </div>

      <p class="small text-muted">
        En la conversación, escribe <code>/atajo</code> y presiona <kbd>Espacio</kbd> para expandir la respuesta.
      </p>

      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:120px">Atajo</th>
              <th>Título</th>
              <th style="width:90px">Orden</th>
              <th style="width:90px">Activa</th>
              <th style="width:140px" class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody id="rr-tbody"></tbody>
        </table>
      </div>
    </div>

    <!-- ================= GENERAL ================= -->
    <div class="tab-pane fade" id="tab-gen" role="tabpanel">

      <h2 class="h6 mb-3">Textos del bot</h2>

      <div class="mb-3">
        <label class="form-label small fw-bold">Saludo inicial (primer mensaje del cliente)</label>
        <textarea class="form-control" id="cfg-texto_saludo_inicial" rows="3"></textarea>
      </div>

      <div class="mb-3">
        <label class="form-label small fw-bold">Mensaje fuera de horario</label>
        <textarea class="form-control" id="cfg-texto_fuera_horario" rows="3"></textarea>
      </div>

      <div class="mb-3">
        <label class="form-label small fw-bold">Mensaje cuando no se detecta categoría</label>
        <textarea class="form-control" id="cfg-texto_sin_clasificar" rows="2"></textarea>
      </div>

      <div class="mb-3">
        <label class="form-label small fw-bold">Mensaje cuando el cliente envía solo una imagen</label>
        <textarea class="form-control" id="cfg-texto_imagen_sin_texto" rows="2"></textarea>
      </div>

      <div class="mb-3">
        <label class="form-label small fw-bold">Texto de consentimiento (chat público)</label>
        <textarea class="form-control" id="cfg-texto_privacidad" rows="3"></textarea>
      </div>

      <hr>

      <h2 class="h6 mb-3">Horario de atención</h2>
      <div class="row g-2">
        <div class="col-6 col-md-3">
          <label class="form-label small">L–V inicio</label>
          <input type="time" class="form-control" id="cfg-horario_lv_inicio">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small">L–V fin</label>
          <input type="time" class="form-control" id="cfg-horario_lv_fin">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small">Sábado inicio</label>
          <input type="time" class="form-control" id="cfg-horario_sa_inicio">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small">Sábado fin</label>
          <input type="time" class="form-control" id="cfg-horario_sa_fin">
        </div>
        <div class="col-12">
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="cfg-horario_do_cerrado" value="1">
            <label class="form-check-label small" for="cfg-horario_do_cerrado">
              Domingo cerrado
            </label>
          </div>
        </div>
      </div>

      <hr>

      <h2 class="h6 mb-3">Límites y anti-spam</h2>
      <div class="row g-2">
        <div class="col-6 col-md-3">
          <label class="form-label small">Máx. caracteres por mensaje</label>
          <input type="number" class="form-control" id="cfg-max_largo_msj" min="100" max="5000">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small">Máx. mensajes por conversación</label>
          <input type="number" class="form-control" id="cfg-max_mensajes_conversacion" min="10">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small">Máx. msj/hora por conversación</label>
          <input type="number" class="form-control" id="cfg-max_msj_hora_conv" min="5">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small">Máx. msj/hora por IP</label>
          <input type="number" class="form-control" id="cfg-max_msj_hora_ip" min="5">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small">Máx. tamaño imagen (MB)</label>
          <input type="number" class="form-control" id="cfg-max_img_mb" min="1" max="20">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small">Ancho máximo imagen (px)</label>
          <input type="number" class="form-control" id="cfg-img_ancho_max" min="200" max="4000">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small">Polling cliente (seg)</label>
          <input type="number" class="form-control" id="cfg-polling_cliente_seg" min="2" max="30">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small">Polling admin (seg)</label>
          <input type="number" class="form-control" id="cfg-polling_admin_seg" min="2" max="30">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small">Minutos anti-repetición del bot</label>
          <input type="number" class="form-control" id="cfg-bot_repetir_min" min="1" max="120">
        </div>
        <div class="col-6 col-md-3 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="cfg-sonido_admin" value="1">
            <label class="form-check-label small" for="cfg-sonido_admin">Sonido al recibir</label>
          </div>
        </div>
      </div>

      <hr>

      <div class="text-end">
        <button type="button" class="btn btn-primary" id="cfg-btn-guardar">
          <i class="bi bi-check2"></i> Guardar cambios
        </button>
      </div>
    </div>

  </div>
</div>

<!-- Modal genérico para editar categoría/palabra/respuesta -->
<div class="modal fade" id="crm-modal-edit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="crm-modal-edit-titulo">Editar</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="crm-modal-edit-body"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" id="crm-modal-edit-guardar">Guardar</button>
      </div>
    </div>
  </div>
</div>

<?php
$this->load->view('crm/partials/pie', array(
    'csrf'          => $csrf,
'scripts_extra' => array('configuracion.js', 'respuestas_rapidas.js'),
));
?>