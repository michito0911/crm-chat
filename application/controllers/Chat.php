<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Chat público (cliente).
 * Identificación 100% por número de teléfono y token en URL (sin cookies).
 */
class Chat extends MY_Controller
{
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
        // Siempre mostrar el formulario. No hay redirección automática por cookies.
        $data = array(
            'texto_privacidad' => $this->cfg('texto_privacidad', 'Acepto el tratamiento de mis datos personales conforme a la Ley N° 29733.'),
            'csrf'             => $this->csrf_data(),
            'titulo'           => 'Chat de atención',
            // El horario se toma de la configuración (antes estaba fijo en la vista: "10:00 a 16:00")
            'texto_horario'    => $this->bot_service->texto_horario(),
        );

        $this->load->view('crm/chat/inicio', $data);
    }

    /* =========================================================
     *  CREAR O RECUPERAR CONVERSACIÓN (POR TELÉFONO)
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
            redirect('chat');
            return;
        }

        $this->load->library('form_validation');
        $this->form_validation->set_rules('nombre', 'Nombre', 'trim|required|min_length[2]|max_length[120]');
        // Teléfono ahora es obligatorio para identificar al cliente
        $this->form_validation->set_rules('telefono', 'Teléfono', 'trim|required|max_length[30]');
        $this->form_validation->set_rules('acepto_datos', 'Consentimiento', 'required');

        if ($this->form_validation->run() === FALSE) {
            $this->session->set_flashdata('error', validation_errors('', ' '));
            redirect('chat');
            return;
        }

        $ip = $this->ip_cliente();
        $telefono = trim((string)$this->input->post('telefono', TRUE));

        // Anti-spam: máximo de conversaciones por IP al día
        $max_conv_ip = 100;
        if ($this->crm_mensaje_model->contar_conversaciones_ip_ultimo_dia($ip) >= $max_conv_ip) {
            $this->session->set_flashdata('error', 'Demasiadas conversaciones creadas desde tu conexión. Intenta más tarde.');
            redirect('chat');
            return;
        }

        // Buscar si ya existe una conversación activa con este teléfono.
        // Pasamos NULL como segundo parámetro para ignorar el límite de horas (busca siempre).
        $existente = $this->crm_conversacion_model->obtener_reciente_por_telefono($telefono, NULL);

        // SEGURIDAD: el teléfono por sí solo NO basta para entrar a una conversación
        // (cualquiera que conozca un número vería su historial, DNI y comprobantes).
        // Solo se retoma si este mismo navegador guardó el token de esa conversación.
        $token_previo = $this->input->post('t', TRUE);
        $token_previo = is_string($token_previo) ? trim($token_previo) : '';

        if ($existente && strlen($token_previo) === 64
            && hash_equals((string) $existente['token'], $token_previo)) {
            // Es el mismo navegador de siempre: retomamos su conversación
            $token = $existente['token'];
        } else {
            // Si no existe, creamos una nueva conversación
            $res = $this->crm_conversacion_model->crear(array(
                'nombre'       => $this->input->post('nombre', TRUE),
                'telefono'     => $telefono,
                'acepto_datos' => 1,
                'ip'           => $ip,
                'user_agent'   => $this->user_agent(),
            ));
            $token = $res['token'];
        }

        // Redirigir pasando el token por la URL (No usamos cookies)
        redirect('chat/conversacion/' . $token);
    }

    /* =========================================================
     *  INTERFAZ DE MENSAJERÍA
     * =======================================================*/

    public function conversacion($token = '')
    {
        // Validar que el token venga en la URL y tenga el largo correcto
        if ($token === '' || strlen($token) !== 64) {
            redirect('chat');
            return;
        }

        $conv = $this->crm_conversacion_model->obtener_por_token($token);
        if (!$conv) {
            redirect('chat');
            return;
        }

        $data = array(
            'conversacion'  => $conv,
            'token'         => $token,
            'csrf'          => $this->csrf_data(),
            'titulo'        => 'Conversación',
            'poll_seg'      => $this->cfg_int('polling_cliente_seg', 4),
            'max_largo_msj' => $this->cfg_int('max_largo_msj', 2000),
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

        // Límite total de mensajes del cliente en la conversación (config max_mensajes_conversacion)
        if ($this->_limite_total_superado($conv)) {
            $this->json_error('Esta conversación alcanzó el máximo de mensajes permitido.', 429);
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

        // ¿Es la respuesta (1 a 5) a la encuesta de satisfacción? Entonces no corre el bot.
        if (!$this->_procesar_csat($conv, $texto)) {
            // Si el cliente escribe en una conversación cerrada, la reabrimos
            $conv = $this->_reabrir_si_cerrada($conv);
            $accion = $this->bot_service->procesar_mensaje($conv, $texto, 'texto', $es_primer);
            $this->_aplicar_accion_bot($conv, $accion);
        }

        // Recargar conversación
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

        if ($this->_limite_total_superado($conv)) {
            $this->json_error('Esta conversación alcanzó el máximo de mensajes permitido.', 429);
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

        // Redimensionar
        $this->_redimensionar_si_necesario($destino, $ext);

        // ¿Es el primer mensaje?
        $es_primer = $this->crm_mensaje_model->contar_por_emisor((int) $conv['id'], 'cliente') === 0;

        // Guardar el mensaje
        $msg_id = $this->crm_mensaje_model->crear((int) $conv['id'], 'cliente', 'imagen', NULL, $nombre);
        if (!$msg_id) {
            @unlink($destino);
            $this->json_error('No se pudo registrar la imagen.', 500);
            return;
        }

        // Ejecutar bot (si la conversación estaba cerrada, se reabre)
        $conv = $this->_reabrir_si_cerrada($conv);
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
     * Lee el token desde POST o GET. Ya NO lee cookies.
     */
    protected function _leer_token()
    {
        $t = $this->input->post('t', TRUE);
        if (!$t) $t = $this->input->get('t', TRUE);
        
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
     * Aplica las acciones devueltas por Bot_service.
     */
    protected function _aplicar_accion_bot(array $conv, array $accion)
    {
        $conv_id = (int) $conv['id'];

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

        // Etiquetas secundarias. Se ACUMULAN: antes se borraban con cualquier mensaje sin
        // categoría (imagen, "okey", "1"...), por eso la tabla quedaba siempre vacía.
        if (!empty($accion['etiquetas'])) {
            $this->crm_etiqueta_model->agregar($conv_id, $accion['etiquetas']);
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
                $this->crm_bot_log_model->registrar(
                    $conv_id,
                    isset($accion['tipo_respuesta']) ? $accion['tipo_respuesta'] : 'categoria',
                    isset($accion['categoria_respuesta']) ? $accion['categoria_respuesta'] : NULL
                );
            }
        } else if (!empty($accion['no_responder'])) {
            $this->crm_bot_log_model->registrar($conv_id, 'sin_respuesta_repetida', NULL);
        }
    }

    /**
     * Si el cliente escribe en una conversación cerrada, la reabre (modo bot)
     * y la deja pendiente para que un asesor la vea. Devuelve la conversación actualizada.
     */
    protected function _reabrir_si_cerrada(array $conv)
    {
        if (isset($conv['estado']) && $conv['estado'] === 'cerrada') {
            $id = (int) $conv['id'];
            $this->crm_conversacion_model->cambiar_estado($id, 'abierta');
            $this->crm_conversacion_model->cambiar_modo($id, 'bot');
            $this->crm_conversacion_model->marcar_pendiente_revision($id, TRUE);
            $conv['estado'] = 'abierta';
            $conv['modo']   = 'bot';
        }
        return $conv;
    }

    /**
     * Anti-spam: máximo de mensajes del cliente por conversación (max_mensajes_conversacion).
     */
    protected function _limite_total_superado(array $conv)
    {
        $max = $this->cfg_int('max_mensajes_conversacion', 0);
        if ($max <= 0) {
            return FALSE;
        }
        return $this->crm_mensaje_model->contar_por_emisor((int) $conv['id'], 'cliente') >= $max;
    }

    /**
     * Encuesta de satisfacción: si se pidió al cerrar y el cliente responde 1 a 5,
     * se registra la nota (y un comentario opcional) y se agradece. Devuelve TRUE si
     * el mensaje era la respuesta a la encuesta.
     */
    protected function _procesar_csat(array $conv, $texto)
    {
        if (empty($conv['csat_solicitado']) || !empty($conv['csat'])) {
            return FALSE;
        }
        if (!isset($conv['estado']) || $conv['estado'] !== 'cerrada') {
            return FALSE;
        }
        if (!preg_match('/^\s*([1-5])(?!\d)[\s\.\)\-:,]*(.*)$/su', (string) $texto, $m)) {
            return FALSE;
        }
        $id = (int) $conv['id'];
        $this->crm_conversacion_model->registrar_csat($id, (int) $m[1], trim($m[2]));

        $gracias = (string) $this->cfg(
            'texto_csat_gracias',
            '¡Gracias por tu calificación! Tu opinión nos ayuda a mejorar.'
        );
        $this->crm_mensaje_model->crear($id, 'bot', 'texto', $gracias, NULL);
        $this->crm_bot_log_model->registrar($id, 'csat', NULL);
        return TRUE;
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
        if (function_exists('mime_content_type')) {
            return mime_content_type($archivo);
        }
        return '';
    }

    /**
     * Redimensiona la imagen si supera el ancho configurado.
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
            $config['quality'] = '82%';
        }
        $this->image_lib->initialize($config);
        if (!$this->image_lib->resize()) {
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