<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once APPPATH . 'core/Crm_admin_base.php';

/**
 * Panel del administrador. Vistas HTML (los datos vienen por AJAX).
 *  GET /crm/panel
 *  GET /crm/panel/conversacion/{id}
 *  GET /crm/panel/configuracion
 */
class Crm_panel extends Crm_admin_base
{
    public function __construct()
    {
        parent::__construct();

        $this->load->model(array(
            'crm_categoria_model',
            'crm_conversacion_model',
            'crm_mensaje_model',
            'crm_nota_model',
            'crm_etiqueta_model',
            'crm_palabra_model',
            'crm_respuesta_model',
            'crm_config_model',
        ));
    }

    /* =========================================================
     *  BANDEJA
     * =======================================================*/

    public function index()
    {
        $data = array(
            'admin'         => $this->admin,
            'titulo'        => 'Bandeja de conversaciones',
            'categorias'    => $this->crm_categoria_model->listar(FALSE),
            'estados'       => array(
                'todas'       => 'Todas',
                'abierta'     => 'Abierta',
                'en_atencion' => 'En atención',
                'cerrada'     => 'Cerrada',
            ),
            'conteos'       => $this->crm_conversacion_model->contar_por_estado(),
            'no_leidos'     => $this->crm_conversacion_model->contar_no_leidos_total(),
            'csrf'          => $this->csrf_data(),
            'poll_seg'      => $this->cfg_int('polling_admin_seg', 4),
            'sonido'        => $this->cfg_int('sonido_admin', 1),
        );

        $this->load->view('crm/admin/panel', $data);
    }

    /* =========================================================
     *  VISTA DE CONVERSACIÓN
     * =======================================================*/

    public function conversacion($id = 0)
    {
        $id   = (int) $id;
        $conv = $this->crm_conversacion_model->obtener($id);

        if (!$conv) {
            show_404();
            return;
        }

        // Marcar como leídos los mensajes del cliente
        $this->crm_mensaje_model->marcar_leidos_cliente($id);
        $this->crm_conversacion_model->resetear_no_leidos($id);

        $categoria_actual = $conv['categoria_id']
            ? $this->crm_categoria_model->obtener((int) $conv['categoria_id'])
            : NULL;

 $data = array(
    'admin'                => $this->admin,
    'titulo'               => 'Conversación con ' . $conv['nombre'],
    'conversacion'         => $conv,
    'categoria_actual'     => $categoria_actual,
    'etiquetas'            => $this->crm_etiqueta_model->listar_por_conversacion($id),
    'notas'                => $this->crm_nota_model->listar_por_conversacion($id),
    'categorias'           => $this->crm_categoria_model->listar(TRUE),
    'csrf'                 => $this->csrf_data(),
    'poll_seg'             => $this->cfg_int('polling_admin_seg', 4),
    'token'                => $conv['token'],
    'max_largo_msj'        => $this->cfg_int('max_largo_msj', 2000),
    'exigir_verificacion'  => $this->cfg_int('exigir_verificacion', 1),   // ← AGREGAR ESTA LÍNEA
);

        $this->load->view('crm/admin/conversacion', $data);
    }

    /* =========================================================
     *  CONFIGURACIÓN (categorías, palabras, respuestas, general)
     * =======================================================*/

    public function configuracion()
    {
        $data = array(
            'admin'       => $this->admin,
            'titulo'      => 'Configuración del CRM',
            'categorias'  => $this->crm_categoria_model->listar(FALSE),
            'palabras'    => $this->crm_palabra_model->listar_todas(),
            'respuestas'  => $this->crm_respuesta_model->listar_todas(),
            'config'      => $this->crm_config_model->obtener_todas(),
            'csrf'        => $this->csrf_data(),
        );

        $this->load->view('crm/admin/configuracion', $data);
    }
        /* =========================================================
     *  GESTIÓN DE RECURSOS
     * =======================================================*/

    public function recursos()
    {
        $this->load->model('crm_recurso_model');

        $data = array(
            'admin'      => $this->admin,
            'titulo'     => 'Recursos',
            'csrf'       => $this->csrf_data(),
            'total'      => $this->crm_recurso_model->contar_activos(),
            'marcas'     => $this->crm_recurso_model->listar_marcas(),
        );

        $this->load->view('crm/admin/recursos', $data);
    }
}