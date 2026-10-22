<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Crm_admin_base
 * --------------
 * Clase base para controladores del panel.
 * Extiende MY_Controller y añade:
 *  - Verificación de sesión de administrador.
 *  - Redirección al login si no hay sesión (o 401 en AJAX).
 *  - Forzar cambio de contraseña si debe_cambiar_password = 1.
 *
 * Uso:
 *   require_once APPPATH . 'core/Crm_admin_base.php';
 *   class Crm_panel extends Crm_admin_base { ... }
 */
class Crm_admin_base extends MY_Controller
{
    /** @var array Datos del admin autenticado */
    protected $admin = array();

    public function __construct()
    {
        parent::__construct();

        $this->load->model('crm_admin_model');

        $admin_id = (int) $this->session->userdata('crm_admin_id');

        if ($admin_id <= 0) {
            $this->_salir_sin_sesion('Tu sesión expiró. Vuelve a iniciar sesión.');
        }

        $admin = $this->crm_admin_model->obtener($admin_id);
        if (!$admin || (int) $admin['activo'] !== 1) {
            $this->session->sess_destroy();
            $this->_salir_sin_sesion('Sesión inválida.');
        }

        $this->admin = $admin;

        // Marca de tiempo de última actividad (opcional)
        $this->session->set_userdata('crm_admin_ultimo_acceso', time());

        // Forzar cambio de contraseña
        if (!empty($admin['debe_cambiar_password'])) {
            $metodo = $this->router->fetch_method();
            $clase  = $this->router->fetch_class();

            // Permitir cambiar_password y logout sin bloquear
            $permitidos = array(
                'crm_auth' => array('cambiar_password', 'logout'),
            );

            $es_permitido = isset($permitidos[$clase])
                && in_array($metodo, $permitidos[$clase], TRUE);

            if (!$es_permitido) {
                if ($this->es_ajax()) {
                    $this->json_error('Debes cambiar tu contraseña antes de continuar.', 403);
                    exit;
                }
                redirect('crm/cambiar_password');
                exit;
            }
        }
    }

    /**
     * Salida uniforme cuando no hay sesión válida.
     */
    protected function _salir_sin_sesion($msg)
    {
        if ($this->es_ajax()) {
            $this->json_error($msg, 401);
            exit;
        }
        redirect('crm/login');
        exit;
    }
}