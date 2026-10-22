<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Sirve las imágenes del chat aplicando control de acceso.
 *   /crm/media/{archivo}          → requiere sesión admin
 *   /crm/media/{archivo}?t=TOKEN  → cliente con token de conversación
 *
 * No se expone la carpeta física directamente por HTTP.
 */
class Crm_media extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model(array('crm_mensaje_model', 'crm_conversacion_model'));
    }

    public function ver($archivo = '')
    {
        // Saneamos el nombre (evita traversal)
        $archivo = basename((string) $archivo);
        if ($archivo === '' || !preg_match('/^[A-Za-z0-9._-]+\.(jpg|jpeg|png|webp)$/i', $archivo)) {
            show_404();
            return;
        }

        // 1) ¿Hay sesión de admin?
        $admin_id = (int) $this->session->userdata('crm_admin_id');
        $es_admin = $admin_id > 0;

        // 2) Si no hay admin, exigimos token de conversación válido
        if (!$es_admin) {
            $token = (string) $this->input->get('t', TRUE);
            if (strlen($token) !== 64) {
                show_404();
                return;
            }
            $conv = $this->crm_conversacion_model->obtener_por_token($token);
            if (!$conv) {
                show_404();
                return;
            }
            // El archivo debe pertenecer a un mensaje de esa conversación
            $msg = $this->db
                ->where('conversacion_id', (int) $conv['id'])
                ->where('archivo', $archivo)
                ->get('crm_mensajes')
                ->row_array();
            if (!$msg) {
                show_404();
                return;
            }
        }

        $ruta = FCPATH . 'assets/crm/uploads/chat/' . $archivo;
        if (!is_file($ruta)) {
            show_404();
            return;
        }

        // Detectar MIME real
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $ruta);
            finfo_close($finfo);
        } else if (function_exists('mime_content_type')) {
            $mime = mime_content_type($ruta);
        }

        $permitidos = array('image/jpeg', 'image/png', 'image/webp', 'image/gif');
        if (!in_array($mime, $permitidos, TRUE)) {
            show_404();
            return;
        }

        // Cabeceras
        $this->output
            ->set_status_header(200)
            ->set_header('Content-Type: ' . $mime)
            ->set_header('Content-Length: ' . filesize($ruta))
            ->set_header('Cache-Control: private, max-age=86400')
            ->set_header('X-Content-Type-Options: nosniff');

        // Enviamos el archivo
        readfile($ruta);
    }
}