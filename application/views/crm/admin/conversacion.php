<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$body_class = 'admin-body admin-body-conv';
$this->load->view('crm/partials/head', array('titulo' => $titulo, 'body_class' => $body_class));
$this->load->view('crm/partials/menu_admin', array('admin' => $admin, 'seccion' => 'panel'));

$v = isset($conversacion['verificacion_estado']) ? $conversacion['verificacion_estado'] : 'sin_verificar';
$insignia_txt = 'Sin verificar';
$insignia_bg  = 'bg-secondary';
if ($v === 'pendiente') { $insignia_txt = 'Pendiente verificación'; $insignia_bg = 'bg-warning text-dark'; }
if ($v === 'verificado'){ $insignia_txt = 'Verificado';              $insignia_bg = 'bg-success'; }
$comp_tipo   = isset($conversacion['comprobante_tipo'])   ? $conversacion['comprobante_tipo']   : '';
$comp_num    = isset($conversacion['comprobante_numero']) ? $conversacion['comprobante_numero'] : '';
$eq_marca    = isset($conversacion['equipo_marca'])       ? $conversacion['equipo_marca']       : '';
$eq_modelo   = isset($conversacion['equipo_modelo'])      ? $conversacion['equipo_modelo']      : '';
?>

<div class="container-fluid py-3 crm-conv-app"
     data-conv-id="<?= (int) $conversacion['id'] ?>"
     data-token="<?= crm_esc($token) ?>"
     data-poll="<?= (int) $poll_seg ?>"
     data-max="<?= (int) $max_largo_msj ?>"
     data-modo="<?= crm_esc($conversacion['modo']) ?>"
     data-estado="<?= crm_esc($conversacion['estado']) ?>"
     data-categoria-id="<?= (int) ($conversacion['categoria_id'] ?: 0) ?>"
     data-verificacion="<?= crm_esc($v) ?>"
          data-exigir-verificacion="<?= (int) $exigir_verificacion ?>">

  <div class="row g-3">

    <!-- Columna principal: chat -->
    <div class="col-12 col-lg-8 col-xl-9">
      <div class="card shadow-sm crm-conv-main">

        <!-- Cabecera del cliente -->
        <div class="card-header bg-white">
          <div class="d-flex align-items-center gap-2 flex-wrap">

            <a href="<?= site_url('crm/panel') ?>" class="btn btn-sm btn-light" title="Volver">
              <i class="bi bi-arrow-left"></i>
            </a>

            <div class="crm-conv-avatar">
              <?= crm_esc(mb_substr($conversacion['nombre'], 0, 1, 'UTF-8')) ?>
            </div>

            <div class="flex-grow-1">
              <div class="fw-bold lh-1"><?= crm_esc($conversacion['nombre']) ?></div>
              <div class="small text-muted">
                <?php if (!empty($conversacion['telefono'])): ?>
                  <i class="bi bi-telephone"></i> <?= crm_esc($conversacion['telefono']) ?>
                <?php else: ?>
                  <span class="fst-italic">Sin teléfono</span>
                <?php endif; ?>
                · <span class="text-nowrap">Desde <?= crm_esc(date('d/m/Y', strtotime($conversacion['creado_at']))) ?></span>
              </div>
            </div>

            <div class="crm-conv-badges">
              <span class="badge <?= 'bg-' . crm_color_estado($conversacion['estado']) ?>" id="conv-badge-estado">
                <?= crm_esc(crm_estado_legible($conversacion['estado'])) ?>
              </span>
              <span class="badge bg-info text-dark" id="conv-badge-modo">
                <i class="bi bi-robot"></i> <?= crm_esc(crm_modo_legible($conversacion['modo'])) ?>
              </span>
              <span class="badge <?= crm_esc($insignia_bg) ?>" id="conv-badge-verif"
                    title="Estado de verificación del comprobante">
                <i class="bi bi-patch-check"></i> <span id="conv-badge-verif-txt"><?= crm_esc($insignia_txt) ?></span>
              </span>
              <?php if (!empty($eq_marca) || !empty($eq_modelo)): ?>
                <span class="badge bg-light text-dark border" id="conv-badge-equipo">
                  <i class="bi bi-cpu"></i>
                  <?= crm_esc(trim($eq_marca . ' ' . $eq_modelo)) ?>
                </span>
              <?php endif; ?>
            </div>

            <button type="button" class="btn btn-sm btn-outline-secondary d-lg-none"
                    id="conv-btn-info" title="Información">
              <i class="bi bi-info-circle"></i>
            </button>
          </div>
        </div>

        <!-- Mensajes -->
        <div class="card-body crm-conv-mensajes" id="conv-mensajes" aria-live="polite">
          <div class="text-center text-muted small py-3">
            <span class="spinner-border spinner-border-sm"></span> Cargando mensajes…
          </div>
        </div>

        <!-- Preview de imagen a enviar -->
        <div class="crm-conv-preview d-none" id="conv-preview">
          <img id="conv-preview-img" alt="Vista previa">
          <button type="button" class="chat-preview-cerrar" id="conv-preview-cerrar"
                  title="Quitar imagen">×</button>
        </div>

        <!-- Input de envío -->
        <div class="card-footer bg-white">
          <div class="d-flex gap-2 align-items-end">
            <button type="button" class="btn btn-light" id="conv-btn-adjuntar"
                    title="Adjuntar imagen" aria-label="Adjuntar imagen">
              <i class="bi bi-image"></i>
            </button>
            <input type="file" id="conv-input-file" accept="image/jpeg,image/png,image/webp" hidden>

            <button type="button" class="btn btn-outline-primary" id="conv-btn-recursos"
                    title="Enviar recursos (drivers, videos)" aria-label="Recursos">
              <i class="bi bi-collection"></i> <span class="d-none d-md-inline">Recursos</span>
            </button>

            <textarea id="conv-input-texto" class="form-control"
                      rows="1" placeholder="Escribe un mensaje al cliente…"
                      maxlength="<?= (int) $max_largo_msj ?>"></textarea>

            <button type="button" class="btn btn-primary" id="conv-btn-enviar"
                    title="Enviar" aria-label="Enviar mensaje">
              <i class="bi bi-send"></i>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Panel lateral (info, acciones, notas) -->
    <div class="col-12 col-lg-4 col-xl-3 crm-conv-lateral" id="crm-conv-lateral">
      <div class="card shadow-sm mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <span class="fw-bold"><i class="bi bi-sliders"></i> Acciones</span>
          <button type="button" class="btn-close d-lg-none" id="conv-info-cerrar"
                  aria-label="Cerrar"></button>
        </div>
        <div class="card-body">

          <!-- Verificación -->
          <div class="mb-3">
            <label class="form-label small fw-bold">Comprobante</label>
            <div class="d-flex gap-1 flex-wrap mb-2" id="conv-verif-acciones">
              <button type="button" class="btn btn-sm btn-success" id="conv-btn-verificar">
                <i class="bi bi-patch-check"></i> Verificar
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="conv-btn-quitar-verif">
                <i class="bi bi-x-circle"></i> Quitar verificación
              </button>
            </div>
            <div class="small text-muted" id="conv-verif-detalle"></div>
          </div>

          <!-- Estado -->
          <div class="mb-3">
            <label class="form-label small fw-bold">Estado</label>
            <div class="d-flex gap-1 flex-wrap">
              <button type="button" class="btn btn-sm btn-outline-primary crm-acc-estado"
                      data-estado="abierta">Abierta</button>
              <button type="button" class="btn btn-sm btn-outline-warning crm-acc-estado"
                      data-estado="en_atencion">En atención</button>
              <button type="button" class="btn btn-sm btn-outline-secondary crm-acc-estado"
                      data-estado="cerrada">Cerrada</button>
            </div>
          </div>

          <!-- Categoría -->
          <div class="mb-3">
            <label class="form-label small fw-bold">
              Categoría
              <?php if (!empty($conversacion['categoria_manual'])): ?>
                <span class="badge bg-warning text-dark" title="Fijada a mano; el bot no la cambia">
                  <i class="bi bi-hand-index"></i> manual
                </span>
              <?php endif; ?>
            </label>
            <select class="form-select form-select-sm" id="conv-categoria">
              <option value="0">— Sin clasificar —</option>
              <?php foreach ($categorias as $c): ?>
                <option value="<?= (int) $c['id'] ?>"
                  <?= ((int) $conversacion['categoria_id'] === (int) $c['id']) ? 'selected' : '' ?>>
                  <?= crm_esc($c['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (!empty($etiquetas)): ?>
              <div class="small text-muted mt-1">
                Etiquetas:
                <?php foreach ($etiquetas as $et): ?>
                  <span class="badge" style="background: <?= crm_esc($et['categoria_color']) ?>">
                    <?= crm_esc($et['categoria_nombre']) ?>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <!-- Modo -->
          <div class="mb-2">
            <label class="form-label small fw-bold">Modo</label>
            <div class="d-flex gap-1">
              <button type="button" class="btn btn-sm btn-outline-info crm-acc-modo flex-fill"
                      data-modo="humano">
                <i class="bi bi-person"></i> Humano
              </button>
              <button type="button" class="btn btn-sm btn-outline-info crm-acc-modo flex-fill"
                      data-modo="bot">
                <i class="bi bi-robot"></i> Devolver al bot
              </button>
            </div>
          </div>

        </div>
      </div>

      <!-- Notas internas -->
      <div class="card shadow-sm">
        <div class="card-header bg-white">
          <span class="fw-bold"><i class="bi bi-journal-text"></i> Notas internas</span>
          <div class="small text-muted">Solo visibles para ti.</div>
        </div>
        <div class="card-body">
          <div id="conv-notas-lista" class="mb-2">
            <?php if (empty($notas)): ?>
              <div class="text-muted small fst-italic" id="conv-notas-vacio">Sin notas todavía.</div>
            <?php else: ?>
              <?php foreach ($notas as $n): ?>
                <div class="crm-nota" data-id="<?= (int) $n['id'] ?>">
                  <div class="small"><?= nl2br(crm_esc($n['nota'])) ?></div>
                  <div class="text-muted crm-nota-fecha"><?= crm_esc(crm_fecha_relativa($n['creado_at'])) ?></div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <textarea class="form-control form-control-sm mb-2" id="conv-nota-texto"
                    rows="2" placeholder="Escribe una nota…" maxlength="4000"></textarea>
          <button type="button" class="btn btn-sm btn-outline-primary w-100" id="conv-nota-btn">
            <i class="bi bi-plus-lg"></i> Agregar nota
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal para ampliar imagen -->
<div class="modal fade" id="conv-img-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content bg-dark">
      <div class="modal-body p-0 text-center">
        <img id="conv-img-modal-img" src="" alt="" class="img-fluid">
      </div>
    </div>
  </div>
</div>

<!-- Modal Verificar comprobante -->
<div class="modal fade" id="conv-verif-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Verificar comprobante</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label small">Tipo</label>
          <select class="form-select" id="conv-verif-tipo">
            <option value="">— Sin especificar —</option>
            <option value="boleta">Boleta</option>
            <option value="factura">Factura</option>
            <option value="otro">Otro</option>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label small">Número (opcional)</label>
          <input type="text" class="form-control" id="conv-verif-numero" maxlength="40" placeholder="B001-123">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-success" id="conv-verif-guardar">
          <i class="bi bi-patch-check"></i> Marcar verificado
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal / Bottom-sheet: Selector de recursos -->
<div class="modal fade crm-recursos-modal" id="conv-recursos-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-end modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-collection"></i> Recursos para el cliente</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div id="conv-recursos-aviso-verif" class="alert alert-warning small py-2 d-none">
          <i class="bi bi-exclamation-triangle"></i>
          Verifica primero la boleta o factura para poder enviar recursos.
          <button type="button" class="btn btn-sm btn-link p-0 ms-1" id="conv-recursos-verif-link">
            Verificar ahora
          </button>
        </div>

        <!-- Sugeridos -->
        <div id="conv-recursos-sugeridos-wrap" class="mb-3 d-none">
          <div class="small fw-bold mb-1">
            <i class="bi bi-lightbulb"></i> Sugeridos para este caso
            <span class="text-muted" id="conv-recursos-equipo"></span>
          </div>
          <div id="conv-recursos-sugeridos" class="list-group list-group-flush"></div>
        </div>

        <!-- Buscador y filtros -->
        <div class="mb-2 d-flex gap-2 flex-wrap align-items-center">
          <input type="search" class="form-control form-control-sm flex-grow-1"
                 id="conv-recursos-q" placeholder="Buscar por marca, modelo o título…">
          <div class="btn-group btn-group-sm" role="group" id="conv-recursos-filtros">
            <button type="button" class="btn btn-outline-secondary active" data-tipo="">Todos</button>
            <button type="button" class="btn btn-outline-secondary" data-tipo="driver">Drivers</button>
            <button type="button" class="btn btn-outline-secondary" data-tipo="video">Videos</button>
            <button type="button" class="btn btn-outline-secondary" data-tipo="manual">Manuales</button>
          </div>
        </div>

        <!-- Lista -->
        <div id="conv-recursos-lista" class="crm-recursos-lista">
          <div class="text-center text-muted py-4 small">
            <span class="spinner-border spinner-border-sm"></span> Cargando…
          </div>
        </div>
      </div>

      <!-- Preview + envío -->
      <div class="modal-footer flex-column align-items-stretch">
        <div class="small text-muted mb-1" id="conv-recursos-count">0 recursos seleccionados</div>

        <label class="form-label small mb-1">Texto de introducción (editable)</label>
        <textarea class="form-control form-control-sm mb-2" id="conv-recursos-intro" rows="2"></textarea>

        <div class="d-flex gap-2">
          <button type="button" class="btn btn-light flex-fill" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-primary flex-fill" id="conv-recursos-enviar">
            <i class="bi bi-send"></i> Enviar seleccionados (<span id="conv-recursos-n">0</span>)
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$this->load->view('crm/partials/pie', array(
    'csrf'          => $csrf,
    'scripts_extra' => array('conversacion_admin.js'),
));
?>