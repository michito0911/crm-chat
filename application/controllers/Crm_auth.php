<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Autenticación del administrador del CRM.
 *  GET  /crm/login             → formulario
 *  POST /crm/login             → valida credenciales
 *  GET  /crm/logout            → cierra sesión
 *  GET/POST /crm/cambiar_password
 */
class Crm_auth extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('crm_admin_model');
    }

    /* =========================================================
     *  LOGIN
     * =======================================================*/

    public function login()
    {
        // Si ya hay sesión, al panel
        if ($this->session->userdata('crm_admin_id')) {
            redirect('crm/panel');
            return;
        }

        $data = array(
            'csrf'   => $this->csrf_data(),
            'titulo' => 'Acceso al panel',
        );

        if ($this->es_post()) {
            $this->load->library('form_validation');
            $this->form_validation->set_rules('usuario', 'Usuario', 'trim|required|max_length[60]');
            $this->form_validation->set_rules('password', 'Contraseña', 'required|max_length[200]');

            if ($this->form_validation->run() === FALSE) {
                $data['error'] = validation_errors('', ' ');
                $this->load->view('crm/admin/login', $data);
                return;
            }

            $usuario  = (string) $this->input->post('usuario', TRUE);
            $password = (string) $this->input->post('password', FALSE);
            $ip       = $this->ip_cliente();

            // Defensa extra: muchos fallos desde la misma IP
            if ($this->crm_admin_model->contar_intentos_fallidos_ip($ip, 15) >= 15) {
                $this->crm_admin_model->registrar_intento($usuario, $ip, FALSE);
                $data['error'] = 'Demasiados intentos fallidos desde tu conexión. Intenta más tarde.';
                $this->load->view('crm/admin/login', $data);
                return;
            }

            $admin = $this->crm_admin_model->obtener_por_usuario($usuario);

            if (!$admin) {
                // Simulamos algo de tiempo para evitar enumeración por timing
                usleep(200000);
                $this->crm_admin_model->registrar_intento($usuario, $ip, FALSE);
                $data['error'] = 'Usuario o contraseña incorrectos.';
                $this->load->view('crm/admin/login', $data);
                return;
            }

            if ($this->crm_admin_model->esta_bloqueado($admin)) {
                $this->crm_admin_model->registrar_intento($usuario, $ip, FALSE);
                $data['error'] = 'Cuenta bloqueada temporalmente por intentos fallidos. Intenta más tarde.';
                $this->load->view('crm/admin/login', $data);
                return;
            }

            if (!password_verify($password, $admin['password_hash'])) {
                $this->crm_admin_model->sumar_intento_fallido((int) $admin['id']);
                $this->crm_admin_model->registrar_intento($usuario, $ip, FALSE);
                $data['error'] = 'Usuario o contraseña incorrectos.';
                $this->load->view('crm/admin/login', $data);
                return;
            }

            // Login OK
            $this->crm_admin_model->resetear_intentos((int) $admin['id']);
            $this->crm_admin_model->registrar_ultimo_login((int) $admin['id']);
            $this->crm_admin_model->registrar_intento($usuario, $ip, TRUE);

            // Regenerar el ID de sesión antes de guardar los datos
            $this->session->sess_regenerate(TRUE);

            $this->session->set_userdata(array(
                'crm_admin_id'       => (int) $admin['id'],
                'crm_admin_usuario'  => $admin['usuario'],
                'crm_admin_nombre'   => $admin['nombre'],
                'crm_admin_logueado' => TRUE,
                'crm_admin_ingreso'  => time(),
            ));

            if (!empty($admin['debe_cambiar_password'])) {
                redirect('crm/cambiar_password');
                return;
            }

            redirect('crm/panel');
            return;
        }

        $this->load->view('crm/admin/login', $data);
    }

    /* =========================================================
     *  LOGOUT
     * =======================================================*/

    public function logout()
    {
        $this->session->unset_userdata(array(
            'crm_admin_id',
            'crm_admin_usuario',
            'crm_admin_nombre',
            'crm_admin_logueado',
            'crm_admin_ingreso',
            'crm_admin_ultimo_acceso',
        ));
        $this->session->sess_destroy();
        redirect('crm/login');
    }

    /* =========================================================
     *  CAMBIO DE CONTRASEÑA (forzado u opcional)
     * =======================================================*/

    public function cambiar_password()
    {
        $admin_id = (int) $this->session->userdata('crm_admin_id');
        if ($admin_id <= 0) {
            redirect('crm/login');
            return;
        }

        $admin = $this->crm_admin_model->obtener($admin_id);
        if (!$admin) {
            $this->session->sess_destroy();
            redirect('crm/login');
            return;
        }

        $data = array(
            'csrf'     => $this->csrf_data(),
            'admin'    => $admin,
            'forzado'  => !empty($admin['debe_cambiar_password']),
            'titulo'   => 'Cambiar contraseña',
        );

        if ($this->es_post()) {
            $this->load->library('form_validation');
            $this->form_validation->set_rules('password_actual', 'Contraseña actual', 'required');
            $this->form_validation->set_rules('password_nueva', 'Contraseña nueva', 'required|min_length[12]|max_length[200]');
            $this->form_validation->set_rules('password_repite', 'Confirmación', 'required|matches[password_nueva]');

            if ($this->form_validation->run() === FALSE) {
                $data['error'] = validation_errors('', ' ');
                $this->load->view('crm/admin/cambiar_password', $data);
                return;
            }

            $actual = (string) $this->input->post('password_actual', FALSE);
            $nueva  = (string) $this->input->post('password_nueva', FALSE);

            if (!password_verify($actual, $admin['password_hash'])) {
                $data['error'] = 'La contraseña actual no es correcta.';
                $this->load->view('crm/admin/cambiar_password', $data);
                return;
            }

            if ($nueva === $actual) {
                $data['error'] = 'La nueva contraseña debe ser diferente a la actual.';
                $this->load->view('crm/admin/cambiar_password', $data);
                return;
            }

            $hash = password_hash($nueva, PASSWORD_BCRYPT, array('cost' => 12));
            $this->crm_admin_model->cambiar_password($admin_id, $hash);

            $this->session->set_flashdata('ok', 'Contraseña actualizada correctamente.');
            redirect('crm/panel');
            return;
        }

        $this->load->view('crm/admin/cambiar_password', $data);
    }
}