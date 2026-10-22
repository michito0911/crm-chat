<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Chat público (cliente).
 *   GET  /chat                → formulario de bienvenida
 *   POST /chat/iniciar        → crea la conversación y guarda el token
 *   GET  /chat/conversacion   → interfaz de mensajería
 *   GET  /chat/mensajes       → AJAX: mensajes desde un id
 *   POST /chat/enviar         → AJAX: enviar texto
 *   POST /chat/subir_imagen   → AJAX: enviar imagen
 */
class Chat extends MY_Controller
{
    const COOKIE_TOKEN = 'crm_chat_token';

    public function __construct()
    {
        parent::__construct();
        $this->load->model(array(
            'crm_conversacion_model',
            'crm_mensaje_model',
            'crm_categoria_model',
            'crm_config_model',
            'crm_bot_log_model',
            'crm_etiqueta_model',
        ));
        $this->load->library('bot_service');
    }

    /* =========================================================
     *  FORMULARIO INICIAL
     * =======================================================*/

    public function index()
    {
        // Si ya hay token válido, llevamos al cliente directo al chat
        $token = $this->_leer_token();
        if ($token) {
            $conv = $this->crm_conversacion_model->obtener_por_token($token);
            if ($conv) {
                redirect('chat/conversacion');
                return;
            }
        }

        $data = array(
            'texto_privacidad' => $this->cfg('texto_privacidad', 'Acepto el tratamiento de mis datos personales conforme a la Ley N° 29733.'),
            'csrf'             => $this->csrf_data(),
            'titulo'           => 'Chat de atención',
        );

        $this->load->view('crm/chat/inicio', $data);
    }

    /* =========================================================
     *  CREAR CONVERSACIÓN
     * =======================================================*/

    public function iniciar()
    {
        if (!$this->es_post()) {
            redirect('chat');
            return;
        }

        // Honeypot: si viene relleno, es bot
        $honeypot = trim((string) $this->input->post('website'));
        if ($honeypot !== '') {
            // Simulamos éxito y volvemos al formulario
            redirect('chat');
            return;
        }

        $this->load->library('form_validation');
        $this->form_validation->set_rules('nombre', 'Nombre', 'trim|required|min_length[2]|max_length[120]');
        $this->form_validation->set_rules('telefono', 'Teléfono', 'trim|max_length[30]');
        $this->form_validation->set_rules('acepto_datos', 'Consentimiento', 'required');

        if ($this->form_validation->run() === FALSE) {
            $this->session->set_flashdata('error', validation_errors('', ' '));
            redirect('chat');
            return;
        }

        $ip = $this->ip_cliente();

        // Anti-spam: máximo de conversaciones por IP al día
        $max_conv_ip = 100;
        if ($this->crm_mensaje_model->contar_conversaciones_ip_ultimo_dia($ip) >= $max_conv_ip) {
            $this->session->set_flashdata('error', 'Demasiadas conversaciones creadas desde tu conexión. Intenta más tarde.');
            redirect('chat');
            return;
        }

        // Mejora #2: si el cliente escribió teléfono y ya tiene una conversación
        // reciente (< 24h) sin cerrar, la reutilizamos.
        $telefono_post = trim((string)$this->input->post('telefono', TRUE));
        if ($telefono_post !== '') {
            $existente = $this->crm_conversacion_model->obtener_reciente_por_telefono($telefono_post, 24);
            if ($existente) {
                // Reutilizar la conversación existente: guardamos su token en cookie
                $this->input->set_cookie(array(
                    'name'     => self::COOKIE_TOKEN,
                    'value'    => $existente['token'],
                    'expire'   => 60 * 60 * 24 * 30,
                    'httponly' => FALSE,
                    'secure'   => (bool) config_item('cookie_secure'),
                    'samesite' => 'Lax',
                ));
                $this->session->set_flashdata('crm_chat_token', $existente['token']);
                $this->session->set_flashdata('ok', 'Recuperamos tu conversación anterior.');
                redirect('chat/conversacion');
                return;
            }
        }

        $res = $this->crm_conversacion_model->crear(array(
            'nombre'       => $this->input->post('nombre', TRUE),
            'telefono'     => $telefono_post,
            'acepto_datos' => 1,
            'ip'           => $ip,
            'user_agent'   => $this->user_agent(),
        ));

        // Guardar token en cookie (30 días) y ofrecerlo al JS vía
        // flashdata para que también lo guarde en localStorage
        $this->input->set_cookie(array(
            'name'     => self::COOKIE_TOKEN,
            'value'    => $res['token'],
            'expire'   => 60 * 60 * 24 * 30,
            'httponly' => FALSE,   // el JS también lo lee para respaldo
            'secure'   => (bool) config_item('cookie_secure'),
            'samesite' => 'Lax',
        ));

        $this->session->set_flashdata('crm_chat_token', $res['token']);

        redirect('chat/conversacion');
    }

