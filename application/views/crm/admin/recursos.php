<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$body_class = 'admin-body admin-body-recursos';
$this->load->view('crm/partials/head', array('titulo' => $titulo, 'body_class' => $body_class));
$this->load->view('crm/partials/menu_admin', array('admin' => $admin, 'seccion' => 'recursos'));
?>

<div class="container-fluid py-3 crm-recursos-admin"
     data-total="<?= (int) $total ?>">

  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h1 class="h5 mb-0">
      <i class="bi bi-collection"></i> Recursos
      <span class="badge bg-secondary"><?= (int) $total ?> activos</span>
    </h1>
    <div class="d-flex gap-2 flex-wrap">
      <button class="btn btn-sm btn-primary" id="rec-btn-nuevo">
        <i class="bi bi-plus-lg"></i> Nuevo
      </button>
      <button class="btn btn-sm btn-outline-secondary" id="rec-btn-importar">
        <i class="bi bi-upload"></i> Importar
      </button>
      <a class="btn btn-sm btn-outline-secondary"
         href="<?= site_url('crm/api/recursos_admin_exportar_csv') ?>">
        <i class="bi bi-download"></i> Exportar CSV
      </a>
    </div>
  </div>

  <!-- Filtros -->
  <div class="card shadow-sm mb-3">
    <div class="card-body py-2">
      <div class="row g-2">
        <div class="col-12 col-md-4">
          <input type="search" class="form-control form-control-sm"
                 id="rec-q" placeholder="Buscar marca, modelo, título o palabras clave…">
        </div>
        <div class="col-6 col-md-2">
          <select class="form-select form-select-sm" id="rec-tipo">
            <option value="">Todos los tipos</option>
            <option value="driver">Drivers</option>
            <option value="video">Videos</option>
            <option value="manual">Manuales</option>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <select class="form-select form-select-sm" id="rec-marca">
            <option value="">Todas las marcas</option>
            <?php foreach ($marcas as $m): ?>
              <option value="<?= crm_esc($m) ?>"><?= crm_esc($m) ?></option>
            <?php endforeach; ?>
            <option value="OTROS">OTROS (por definir)</option>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <select class="form-select form-select-sm" id="rec-activo">
            <option value="">Activos e inactivos</option>
            <option value="1">Solo activos</option>
            <option value="0">Solo inactivos</option>
          </select>
        </div>
      </div>
    </div>
  </div>

  <!-- Lista -->
  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0 crm-rec-table">
        <thead class="table-light">
          <tr>
            <th>Marca</th>
            <th>Modelo</th>
            <th>Tipo</th>
            <th>Título</th>
            <th class="text-end">Enviado</th>
            <th class="text-center">Estado</th>
            <th class="text-end">Acciones</th>
          </tr>
        </thead>
        <tbody id="rec-tbody">
          <tr><td colspan="7" class="text-center text-muted py-4">
            <span class="spinner-border spinner-border-sm"></span> Cargando…
          </td></tr>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <div class="card-footer d-flex justify-content-between align-items-center">
      <button type="button" class="btn btn-sm btn-outline-secondary" id="rec-pag-prev" disabled>
        <i class="bi bi-chevron-left"></i> Anterior
      </button>
      <span class="small text-muted" id="rec-pag-info">—</span>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="rec-pag-next" disabled>
        Siguiente <i class="bi bi-chevron-right"></i>
      </button>
    </div>
  </div>
</div>

