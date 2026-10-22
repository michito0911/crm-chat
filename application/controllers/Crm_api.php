<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once APPPATH . 'core/Crm_admin_base.php';

/**
 * API AJAX del panel (requiere sesión de administrador).
 */
class Crm_api extends Crm_admin_base
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

        // Todos los métodos responden JSON
        $this->output->set_content_type('application/json', 'utf-8');
    }

    /* =========================================================
     *  INDICADOR DE NO LEÍDOS (polling corto)
     * =======================================================*/

    public function no_leidos()
    {
        if (!$this->es_ajax()) {
            return $this->json_error('Método no permitido.', 405);
        }

        $total = $this->crm_conversacion_model->contar_no_leidos_total();

        // Últimos 5 conversaciones con no_leidos > 0 para el toast
        $nuevas = $this->db
            ->select('id, nombre, no_leidos_admin, ultimo_mensaje_at')
            ->where('no_leidos_admin >', 0)
            ->order_by('ultimo_mensaje_at', 'DESC')
            ->limit(5)
            ->get('crm_conversaciones')
            ->result_array();

        $this->json_ok(array(
            'total'   => $total,
            'nuevas'  => $nuevas,
        ));
    }

    /* =========================================================
     *  LISTADO DE CONVERSACIONES
     * =======================================================*/

    public function conversaciones()
    {
        if (!$this->es_ajax()) {
            return $this->json_error('Método no permitido.', 405);
        }

        $filtros = array(
            'categoria_id' => (int) $this->input->get_post('categoria_id', TRUE),
            'estado'       => (string) $this->input->get_post('estado', TRUE),
            'busqueda'     => (string) $this->input->get_post('q', TRUE),
            'pendiente'    => (int) $this->input->get_post('pendiente', TRUE),
        );

        $pagina = max(1, (int) $this->input->get_post('pagina', TRUE));
        $limit  = 30;
        $offset = ($pagina - 1) * $limit;

        $lista  = $this->crm_conversacion_model->listar_con_filtros($filtros, $limit, $offset);
        $total  = $this->crm_conversacion_model->contar_con_filtros($filtros);

        // Enriquecer con categoría (ya viene en el SELECT) y vista previa
        $salida = array();
        foreach ($lista as $c) {
            $salida[] = array(
                'id'                 => (int) $c['id'],
                'nombre'             => $c['nombre'],
                'telefono'           => $c['telefono'],
                'categoria_id'       => $c['categoria_id'] ? (int) $c['categoria_id'] : NULL,
                'categoria_nombre'   => $c['categoria_nombre'],
                'urgente'            => (int) $c['urgente'],
                'categoria_color'    => $c['categoria_color'],
                'categoria_manual'   => (int) $c['categoria_manual'],
                'estado'             => $c['estado'],
                'modo'               => $c['modo'],
                'pendiente_revision' => (int) $c['pendiente_revision'],
                'no_leidos_admin'    => (int) $c['no_leidos_admin'],
                'ultimo_mensaje_at'  => $c['ultimo_mensaje_at'],
                'ultimo_relativo'    => crm_fecha_relativa($c['ultimo_mensaje_at']),
                'vista_previa'       => $c['ultimo_tipo'] === 'imagen'
                    ? '[imagen]'
                    : crm_recortar((string) $c['ultimo_mensaje'], 80),
            );
        }

        $this->json_ok(array(
            'conversaciones' => $salida,
            'total'          => $total,
            'pagina'         => $pagina,
            'paginas'        => max(1, (int) ceil($total / $limit)),
        ));
    }

    /* =========================================================
     *  MENSAJES DE UNA CONVERSACIÓN
     * =======================================================*/

    public function mensajes($id = 0)
    {
        if (!$this->es_ajax()) {
            return $this->json_error('Método no permitido.', 405);
        }

        $id = (int) $id;
        $conv = $this->crm_conversacion_model->obtener($id);
        if (!$conv) {
            return $this->json_error('Conversación no encontrada.', 404);
        }

        $desde = max(0, (int) $this->input->get_post('desde', TRUE));
        $rows  = $this->crm_mensaje_model->listar($id, $desde, 300);

        // Si el admin está viendo, marcamos como leídos los mensajes del cliente
        if ($desde === 0) {
            $this->crm_mensaje_model->marcar_leidos_cliente($id);
            $this->crm_conversacion_model->resetear_no_leidos($id);
        }

        $salida = array();
        $ultimo = $desde;
        foreach ($rows as $m) {
            $salida[] = $this->_formatear_mensaje($m, $conv['token']);
            if ((int) $m['id'] > $ultimo) {
                $ultimo = (int) $m['id'];
            }
        }

        $this->json_ok(array(
            'mensajes'            => $salida,
            'ultimo_id'           => $ultimo,
            'estado'              => $conv['estado'],
            'modo'                => $conv['modo'],
            'no_leidos'           => (int) $conv['no_leidos_admin'],
            'verificacion_estado' => $conv['verificacion_estado'],
            'comprobante_tipo'    => $conv['comprobante_tipo'],
            'comprobante_numero'  => $conv['comprobante_numero'],
            'equipo_marca'        => $conv['equipo_marca'],
            'equipo_modelo'       => $conv['equipo_modelo'],
            'dni_cliente'         => $conv['dni_cliente'],           // nuevo
            'ruc_cliente'         => $conv['ruc_cliente'],           // nuevo
            'comprobante_detectado' => $conv['comprobante_detectado'], // nuevo
            'windows_version'     => $conv['windows_version'],       // nuevo
            'urgente'             => (int) $conv['urgente'],         // nuevo
        ));
    }

    /* =========================================================
     *  ENVIAR MENSAJE (TEXTO)
     * =======================================================*/

    public function enviar($id = 0)
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }

        $id = (int) $id;
        $conv = $this->crm_conversacion_model->obtener($id);
        if (!$conv) {
            return $this->json_error('Conversación no encontrada.', 404);
        }

        $texto = trim((string) $this->input->post('texto', FALSE));
        if ($texto === '') {
            return $this->json_error('Escribe un mensaje.', 400);
        }

        $max = $this->cfg_int('max_largo_msj', 2000);
        if (function_exists('mb_strlen') && mb_strlen($texto, 'UTF-8') > $max) {
            return $this->json_error('El mensaje supera los ' . $max . ' caracteres.', 400);
        }

        $msg_id = $this->crm_mensaje_model->crear($id, 'admin', 'texto', $texto, NULL);
        if (!$msg_id) {
            return $this->json_error('No se pudo guardar el mensaje.', 500);
        }

        // Al responder, la conversación pasa a "En atención" y modo "humano"
        $this->crm_conversacion_model->cambiar_estado($id, 'en_atencion');
        $this->crm_conversacion_model->cambiar_modo($id, 'humano');
        $this->crm_conversacion_model->resetear_no_leidos($id);

        $nuevos = $this->crm_mensaje_model->listar($id, (int) $msg_id - 1, 20);
        $salida = array();
        $ultimo = (int) $msg_id;
        foreach ($nuevos as $m) {
            $salida[] = $this->_formatear_mensaje($m, $conv['token']);
            if ((int) $m['id'] > $ultimo) {
                $ultimo = (int) $m['id'];
            }
        }

        $this->json_ok(array(
            'mensajes'  => $salida,
            'ultimo_id' => $ultimo,
            'estado'    => 'en_atencion',
            'modo'      => 'humano',
        ));
    }

    /* =========================================================
     *  ENVIAR IMAGEN (ADMIN)
     * =======================================================*/

    public function enviar_imagen($id = 0)
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }

        $id = (int) $id;
        $conv = $this->crm_conversacion_model->obtener($id);
        if (!$conv) {
            return $this->json_error('Conversación no encontrada.', 404);
        }

        if (empty($_FILES['imagen']['name'])) {
            return $this->json_error('No se recibió ninguna imagen.', 400);
        }

        $max_mb    = $this->cfg_int('max_img_mb', 5);
        $max_bytes = $max_mb * 1024 * 1024;

        if ($_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
            return $this->json_error('Error al subir la imagen.', 400);
        }
        if ($_FILES['imagen']['size'] > $max_bytes) {
            return $this->json_error('La imagen supera los ' . $max_mb . ' MB.', 400);
        }

        $mime = $this->_mime_real($_FILES['imagen']['tmp_name']);
        $mimes_ok = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
        if (!isset($mimes_ok[$mime])) {
            return $this->json_error('Formato no permitido. Usa JPG, PNG o WEBP.', 400);
        }
        $ext = $mimes_ok[$mime];

        $dir = FCPATH . 'assets/crm/uploads/chat/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, TRUE);
        }
        $nombre  = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destino = $dir . $nombre;

        if (!move_uploaded_file($_FILES['imagen']['tmp_name'], $destino)) {
            return $this->json_error('No se pudo guardar la imagen.', 500);
        }
        $this->_redimensionar_si_necesario($destino, $ext);

        $msg_id = $this->crm_mensaje_model->crear($id, 'admin', 'imagen', NULL, $nombre);
        if (!$msg_id) {
            @unlink($destino);
            return $this->json_error('No se pudo registrar la imagen.', 500);
        }

        $this->crm_conversacion_model->cambiar_estado($id, 'en_atencion');
        $this->crm_conversacion_model->cambiar_modo($id, 'humano');

        $nuevos = $this->crm_mensaje_model->listar($id, (int) $msg_id - 1, 20);
        $salida = array();
        $ultimo = (int) $msg_id;
        foreach ($nuevos as $m) {
            $salida[] = $this->_formatear_mensaje($m, $conv['token']);
            if ((int) $m['id'] > $ultimo) {
                $ultimo = (int) $m['id'];
            }
        }

        $this->json_ok(array(
            'mensajes'  => $salida,
            'ultimo_id' => $ultimo,
        ));
    }

    /* =========================================================
     *  CAMBIAR ESTADO / CATEGORÍA / MODO
     * =======================================================*/

    public function estado($id = 0)
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $id = (int) $id;
        $estado = (string) $this->input->post('estado', TRUE);

        if (!in_array($estado, array('abierta', 'en_atencion', 'cerrada'), TRUE)) {
            return $this->json_error('Estado inválido.', 400);
        }
        if (!$this->crm_conversacion_model->obtener($id)) {
            return $this->json_error('Conversación no encontrada.', 404);
        }

        $this->crm_conversacion_model->cambiar_estado($id, $estado);
        $this->json_ok(array('estado' => $estado));
    }

    public function categoria($id = 0)
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $id = (int) $id;
        $cat_id = (int) $this->input->post('categoria_id', TRUE);

        if (!$this->crm_conversacion_model->obtener($id)) {
            return $this->json_error('Conversación no encontrada.', 404);
        }
        if ($cat_id > 0 && !$this->crm_categoria_model->obtener($cat_id)) {
            return $this->json_error('Categoría inválida.', 400);
        }

        // Cambia categoría y marca categoria_manual = 1
        $this->crm_conversacion_model->cambiar_categoria_manual($id, $cat_id ?: NULL);
        $this->json_ok(array('categoria_id' => $cat_id ?: NULL));
    }

    public function modo($id = 0)
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $id   = (int) $id;
        $modo = (string) $this->input->post('modo', TRUE);

        if (!in_array($modo, array('bot', 'humano'), TRUE)) {
            return $this->json_error('Modo inválido.', 400);
        }
        if (!$this->crm_conversacion_model->obtener($id)) {
            return $this->json_error('Conversación no encontrada.', 404);
        }

        $this->crm_conversacion_model->cambiar_modo($id, $modo);

        // Si devolvemos al bot, también quitamos el pendiente y pasamos a "abierta"
        if ($modo === 'bot') {
            $this->crm_conversacion_model->marcar_pendiente_revision($id, FALSE);
            $this->crm_conversacion_model->cambiar_estado($id, 'abierta');
        }

        $this->json_ok(array('modo' => $modo));
    }

    /* =========================================================
     *  NOTAS INTERNAS
     * =======================================================*/

    public function nota($id = 0)
    {
        if (!$this->es_ajax()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $id = (int) $id;
        if (!$this->crm_conversacion_model->obtener($id)) {
            return $this->json_error('Conversación no encontrada.', 404);
        }

        if ($this->es_post()) {
            $texto = trim((string) $this->input->post('nota', FALSE));
            if ($texto === '') {
                return $this->json_error('La nota está vacía.', 400);
            }
            if (function_exists('mb_strlen') && mb_strlen($texto, 'UTF-8') > 4000) {
                return $this->json_error('La nota es demasiado larga.', 400);
            }
            $nota_id = $this->crm_nota_model->crear($id, (int) $this->admin['id'], $texto);
            if (!$nota_id) {
                return $this->json_error('No se pudo guardar la nota.', 500);
            }
            $this->json_ok(array('id' => $nota_id, 'nota' => $texto));
            return;
        }

        // GET: listar notas
        $this->json_ok(array('notas' => $this->crm_nota_model->listar_por_conversacion($id)));
    }

    /* =========================================================
     *  CONFIGURACIÓN: CATEGORÍAS
     * =======================================================*/

    public function categorias()
    {
        if (!$this->es_ajax()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $this->json_ok(array('categorias' => $this->crm_categoria_model->listar(FALSE)));
    }

    public function categorias_guardar()
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }

        $id     = (int) $this->input->post('id', TRUE);
        $nombre = trim((string) $this->input->post('nombre', TRUE));
        $color  = trim((string) $this->input->post('color', TRUE));
        $orden  = (int) $this->input->post('orden', TRUE);
        $activa = (int) $this->input->post('activa', TRUE);

        if ($nombre === '') {
            return $this->json_error('El nombre es obligatorio.', 400);
        }
        if (function_exists('mb_strlen') && mb_strlen($nombre, 'UTF-8') > 80) {
            return $this->json_error('El nombre es demasiado largo.', 400);
        }
        if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
            $color = '#6c757d';
        }

        // Evitar nombres duplicados
        $existente = $this->crm_categoria_model->obtener_por_nombre($nombre);
        if ($existente && (int) $existente['id'] !== $id) {
            return $this->json_error('Ya existe una categoría con ese nombre.', 400);
        }

        $datos = array(
            'nombre' => $nombre,
            'color'  => $color ?: '#6c757d',
            'orden'  => $orden,
            'activa' => $activa ? 1 : 0,
        );

        if ($id > 0) {
            if (!$this->crm_categoria_model->obtener($id)) {
                return $this->json_error('Categoría no encontrada.', 404);
            }
            $ok = $this->crm_categoria_model->actualizar($id, $datos);
            $this->json_ok(array('id' => $id, 'accion' => 'actualizada', 'ok' => $ok));
            return;
        }

        $nuevo_id = $this->crm_categoria_model->crear($datos);
        if (!$nuevo_id) {
            return $this->json_error('No se pudo crear la categoría.', 500);
        }
        $this->json_ok(array('id' => $nuevo_id, 'accion' => 'creada'));
    }

    public function categorias_eliminar()
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $id = (int) $this->input->post('id', TRUE);
        if ($id <= 0) {
            return $this->json_error('ID inválido.', 400);
        }
        $cat = $this->crm_categoria_model->obtener($id);
        if (!$cat) {
            return $this->json_error('Categoría no encontrada.', 404);
        }
        if ((int) $cat['es_sistema'] === 1) {
            return $this->json_error('No se puede eliminar una categoría del sistema.', 400);
        }

        // Antes de eliminar, limpiar palabras y respuestas asociadas
        // (los FK ya tienen ON DELETE CASCADE, pero por claridad)
        $this->crm_palabra_model->eliminar_por_categoria($id);

        if (!$this->crm_categoria_model->eliminar($id)) {
            return $this->json_error('No se pudo eliminar la categoría.', 500);
        }
        $this->json_ok(array('id' => $id));
    }

    /* =========================================================
     *  CONFIGURACIÓN: PALABRAS CLAVE
     * =======================================================*/

    public function palabras()
    {
        if (!$this->es_ajax()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $cat = (int) $this->input->get_post('categoria_id', TRUE);
        if ($cat > 0) {
            $this->json_ok(array('palabras' => $this->crm_palabra_model->listar_por_categoria($cat)));
            return;
        }
        $this->json_ok(array('palabras' => $this->crm_palabra_model->listar_todas()));
    }

    public function palabras_guardar()
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $id           = (int) $this->input->post('id', TRUE);
        $categoria_id = (int) $this->input->post('categoria_id', TRUE);
        $palabra      = trim((string) $this->input->post('palabra', TRUE));
        $peso         = (int) $this->input->post('peso', TRUE);

        if ($categoria_id <= 0) {
            return $this->json_error('Debes elegir una categoría.', 400);
        }
        if (!$this->crm_categoria_model->obtener($categoria_id)) {
            return $this->json_error('Categoría inválida.', 400);
        }
        if ($palabra === '') {
            return $this->json_error('La palabra es obligatoria.', 400);
        }
        if (function_exists('mb_strlen') && mb_strlen($palabra, 'UTF-8') > 120) {
            return $this->json_error('La palabra es demasiado larga.', 400);
        }
        $peso = max(1, min(5, $peso ?: 1));

        $datos = array(
            'categoria_id' => $categoria_id,
            'palabra'      => $palabra,
            'peso'         => $peso,
        );

        if ($id > 0) {
            if (!$this->crm_palabra_model->obtener($id)) {
                return $this->json_error('Palabra no encontrada.', 404);
            }
            $this->crm_palabra_model->actualizar($id, $datos);
            $this->json_ok(array('id' => $id, 'accion' => 'actualizada'));
            return;
        }

        $nuevo = $this->crm_palabra_model->crear($datos);
        if (!$nuevo) {
            return $this->json_error('No se pudo crear la palabra.', 500);
        }
        $this->json_ok(array('id' => $nuevo, 'accion' => 'creada'));
    }

    public function palabras_eliminar()
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $id = (int) $this->input->post('id', TRUE);
        if ($id <= 0) {
            return $this->json_error('ID inválido.', 400);
        }
        if (!$this->crm_palabra_model->obtener($id)) {
            return $this->json_error('Palabra no encontrada.', 404);
        }
        $this->crm_palabra_model->eliminar($id);
        $this->json_ok(array('id' => $id));
    }

    /* =========================================================
     *  CONFIGURACIÓN: RESPUESTAS AUTOMÁTICAS
     * =======================================================*/

    public function respuestas()
    {
        if (!$this->es_ajax()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $cat = (int) $this->input->get_post('categoria_id', TRUE);
        if ($cat > 0) {
            $this->json_ok(array('respuestas' => $this->crm_respuesta_model->listar_por_categoria($cat)));
            return;
        }
        $this->json_ok(array('respuestas' => $this->crm_respuesta_model->listar_todas()));
    }

    public function respuestas_guardar()
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $id           = (int) $this->input->post('id', TRUE);
        $categoria_id = (int) $this->input->post('categoria_id', TRUE);
        $texto        = trim((string) $this->input->post('texto', FALSE));
        $activa       = (int) $this->input->post('activa', TRUE);

        if ($categoria_id <= 0) {
            return $this->json_error('Debes elegir una categoría.', 400);
        }
        if (!$this->crm_categoria_model->obtener($categoria_id)) {
            return $this->json_error('Categoría inválida.', 400);
        }
        if ($texto === '') {
            return $this->json_error('El texto es obligatorio.', 400);
        }
        if (function_exists('mb_strlen') && mb_strlen($texto, 'UTF-8') > 4000) {
            return $this->json_error('El texto es demasiado largo (máx 4000).', 400);
        }

        $datos = array(
            'categoria_id' => $categoria_id,
            'texto'        => $texto,
            'activa'       => $activa ? 1 : 0,
        );

        if ($id > 0) {
            if (!$this->crm_respuesta_model->obtener($id)) {
                return $this->json_error('Respuesta no encontrada.', 404);
            }
            $this->crm_respuesta_model->actualizar($id, $datos);
            $this->json_ok(array('id' => $id, 'accion' => 'actualizada'));
            return;
        }

        $nuevo = $this->crm_respuesta_model->crear($datos);
        if (!$nuevo) {
            return $this->json_error('No se pudo crear la respuesta.', 500);
        }
        $this->json_ok(array('id' => $nuevo, 'accion' => 'creada'));
    }

    public function respuestas_eliminar()
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $id = (int) $this->input->post('id', TRUE);
        if ($id <= 0) {
            return $this->json_error('ID inválido.', 400);
        }
        if (!$this->crm_respuesta_model->obtener($id)) {
            return $this->json_error('Respuesta no encontrada.', 404);
        }
        $this->crm_respuesta_model->eliminar($id);
        $this->json_ok(array('id' => $id));
    }

    /* =========================================================
     *  CONFIGURACIÓN GENERAL
     * =======================================================*/

    public function configuracion()
    {
        if (!$this->es_ajax()) {
            return $this->json_error('Método no permitido.', 405);
        }

        if (!$this->es_post()) {
            $this->json_ok(array('config' => $this->crm_config_model->obtener_todas()));
            return;
        }

        // Claves editables desde el panel
        $permitidas = array(
            'horario_lv_inicio', 'horario_lv_fin',
            'horario_sa_inicio', 'horario_sa_fin',
            'horario_do_cerrado',
            'texto_saludo_inicial', 'texto_fuera_horario',
            'texto_sin_clasificar', 'texto_imagen_sin_texto',
            'texto_privacidad',
            'max_mensajes_conversacion', 'max_msj_hora_conv', 'max_msj_hora_ip',
            'max_img_mb', 'img_ancho_max', 'max_largo_msj',
            'polling_cliente_seg', 'polling_admin_seg',
            'sonido_admin', 'bot_repetir_min',
        );

        $pares = array();
        foreach ($permitidas as $clave) {
            $valor = $this->input->post($clave, FALSE);
            if ($valor !== NULL) {
                $pares[$clave] = is_string($valor) ? trim($valor) : $valor;
            }
        }

        if (empty($pares)) {
            return $this->json_error('Nada que guardar.', 400);
        }

        // Validaciones básicas
        foreach (array('horario_lv_inicio','horario_lv_fin','horario_sa_inicio','horario_sa_fin') as $h) {
            if (isset($pares[$h]) && !preg_match('/^\d{2}:\d{2}$/', $pares[$h])) {
                return $this->json_error('Horario inválido en ' . $h . '. Formato HH:MM.', 400);
            }
        }
        foreach (array('max_mensajes_conversacion','max_msj_hora_conv','max_msj_hora_ip','max_img_mb','img_ancho_max','max_largo_msj','polling_cliente_seg','polling_admin_seg','bot_repetir_min','sonido_admin','horario_do_cerrado') as $n) {
            if (isset($pares[$n]) && !is_numeric($pares[$n])) {
                return $this->json_error('Valor numérico inválido en ' . $n . '.', 400);
            }
        }

        $this->crm_config_model->guardar_multiples($pares);
        $this->json_ok(array('guardadas' => array_keys($pares)));
    }

    /* =========================================================
     *  INTERNOS
     * =======================================================*/

    protected function _formatear_mensaje(array $m, $token)
    {
        return array(
            'id'         => (int) $m['id'],
            'emisor'     => $m['emisor'],
            'tipo'       => $m['tipo'],
            'texto'      => $m['tipo'] === 'texto' ? (string) $m['contenido'] : '',
'texto_html' => $m['tipo'] === 'texto' ? crm_links_clicables($m['contenido']) : '',
            'archivo'    => $m['archivo'] ? crm_archivo_url($m['archivo'], $token) : NULL,
            'creado_at'  => $m['creado_at'],
            'hora'       => crm_hora_corta($m['creado_at']),
            'leido'      => (int) $m['leido'],
        );
    }

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

    protected function _redimensionar_si_necesario($ruta, $ext)
    {
        $ancho_max = $this->cfg_int('img_ancho_max', 1600);
        if ($ancho_max <= 0) return;
        $info = @getimagesize($ruta);
        if (!$info) return;
        list($w, $h) = $info;
        if ($w <= $ancho_max) return;

        $this->load->library('image_lib');
        $this->image_lib->initialize(array(
            'image_library'  => 'gd2',
            'source_image'   => $ruta,
            'maintain_ratio' => TRUE,
            'width'          => $ancho_max,
            'quality'        => '82%',
        ));
        if (!$this->image_lib->resize()) {
            log_message('error', 'image_lib resize: ' . $this->image_lib->display_errors('', ''));
        }
        $this->image_lib->clear();
    }
        /* =========================================================
     *  VERIFICACIÓN DE COMPROBANTE
     * =======================================================*/

    /**
     * POST /crm/api/verificar/{id}
     * Body: comprobante_tipo (boleta|factura|otro), comprobante_numero (opcional)
     */
    public function verificar($id = 0)
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $id = (int) $id;
        $conv = $this->crm_conversacion_model->obtener($id);
        if (!$conv) {
            return $this->json_error('Conversación no encontrada.', 404);
        }

        $tipo   = trim((string) $this->input->post('comprobante_tipo', TRUE));
        $numero = trim((string) $this->input->post('comprobante_numero', TRUE));

        $permitidos = array('boleta', 'factura', 'otro');
        if ($tipo !== '' && !in_array($tipo, $permitidos, TRUE)) {
            return $this->json_error('Tipo de comprobante inválido.', 400);
        }
        if (function_exists('mb_strlen')) {
            if (mb_strlen($numero, 'UTF-8') > 40) {
                return $this->json_error('El número de comprobante es demasiado largo.', 400);
            }
        }

        $this->crm_conversacion_model->verificar_comprobante(
            $id,
            (int) $this->admin['id'],
            $tipo !== '' ? $tipo : NULL,
            $numero
        );

        $conv2 = $this->crm_conversacion_model->obtener($id);
        $this->json_ok(array(
            'verificacion_estado' => $conv2['verificacion_estado'],
            'comprobante_tipo'    => $conv2['comprobante_tipo'],
            'comprobante_numero'  => $conv2['comprobante_numero'],
            'verificado_at'       => $conv2['verificado_at'],
        ));
    }

    /**
     * POST /crm/api/quitar_verificacion/{id}
     */
    public function quitar_verificacion($id = 0)
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $id = (int) $id;
        $conv = $this->crm_conversacion_model->obtener($id);
        if (!$conv) {
            return $this->json_error('Conversación no encontrada.', 404);
        }
        $this->crm_conversacion_model->quitar_verificacion($id);
        $this->json_ok(array('verificacion_estado' => 'sin_verificar'));
    }

    /* =========================================================
     *  RECURSOS (SELECTOR)
     * =======================================================*/

    /**
     * GET /crm/api/recursos_listar?q=&tipo=
     * Lista completa para el selector. Solo activos.
     */
    public function recursos_listar()
    {
        if (!$this->es_ajax()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $this->load->model('crm_recurso_model');

        $filtros = array(
            'q'     => (string) $this->input->get_post('q', TRUE),
            'tipo'  => (string) $this->input->get_post('tipo', TRUE),
            'activo'=> 1,
        );

        $rows = $this->crm_recurso_model->listar_con_filtros($filtros, 500, 0);

        $salida = array();
        foreach ($rows as $r) {
            $salida[] = array(
                'id'             => (int) $r['id'],
                'marca'          => $r['marca'],
                'modelo'         => $r['modelo'],
                'tipo'           => $r['tipo'],
                'titulo'         => $r['titulo'],
                'url'            => $r['url'],
                'sistema'        => $r['sistema'],
                'palabras_clave' => $r['palabras_clave'],
            );
        }
        $this->json_ok(array('recursos' => $salida));
    }

    /**
     * GET /crm/api/recursos_sugeridos/{id}
     * Sugeridos según equipo_marca / equipo_modelo de la conversación.
     * Además devuelve el mapa de "ya enviados" a esta conversación.
     */
    public function recursos_sugeridos($id = 0)
    {
        if (!$this->es_ajax()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $id = (int) $id;
        $conv = $this->crm_conversacion_model->obtener($id);
        if (!$conv) {
            return $this->json_error('Conversación no encontrada.', 404);
        }

        $this->load->model('crm_recurso_model');
        $this->load->model('crm_recurso_envio_model');

        $rows = $this->crm_recurso_model->listar_sugeridos(
            isset($conv['equipo_marca'])  ? $conv['equipo_marca']  : '',
            isset($conv['equipo_modelo']) ? $conv['equipo_modelo'] : '',
            6
        );

        $salida = array();
        foreach ($rows as $r) {
            $salida[] = array(
                'id'     => (int) $r['id'],
                'marca'  => $r['marca'],
                'modelo' => $r['modelo'],
                'tipo'   => $r['tipo'],
                'titulo' => $r['titulo'],
                'url'    => $r['url'],
            );
        }

        $enviados = $this->crm_recurso_envio_model->mapa_recursos_enviados($id);

        $this->json_ok(array(
            'equipo_marca'  => $conv['equipo_marca'],
            'equipo_modelo' => $conv['equipo_modelo'],
            'sugeridos'     => $salida,
            'ya_enviados'   => array_keys($enviados),
        ));
    }

    /**
     * POST /crm/api/enviar_recursos/{id}
     * Body: recurso_ids (JSON o csv), intro (texto de introducción)
     * Reglas:
     *  - Si exigir_verificacion = 1 y la conversación no está verificada → 403.
     *  - Guarda UN mensaje 'admin' con intro + título + URL por recurso.
     *  - Registra cada envío en crm_recursos_envios.
     *  - Pasa la conversación a modo 'humano' y estado 'en_atencion'.
     */
    public function enviar_recursos($id = 0)
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $id = (int) $id;
        $conv = $this->crm_conversacion_model->obtener($id);
        if (!$conv) {
            return $this->json_error('Conversación no encontrada.', 404);
        }

        // Bloqueo por verificación
        $exigir = (int) $this->cfg_int('exigir_verificacion', 1);
        if ($exigir === 1 && $conv['verificacion_estado'] !== 'verificado') {
            return $this->json_error('Verifica primero la boleta o factura antes de enviar recursos.', 403);
        }

        // IDs de recursos
        $raw = $this->input->post('recurso_ids', FALSE);
        $ids = array();
        if (is_array($raw)) {
            foreach ($raw as $r) {
                $r = (int) $r;
                if ($r > 0) $ids[] = $r;
            }
        } else if (is_string($raw) && $raw !== '') {
            $parts = preg_split('/[,\s]+/', $raw);
            foreach ($parts as $r) {
                $r = (int) $r;
                if ($r > 0) $ids[] = $r;
            }
        }
        $ids = array_values(array_unique($ids));

        if (empty($ids)) {
            return $this->json_error('Selecciona al menos un recurso.', 400);
        }

        // Cargar recursos y validar
        $this->load->model('crm_recurso_model');
        $recursos = array();
        foreach ($ids as $rid) {
            $r = $this->crm_recurso_model->obtener($rid);
            if (!$r || (int) $r['activo'] !== 1) continue;
            $recursos[] = $r;
        }
        if (empty($recursos)) {
            return $this->json_error('Los recursos seleccionados no están disponibles.', 400);
        }

        // Texto de introducción (editable por el admin en el preview)
        $intro = trim((string) $this->input->post('intro', FALSE));
        if ($intro === '') {
            $intro = (string) $this->cfg(
                'texto_envio_recursos',
                'Hola, ya verificamos tu comprobante. Te compartimos el material para tu equipo:'
            );
        }
        // Aplicar variables {empresa} {horario} {maps} si el helper las soporta.
        // (Bot_service ya las aplica; aquí solo hacemos un replace simple de {empresa}.)
        $intro = str_replace('{empresa}', (string) $this->cfg('empresa_comercial', 'GSP IMPORT'), $intro);

        if (function_exists('mb_strlen')) {
            if (mb_strlen($intro, 'UTF-8') > 2000) {
                return $this->json_error('El texto de introducción es demasiado largo.', 400);
            }
        }

        // Armar el mensaje combinado
        $lineas = array();
        $lineas[] = $intro;
        foreach ($recursos as $r) {
            $lineas[] = '';
            $lineas[] = '• ' . $r['titulo'];
            $lineas[] = '  ' . $r['url'];
        }
        $texto_mensaje = implode("\n", $lineas);

        // Guardar el mensaje del admin
        $msg_id = $this->crm_mensaje_model->crear($id, 'admin', 'texto', $texto_mensaje, NULL);
        if (!$msg_id) {
            return $this->json_error('No se pudo guardar el mensaje.', 500);
        }

        // Registrar cada envío
        $this->load->model('crm_recurso_envio_model');
        $this->crm_recurso_envio_model->registrar_lote(
            $id,
            $msg_id,
            (int) $this->admin['id'],
            array_map(function ($r) { return (int) $r['id']; }, $recursos)
        );

        // Pasar la conversación a modo humano y en atención
        $this->crm_conversacion_model->cambiar_modo($id, 'humano');
        $this->crm_conversacion_model->cambiar_estado($id, 'en_atencion');
        $this->crm_conversacion_model->resetear_no_leidos($id);

        // Devolver los mensajes nuevos (para que el front los pinte)
        $nuevos = $this->crm_mensaje_model->listar($id, (int) $msg_id - 1, 20);
        $lista = array();
        $ultimo = (int) $msg_id;
        foreach ($nuevos as $m) {
            $lista[] = $this->_formatear_mensaje($m, $conv['token']);
            if ((int) $m['id'] > $ultimo) $ultimo = (int) $m['id'];
        }

        $this->json_ok(array(
            'mensajes'  => $lista,
            'ultimo_id' => $ultimo,
            'modo'      => 'humano',
            'estado'    => 'en_atencion',
            'enviados'  => count($recursos),
        ));
    }
        /* =========================================================
     *  GESTIÓN DE RECURSOS (pantalla /crm/panel/recursos)
     * =======================================================*/

    /**
     * GET /crm/api/recursos_admin_listar
     * Filtros: q, tipo, activo, marca, pagina
     */
    public function recursos_admin_listar()
    {
        if (!$this->es_ajax()) return $this->json_error('Método no permitido.', 405);
        $this->load->model('crm_recurso_model');

        $filtros = array(
            'q'      => (string) $this->input->get_post('q', TRUE),
            'tipo'   => (string) $this->input->get_post('tipo', TRUE),
            'marca'  => (string) $this->input->get_post('marca', TRUE),
            'activo' => $this->input->get_post('activo', TRUE),
        );
        $pagina = max(1, (int) $this->input->get_post('pagina', TRUE));
        $limit  = 25;
        $offset = ($pagina - 1) * $limit;

        $filas  = $this->crm_recurso_model->listar_admin($filtros, $limit, $offset);
        $total  = $this->crm_recurso_model->contar_admin($filtros);

        $salida = array();
        foreach ($filas as $r) {
            $salida[] = array(
                'id'             => (int) $r['id'],
                'marca'          => $r['marca'],
                'modelo'         => $r['modelo'],
                'tipo'           => $r['tipo'],
                'titulo'         => $r['titulo'],
                'url'            => $r['url'],
                'sistema'        => $r['sistema'],
                'activo'         => (int) $r['activo'],
                'palabras_clave' => $r['palabras_clave'],
                'notas'          => $r['notas'],
                'veces_enviado'  => (int) $r['veces_enviado'],
                'creado_at'      => $r['creado_at'],
                'actualizado_at' => $r['actualizado_at'],
            );
        }

        $this->json_ok(array(
            'recursos' => $salida,
            'total'    => $total,
            'pagina'   => $pagina,
            'paginas'  => max(1, (int) ceil($total / $limit)),
        ));
    }

    /**
     * GET /crm/api/recursos_admin_obtener/{id}
     */
    public function recursos_admin_obtener($id = 0)
    {
        if (!$this->es_ajax()) return $this->json_error('Método no permitido.', 405);
        $this->load->model('crm_recurso_model');

        $r = $this->crm_recurso_model->obtener((int) $id);
        if (!$r) return $this->json_error('Recurso no encontrado.', 404);

        $this->json_ok(array('recurso' => $r));
    }

    /**
     * POST /crm/api/recursos_admin_guardar
     * Body: id (0 = crear), marca, modelo, palabras_clave, tipo, titulo,
     *       url, sistema, notas, activo
     */
    public function recursos_admin_guardar()
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $this->load->model('crm_recurso_model');

        $id     = (int) $this->input->post('id', TRUE);
        $marca  = trim((string) $this->input->post('marca', TRUE));
        $modelo = trim((string) $this->input->post('modelo', TRUE));
        $tipo   = trim((string) $this->input->post('tipo', TRUE));
        $titulo = trim((string) $this->input->post('titulo', TRUE));
        $url    = trim((string) $this->input->post('url', TRUE));

        if ($marca === '')  return $this->json_error('La marca es obligatoria.', 400);
        if ($modelo === '') return $this->json_error('El modelo es obligatorio.', 400);
        if (!in_array($tipo, array('driver', 'video', 'manual'), TRUE)) {
            return $this->json_error('Tipo inválido.', 400);
        }
        if ($titulo === '') return $this->json_error('El título es obligatorio.', 400);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return $this->json_error('La URL debe empezar con http:// o https://', 400);
        }
        if (strlen($url) > 500) {
            return $this->json_error('La URL es demasiado larga.', 400);
        }

        $datos = array(
            'marca'          => $marca,
            'modelo'         => $modelo,
            'palabras_clave' => (string) $this->input->post('palabras_clave', TRUE),
            'tipo'           => $tipo,
            'titulo'         => $titulo,
            'url'            => $url,
            'sistema'        => (string) $this->input->post('sistema', TRUE),
            'notas'          => (string) $this->input->post('notas', TRUE),
            'activo'         => (int) $this->input->post('activo', TRUE) ? 1 : 0,
        );

        if ($id > 0) {
            $this->crm_recurso_model->actualizar($id, $datos);
        } else {
            $id = $this->crm_recurso_model->crear($datos);
        }

        $this->json_ok(array(
            'id'     => (int) $id,
            'accion' => ($id > 0) ? 'guardado' : 'creado',
            'url_duplicada' => $this->crm_recurso_model->url_existe($url, $id),
        ));
    }

    /**
     * POST /crm/api/recursos_admin_eliminar/{id}
     * Bloquea si el recurso tiene envíos registrados.
     */
    public function recursos_admin_eliminar($id = 0)
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $this->load->model('crm_recurso_model');
        $this->load->model('crm_recurso_envio_model');

        $id = (int) $id;
        $r = $this->crm_recurso_model->obtener($id);
        if (!$r) return $this->json_error('Recurso no encontrado.', 404);

        $envios = $this->crm_recurso_envio_model->contar_por_recurso($id);
        if ($envios > 0) {
            return $this->json_error('Este recurso tiene ' . $envios .
                ' envíos registrados. Solo puedes desactivarlo.', 400);
        }

        $this->crm_recurso_model->eliminar($id);
        $this->json_ok(array('id' => $id));
    }

    /**
     * POST /crm/api/recursos_admin_duplicar/{id}
     */
    public function recursos_admin_duplicar($id = 0)
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $this->load->model('crm_recurso_model');
        $nuevo_id = $this->crm_recurso_model->duplicar((int) $id);
        if (!$nuevo_id) return $this->json_error('No se pudo duplicar.', 500);
        $this->json_ok(array('id' => (int) $nuevo_id));
    }

    /**
     * POST /crm/api/recursos_admin_toggle/{id}
     */
    public function recursos_admin_toggle($id = 0)
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $this->load->model('crm_recurso_model');
        $nuevo = $this->crm_recurso_model->toggle_activo((int) $id);
        if ($nuevo === FALSE) return $this->json_error('Recurso no encontrado.', 404);
        $this->json_ok(array('activo' => (int) $nuevo));
    }

    /**
     * GET /crm/api/recursos_admin_marcas
     */
    public function recursos_admin_marcas()
    {
        if (!$this->es_ajax()) return $this->json_error('Método no permitido.', 405);
        $this->load->model('crm_recurso_model');
        $this->json_ok(array('marcas' => $this->crm_recurso_model->listar_marcas()));
    }

    /**
     * POST /crm/api/recursos_admin_importar
     * Body: texto (pegado), tipo_defecto (para formatos b y c)
     * NO guarda: devuelve preview con filas clasificadas.
     * Para guardar definitivamente, el front envía cada fila con
     * recursos_admin_guardar.
     */
    public function recursos_admin_importar()
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $this->load->model('crm_recurso_model');

        $texto = (string) $this->input->post('texto', FALSE);
        $tipo_defecto = (string) $this->input->post('tipo_defecto', TRUE);
        if (!in_array($tipo_defecto, array('driver', 'video', 'manual'), TRUE)) {
            $tipo_defecto = 'driver';
        }

        if (trim($texto) === '') {
            return $this->json_error('Pega el texto a importar.', 400);
        }

        $lineas = preg_split('/\r\n|\r|\n/', $texto);
        $filas = array();
        $idx = 0;

        foreach ($lineas as $linea) {
            $idx++;
            $linea = trim($linea);
            if ($linea === '') continue;

            $fila = array(
                'n'      => $idx,
                'linea'  => $linea,
                'estado' => 'nueva',
                'error'  => NULL,
                'datos'  => NULL,
            );

            // Formato A: "Marca | Modelo | Tipo | Título | URL | Sistema"
            if (substr_count($linea, '|') >= 4) {
                $partes = array_map('trim', explode('|', $linea));
                while (count($partes) < 6) $partes[] = '';
                $marca  = $partes[0];
                $modelo = $partes[1];
                $tipo   = $partes[2];
                $titulo = $partes[3];
                $url    = $partes[4];
                $sistema= $partes[5];

                if (!in_array($tipo, array('driver','video','manual'), TRUE)) {
                    $fila['estado'] = 'error';
                    $fila['error']  = 'Tipo inválido: ' . $tipo;
                } else if ($marca === '' || $modelo === '' || $titulo === '' || $url === '') {
                    $fila['estado'] = 'error';
                    $fila['error']  = 'Faltan campos obligatorios';
                } else {
                    $fila['datos'] = array(
                        'marca' => $marca, 'modelo' => $modelo, 'tipo' => $tipo,
                        'titulo' => $titulo, 'url' => $url, 'sistema' => $sistema,
                    );
                }
            }
            // Formato B: "MARCA / MODELO --> URL"
            else if (preg_match('#^(.+?)\s*/\s*(.+?)\s*-->\s*(\S+)\s*$#', $linea, $m)) {
                $marca  = trim($m[1]);
                $modelo = trim($m[2]);
                $url    = trim($m[3]);
                $fila['datos'] = array(
                    'marca' => $marca, 'modelo' => $modelo,
                    'tipo' => $tipo_defecto,
                    'titulo' => $marca . ' ' . $modelo,
                    'url' => $url, 'sistema' => '',
                );
            }
            // Formato C: "TÍTULO --> URL"
            else if (preg_match('#^(.+?)\s*-->\s*(\S+)\s*$#', $linea, $m)) {
                $titulo = trim($m[1]);
                $url    = trim($m[2]);
                $fila['datos'] = array(
                    'marca' => 'OTROS', 'modelo' => 'POR DEFINIR',
                    'tipo' => $tipo_defecto,
                    'titulo' => $titulo, 'url' => $url, 'sistema' => '',
                );
            }
            else {
                $fila['estado'] = 'error';
                $fila['error']  = 'Formato no reconocido';
            }

            // Verificar duplicado por URL
            if ($fila['estado'] === 'nueva' && !empty($fila['datos']['url'])) {
                if ($this->crm_recurso_model->url_existe($fila['datos']['url'], NULL)) {
                    $fila['estado'] = 'duplicada';
                }
            }

            $filas[] = $fila;
        }

        // Resumen
        $resumen = array('nuevas' => 0, 'duplicadas' => 0, 'errores' => 0);
        foreach ($filas as $f) {
            if ($f['estado'] === 'nueva')      $resumen['nuevas']++;
            if ($f['estado'] === 'duplicada')  $resumen['duplicadas']++;
            if ($f['estado'] === 'error')      $resumen['errores']++;
        }

        $this->json_ok(array('filas' => $filas, 'resumen' => $resumen));
    }

    /**
     * GET /crm/api/recursos_admin_exportar_csv
     * Devuelve CSV como descarga (no JSON).
     */
    public function recursos_admin_exportar_csv()
    {
        $this->load->model('crm_recurso_model');

        $filas = $this->crm_recurso_model->listar_admin(array(), 10000, 0);

        $this->output->set_content_type('text/csv', 'utf-8');
        $this->output->set_header('Content-Disposition: attachment; filename="recursos_crm_' . date('Ymd_His') . '.csv"');

        $out = fopen('php://output', 'w');
        // BOM para que Excel respete UTF-8
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array('ID','Marca','Modelo','Tipo','Título','URL','Sistema','Palabras clave','Activo','Veces enviado'));
        foreach ($filas as $r) {
            fputcsv($out, array(
                $r['id'], $r['marca'], $r['modelo'], $r['tipo'], $r['titulo'],
                $r['url'], $r['sistema'], $r['palabras_clave'],
                $r['activo'] ? 'Sí' : 'No',
                isset($r['veces_enviado']) ? $r['veces_enviado'] : 0,
            ));
        }
        fclose($out);
    }
        /* =========================================================
     *  RESPUESTAS RÁPIDAS (mejora #6)
     * =======================================================*/

    public function rr_listar()
    {
        if (!$this->es_ajax()) return $this->json_error('Método no permitido.', 405);
        $this->load->model('crm_respuesta_rapida_model');
        $this->json_ok(array(
            'respuestas' => $this->crm_respuesta_rapida_model->listar(FALSE),
        ));
    }

    public function rr_guardar()
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $this->load->model('crm_respuesta_rapida_model');

        $id     = (int) $this->input->post('id', TRUE);
        $atajo  = trim((string) $this->input->post('atajo', TRUE));
        $titulo = trim((string) $this->input->post('titulo', TRUE));
        $texto  = trim((string) $this->input->post('texto', FALSE));

        if ($atajo === '')  return $this->json_error('El atajo es obligatorio.', 400);
        if ($titulo === '') return $this->json_error('El título es obligatorio.', 400);
        if ($texto === '')  return $this->json_error('El texto es obligatorio.', 400);

        $datos = array(
            'atajo'  => $atajo,
            'titulo' => $titulo,
            'texto'  => $texto,
            'activa' => (int) $this->input->post('activa', TRUE) ? 1 : 0,
            'orden'  => (int) $this->input->post('orden', TRUE) ?: 100,
        );

        if ($id > 0) {
            $this->crm_respuesta_rapida_model->actualizar($id, $datos);
        } else {
            $id = $this->crm_respuesta_rapida_model->crear($datos);
        }
        $this->json_ok(array('id' => (int) $id));
    }

    public function rr_eliminar()
    {
        if (!$this->es_ajax() || !$this->es_post()) {
            return $this->json_error('Método no permitido.', 405);
        }
        $this->load->model('crm_respuesta_rapida_model');
        $id = (int) $this->input->post('id', TRUE);
        if ($id <= 0) return $this->json_error('ID inválido.', 400);
        $this->crm_respuesta_rapida_model->eliminar($id);
        $this->json_ok(array('id' => $id));
    }
}