    /* =========================================================
     *  INTERFAZ DE MENSAJERÍA
     * =======================================================*/

    public function conversacion()
{
    $token = $this->_leer_token();
    if (!$token) {
        redirect('chat');
        return;
    }

    $conv = $this->crm_conversacion_model->obtener_por_token($token);
    if (!$conv) {
        // Token inválido o conversación eliminada
        $this->input->set_cookie(array(
            'name'   => self::COOKIE_TOKEN,
            'value'  => '',
            'expire' => '',
        ));
        redirect('chat');
        return;
    }

    $data = array(
        'conversacion'  => $conv,
        'token'         => $token,
        'csrf'          => $this->csrf_data(),
        'titulo'        => 'Conversación',
        'poll_seg'      => $this->cfg_int('polling_cliente_seg', 4),
        'max_largo_msj' => $this->cfg_int('max_largo_msj', 2000),  // ← esta línea falta
    );

    $this->load->view('crm/chat/conversacion', $data);
}

    /* =========================================================
     *  AJAX: POLLING DE MENSAJES
     * =======================================================*/

    public function mensajes()
    {
        if (!$this->es_ajax()) {
            show_404();
            return;
        }

        $conv = $this->_conv_desde_token_o_fallar();
        $desde = (int) $this->input->get_post('desde', TRUE);
        if ($desde < 0) {
            $desde = 0;
        }

        $rows = $this->crm_mensaje_model->listar((int) $conv['id'], $desde, 300);

        $mensajes = array();
        $ultimo_id = $desde;
        foreach ($rows as $r) {
            $mensajes[] = $this->_formatear_mensaje($r, $conv['token']);
            if ((int) $r['id'] > $ultimo_id) {
                $ultimo_id = (int) $r['id'];
            }
        }

        $this->json_ok(array(
            'mensajes'  => $mensajes,
            'ultimo_id' => $ultimo_id,
            'modo'      => $conv['modo'],
            'estado'    => $conv['estado'],
        ));
    }

    /* =========================================================
     *  AJAX: ENVIAR TEXTO
     * =======================================================*/