<!-- Modal: editar / crear recurso -->
<div class="modal fade" id="rec-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="rec-modal-titulo">Recurso</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="rec-f-id" value="0">

        <div class="row g-2">
          <div class="col-12 col-md-6">
            <label class="form-label small">Marca</label>
            <div class="input-group input-group-sm">
              <input list="rec-marcas-datalist" class="form-control" id="rec-f-marca" maxlength="60">
              <datalist id="rec-marcas-datalist">
                <?php foreach ($marcas as $m): ?>
                  <option value="<?= crm_esc($m) ?>"></option>
                <?php endforeach; ?>
                <option value="OTROS"></option>
                <option value="GENERAL"></option>
              </datalist>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label small">Modelo</label>
            <input type="text" class="form-control form-control-sm" id="rec-f-modelo" maxlength="80">
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label small">Tipo</label>
            <select class="form-select form-select-sm" id="rec-f-tipo">
              <option value="driver">Driver</option>
              <option value="video">Video</option>
              <option value="manual">Manual</option>
            </select>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label small">Sistema (opcional)</label>
            <input type="text" class="form-control form-control-sm" id="rec-f-sistema"
                   maxlength="40" placeholder="Windows 10, Android…">
          </div>

          <div class="col-12">
            <label class="form-label small">Título</label>
            <input type="text" class="form-control form-control-sm" id="rec-f-titulo" maxlength="120">
          </div>

          <div class="col-12">
            <label class="form-label small">URL</label>
            <div class="input-group input-group-sm">
              <input type="url" class="form-control" id="rec-f-url" maxlength="500">
              <button class="btn btn-outline-secondary" type="button" id="rec-f-probar"
                      title="Abrir enlace en pestaña nueva">
                <i class="bi bi-box-arrow-up-right"></i>
              </button>
            </div>
            <div class="small text-warning mt-1 d-none" id="rec-f-url-aviso">
              <i class="bi bi-exclamation-triangle"></i> Esta URL ya existe en otro recurso.
            </div>
          </div>

          <div class="col-12">
            <label class="form-label small">
              Palabras clave
              <button type="button" class="btn btn-link btn-sm p-0 ms-2" id="rec-f-generar">
                Generar variantes
              </button>
            </label>
            <textarea class="form-control form-control-sm" id="rec-f-palabras" rows="2"
                      placeholder="separadas por coma, ej: xp-365b,xp365b,365b"></textarea>
          </div>

          <div class="col-12">
            <label class="form-label small">Notas internas (opcional)</label>
            <textarea class="form-control form-control-sm" id="rec-f-notas" rows="2" maxlength="255"></textarea>
          </div>

          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="rec-f-activo" checked>
              <label class="form-check-label small" for="rec-f-activo">Activo</label>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" id="rec-f-guardar">
          <i class="bi bi-check2"></i> Guardar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: importación masiva -->
<div class="modal fade" id="rec-importar-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-upload"></i> Importar recursos</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-2">
          Pega una línea por recurso. Formatos aceptados:
        </p>
        <ul class="small text-muted">
          <li><code>Marca | Modelo | Tipo | Título | URL | Sistema</code></li>
          <li><code>MARCA / MODELO --> URL</code> (toma el tipo por defecto)</li>
          <li><code>TÍTULO --> URL</code> (crea marca OTROS, modelo POR DEFINIR)</li>
        </ul>

        <div class="row g-2 mb-2">
          <div class="col-12 col-md-4">
            <label class="form-label small">Tipo por defecto (formatos 2 y 3)</label>
            <select class="form-select form-select-sm" id="rec-imp-tipo">
              <option value="driver">Driver</option>
              <option value="video">Video</option>
              <option value="manual">Manual</option>
            </select>
          </div>
        </div>

        <textarea class="form-control form-control-sm font-monospace" id="rec-imp-texto"
                  rows="8" placeholder="Pega aquí..."></textarea>

        <div class="d-flex gap-2 align-items-center mt-2">
          <button class="btn btn-sm btn-outline-primary" id="rec-imp-preview">
            <i class="bi bi-search"></i> Previsualizar
          </button>
          <span class="small text-muted" id="rec-imp-resumen"></span>
        </div>

        <div class="mt-3 d-none" id="rec-imp-resultado">
          <div class="table-responsive" style="max-height:320px">
            <table class="table table-sm table-hover">
              <thead class="table-light">
                <tr>
                  <th style="width:40px">#</th>
                  <th style="width:90px">Estado</th>
                  <th>Contenido</th>
                </tr>
              </thead>
              <tbody id="rec-imp-tbody"></tbody>
            </table>
          </div>
          <div class="d-flex gap-2 justify-content-end">
            <button class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancelar</button>
            <button class="btn btn-primary btn-sm" id="rec-imp-guardar" disabled>
              <i class="bi bi-check2"></i> Guardar solo las nuevas
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$this->load->view('crm/partials/pie', array(
    'csrf'          => $csrf,
    'scripts_extra' => array('recursos.js'),
));
?>