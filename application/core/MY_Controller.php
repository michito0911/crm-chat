<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * MY_Controller
 * ------------
 * Clase base del CRM. Aporta:
 *  - Cabeceras de seguridad básicas.
 *  - Carga automática del helper `crm`.
 *  - Helpers para responder JSON (ok/error).
 *  - Utilidades de IP / user agent / detección AJAX.
 */
class MY_Controller extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();

        // Cabeceras de seguridad adicionales (Apache añade otras vía .htaccess)
        $this->output->set_header('X-Content-Type-Options: nosniff');
        $this->output->set_header('X-Frame-Options: SAMEORIGIN');
        $this->output->set_header('Referrer-Policy: strict-origin-when-cross-origin');
        $this->output->set_header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        // Helper común del CRM
        $this->load->helper('crm');
    }

    /* =========================================================
     *  RESPUESTAS JSON
     * =======================================================*/

    protected function json_ok($data = array())
    {
        $this->output
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode(
                array('ok' => TRUE, 'data' => $data),
                JSON_UNESCAPED_UNICODE
            ));
    }

    protected function json_error($msg, $code = 400, $extra = array())
    {
        $payload = array('ok' => FALSE, 'error' => (string) $msg);
        if (!empty($extra)) {
            $payload['data'] = $extra;
        }
        $this->output
            ->set_status_header((int) $code)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /* =========================================================
     *  UTILIDADES
     * =======================================================*/

    protected function es_ajax()
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    protected function es_post()
    {
        return strtoupper($this->input->method(TRUE)) === 'POST';
    }

    /**
     * Datos CSRF listos para mandar a la vista o al JSON.
     */
    protected function csrf_data()
    {
        return array(
            'csrf_name' => $this->security->get_csrf_token_name(),
            'csrf_hash' => $this->security->get_csrf_hash(),
        );
    }

    /**
     * IP del cliente, considerando proxies (Cloudflare, etc.).
     */
    protected function ip_cliente()
    {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    protected function user_agent()
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    }

    /**
     * Carga una configuración desde crm_configuracion una sola vez
     * por petición y la reutiliza.
     */
    protected function cfg($clave, $default = NULL)
    {
        static $cache = NULL;
        if ($cache === NULL) {
            $this->load->model('crm_config_model');
            $cache = $this->crm_config_model->obtener_todas();
            if (!is_array($cache)) {
                $cache = array();
            }
        }
        return array_key_exists($clave, $cache) ? $cache[$clave] : $default;
    }

    protected function cfg_int($clave, $default = 0)
    {
        return (int) $this->cfg($clave, $default);
    }
}