    public function enviar()
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            show_404();
            return;
        }

        $conv = $this->_conv_desde_token_o_fallar();

        $texto = trim((string) $this->input->post('texto', FALSE));
        if ($texto === '') {
            $this->json_error('Escribe un mensaje.', 400);
            return;
        }

        $max_largo = $this->cfg_int('max_largo_msj', 2000);
        if (function_exists('mb_strlen')) {
            if (mb_strlen($texto, 'UTF-8') > $max_largo) {
                $this->json_error('El mensaje es demasiado largo (máx ' . $max_largo . ' caracteres).', 400);
                return;
            }
        } else if (strlen($texto) > $max_largo) {
            $this->json_error('El mensaje es demasiado largo (máx ' . $max_largo . ' caracteres).', 400);
            return;
        }

        // Anti-spam por conversación y por IP
        $max_hora_conv = $this->cfg_int('max_msj_hora_conv', 30);
        if ($this->crm_mensaje_model->contar_cliente_ultima_hora_conv((int) $conv['id']) >= $max_hora_conv) {
            $this->json_error('Has enviado demasiados mensajes en poco tiempo. Espera un momento.', 429);
            return;
        }

        $max_hora_ip = $this->cfg_int('max_msj_hora_ip', 60);
        if ($this->crm_mensaje_model->contar_cliente_ultima_hora_ip($this->ip_cliente()) >= $max_hora_ip) {
            $this->json_error('Has enviado demasiados mensajes en poco tiempo. Espera un momento.', 429);
            return;
        }

        // ¿Es el primer mensaje del cliente en esta conversación?
        $es_primer = $this->crm_mensaje_model->contar_por_emisor((int) $conv['id'], 'cliente') === 0;

        // Guardar mensaje del cliente
        $msg_id = $this->crm_mensaje_model->crear((int) $conv['id'], 'cliente', 'texto', $texto, NULL);
        if (!$msg_id) {
            $this->json_error('No se pudo guardar el mensaje.', 500);
            return;
        }

        // Ejecutar el bot
        $accion = $this->bot_service->procesar_mensaje($conv, $texto, 'texto', $es_primer);
        $this->_aplicar_accion_bot($conv, $accion);

        // Recargar conversación (por si el bot cambió categoría o modo)
        $conv2 = $this->crm_conversacion_model->obtener((int) $conv['id']);

        $nuevos = $this->crm_mensaje_model->listar((int) $conv['id'], (int) $msg_id - 1, 50);
        $lista = array();
        $ultimo_id = (int) $msg_id;
        foreach ($nuevos as $r) {
            $lista[] = $this->_formatear_mensaje($r, $conv2['token']);
            if ((int) $r['id'] > $ultimo_id) {
                $ultimo_id = (int) $r['id'];
            }
        }

        $this->json_ok(array(
            'mensajes'  => $lista,
            'ultimo_id' => $ultimo_id,
            'modo'      => $conv2['modo'],
            'estado'    => $conv2['estado'],
        ));
    }

    /* =========================================================
     *  AJAX: SUBIR IMAGEN
     * =======================================================*/

    public function subir_imagen()
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            show_404();
            return;
        }

        $conv = $this->_conv_desde_token_o_fallar();

        if (empty($_FILES['imagen']['name'])) {
            $this->json_error('No se recibió ninguna imagen.', 400);
            return;
        }

        // Anti-spam
        $max_hora_conv = $this->cfg_int('max_msj_hora_conv', 30);
        if ($this->crm_mensaje_model->contar_cliente_ultima_hora_conv((int) $conv['id']) >= $max_hora_conv) {
            $this->json_error('Has enviado demasiados mensajes en poco tiempo. Espera un momento.', 429);
            return;
        }

        // Validaciones
        $max_mb    = $this->cfg_int('max_img_mb', 5);
        $max_bytes = $max_mb * 1024 * 1024;

        if ($_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
            $this->json_error('Error al subir la imagen (código ' . (int) $_FILES['imagen']['error'] . ').', 400);
            return;
        }
        if ($_FILES['imagen']['size'] > $max_bytes) {
            $this->json_error('La imagen supera el máximo de ' . $max_mb . ' MB.', 400);
            return;
        }

        // Validación real por MIME
        $mime = $this->_mime_real($_FILES['imagen']['tmp_name']);
        $mimes_ok = array(
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        );
        if (!isset($mimes_ok[$mime])) {
            $this->json_error('Formato no permitido. Usa JPG, PNG o WEBP.', 400);
            return;
        }
        $ext = $mimes_ok[$mime];

        // Guardar con nombre aleatorio
        $dir      = FCPATH . 'assets/crm/uploads/chat/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, TRUE);
        }
        $nombre    = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destino   = $dir . $nombre;

        if (!move_uploaded_file($_FILES['imagen']['tmp_name'], $destino)) {
            $this->json_error('No se pudo guardar la imagen en el servidor.', 500);
            return;
        }

        // Redimensionar si supera el ancho máximo configurado
        $this->_redimensionar_si_necesario($destino, $ext);

        // ¿Es el primer mensaje del cliente?
        $es_primer = $this->crm_mensaje_model->contar_por_emisor((int) $conv['id'], 'cliente') === 0;

        // Guardar el mensaje (tipo imagen, sin texto)
        $msg_id = $this->crm_mensaje_model->crear((int) $conv['id'], 'cliente', 'imagen', NULL, $nombre);
        if (!$msg_id) {
            @unlink($destino);
            $this->json_error('No se pudo registrar la imagen.', 500);
            return;
        }

        // El bot recibe el mensaje como imagen sin texto
        $accion = $this->bot_service->procesar_mensaje($conv, '', 'imagen', $es_primer);
        $this->_aplicar_accion_bot($conv, $accion);

        $conv2 = $this->crm_conversacion_model->obtener((int) $conv['id']);

        $nuevos = $this->crm_mensaje_model->listar((int) $conv['id'], (int) $msg_id - 1, 50);
        $lista = array();
        $ultimo_id = (int) $msg_id;
        foreach ($nuevos as $r) {
            $lista[] = $this->_formatear_mensaje($r, $conv2['token']);
            if ((int) $r['id'] > $ultimo_id) {
                $ultimo_id = (int) $r['id'];
            }
        }

        $this->json_ok(array(
            'mensajes'  => $lista,
            'ultimo_id' => $ultimo_id,
            'modo'      => $conv2['modo'],
            'estado'    => $conv2['estado'],
        ));
    }

    /* =========================================================
     *  INTERNOS
     * =======================================================*/

    /**
     * Lee el token desde POST, GET, cookie o sesión flashdata.
     */
    protected function _leer_token()
    {
        $t = $this->input->post('t', TRUE);
        if (!$t) $t = $this->input->get('t', TRUE);
        if (!$t) $t = $this->input->cookie(self::COOKIE_TOKEN, TRUE);
        if (!$t) {
            $flash = $this->session->flashdata('crm_chat_token');
            if ($flash) $t = $flash;
        }
        $t = is_string($t) ? trim($t) : '';
        return (strlen($t) === 64) ? $t : '';
    }

    /**
     * Devuelve la conversación o responde 401 en AJAX / redirige en HTML.
     */
    protected function _conv_desde_token_o_fallar()
    {
        $token = $this->_leer_token();
        if (!$token) {
            if ($this->es_ajax()) {
                $this->json_error('Token de conversación inválido.', 401);
                exit;
            }
            redirect('chat');
            exit;
        }
        $conv = $this->crm_conversacion_model->obtener_por_token($token);
        if (!$conv) {
            if ($this->es_ajax()) {
                $this->json_error('Conversación no encontrada.', 404);
                exit;
            }
            redirect('chat');
            exit;
        }
        return $conv;
    }

        /**
     * Aplica las acciones devueltas por Bot_service (contrato v2).
     */
    protected function _aplicar_accion_bot(array $conv, array $accion)
    {
        $conv_id = (int) $conv['id'];

        // Campos nuevos (secciones 4.3, 4.4, 4.6)
        if (!empty($accion['solicita_recurso'])) {
            $this->crm_conversacion_model->marcar_solicita_recurso($conv_id);
        }
        if (!empty($accion['equipo_marca']) || !empty($accion['equipo_modelo'])) {
            $this->crm_conversacion_model->actualizar_equipo(
                $conv_id,
                isset($accion['equipo_marca'])  ? $accion['equipo_marca']  : NULL,
                isset($accion['equipo_modelo']) ? $accion['equipo_modelo'] : NULL
            );
        }
        if (!empty($accion['verificacion_estado']) && $accion['verificacion_estado'] === 'pendiente') {
            $this->crm_conversacion_model->marcar_verificacion_pendiente($conv_id);
        }
                // Ficha automática (mejora #4)
        if (!empty($accion['ficha']) && is_array($accion['ficha'])) {
            $this->crm_conversacion_model->actualizar_ficha($conv_id, $accion['ficha']);
        }

        // Alerta de urgencia (mejora #7)
        if (!empty($accion['urgente'])) {
            $this->crm_conversacion_model->marcar_urgente($conv_id);
        }

        // Recategorización
        if (!empty($accion['recategorizar'])) {
            $this->crm_conversacion_model->cambiar_categoria_bot($conv_id, $accion['categoria_id']);
        }

        // Etiquetas secundarias
        if (isset($accion['etiquetas'])) {
            $this->crm_etiqueta_model->reemplazar($conv_id, $accion['etiquetas']);
        }

        // Pendiente de revisión
        if (!empty($accion['marcar_pendiente'])) {
            $this->crm_conversacion_model->marcar_pendiente_revision($conv_id, TRUE);
        }

        // Respuesta del bot
        if (!empty($accion['responder']) && !empty($accion['respuesta_texto'])) {
            $this->crm_mensaje_model->crear($conv_id, 'bot', 'texto', $accion['respuesta_texto'], NULL);

            if (!empty($accion['tipos_log']) && is_array($accion['tipos_log'])) {
                foreach ($accion['tipos_log'] as $t) {
                    $this->crm_bot_log_model->registrar(
                        $conv_id,
                        isset($t['tipo']) ? $t['tipo'] : 'categoria',
                        isset($t['categoria_id']) ? $t['categoria_id'] : NULL
                    );
                }
            } else {
                // Compatibilidad con el contrato v1
                $this->crm_bot_log_model->registrar(
                    $conv_id,
                    isset($accion['tipo_respuesta']) ? $accion['tipo_respuesta'] : 'categoria',
                    isset($accion['categoria_respuesta']) ? $accion['categoria_respuesta'] : NULL
                );
            }
        } else if (!empty($accion['no_responder'])) {
            // El bot no tenía nada nuevo que decir y no responde para no
            // spamear. Registramos el intento para no dejar "sin log".
            $this->crm_bot_log_model->registrar($conv_id, 'sin_respuesta_repetida', NULL);
        }
    }

    /**
     * MIME real con finfo.
     */
    protected function _mime_real($archivo)
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $archivo);
            finfo_close($finfo);
            return $mime;
        }
        // Fallback si no hay finfo
        if (function_exists('mime_content_type')) {
            return mime_content_type($archivo);
        }
        return '';
    }

    /**
     * Redimensiona la imagen si supera el ancho configurado.
     * Mantiene proporción. Si no hay GD, no hace nada.
     */
    protected function _redimensionar_si_necesario($ruta, $ext)
    {
        $ancho_max = $this->cfg_int('img_ancho_max', 1600);
        if ($ancho_max <= 0) {
            return;
        }

        $info = @getimagesize($ruta);
        if (!$info) {
            return;
        }
        list($w, $h) = $info;
        if ($w <= $ancho_max) {
            return;
        }

        $this->load->library('image_lib');
        $config = array(
            'image_library'  => 'gd2',
            'source_image'   => $ruta,
            'maintain_ratio' => TRUE,
            'width'          => $ancho_max,
            'quality'        => '82%',
        );
        if ($ext === 'png') {
            // PNG no usa quality como JPEG; igual, image_lib lo maneja
            $config['quality'] = '82%';
        }
        $this->image_lib->initialize($config);
        if (!$this->image_lib->resize()) {
            // Si falla, no rompemos la subida; log interno
            log_message('error', 'image_lib resize: ' . $this->image_lib->display_errors('', ''));
        }
        $this->image_lib->clear();
    }

    /**
     * Formatea un mensaje para el JSON del cliente.
     */
    protected function _formatear_mensaje(array $m, $token)
    {
        $texto = '';
        if ($m['tipo'] === 'texto') {
            $texto = (string) $m['contenido'];
        }
        return array(
            'id'         => (int) $m['id'],
            'emisor'     => $m['emisor'],
            'tipo'       => $m['tipo'],
            'texto'      => $texto,
            'archivo'    => $m['archivo'] ? crm_archivo_url($m['archivo'], $token) : NULL,
            'creado_at'  => $m['creado_at'],
            'hora'       => crm_hora_corta($m['creado_at']),
        );
    }
}