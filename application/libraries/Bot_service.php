<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Bot_service (v2.1)
 * ------------------
 * Chatbot gratuito basado SOLO en palabras clave y respuestas configurables.
 *
 * Cambios v2.1 (Bloque 1 de mejoras):
 *  - Mejora #4: detección de ficha automática (DNI, RUC, comprobante, Windows).
 *  - Mejora #7: detección de urgencia y respuesta específica.
 *  - Propiedad $palabras_urgencia que se sobreescribe desde crm_configuracion.
 *
 * Base v2:
 *  - Flujo único por mensaje: SIEMPRE clasifica primero.
 *  - Respuesta combinada: saludo (si es el 1er mensaje) + categoría + fuera_horario.
 *  - Anti-repetición por respuesta específica.
 *  - Detección de recurso / equipo / comprobante.
 *  - Numéricos como continuación.
 *  - Coincidencia por palabra completa con tolerancia a plurales.
 *  - Desempate: puntaje → peso individual → orden categoría.
 *  - Variables {empresa} {horario} {maps}.
 *
 * PHP 7.2 (nada de fn, match, str_contains, ?->, typed properties).
 */
class Bot_service
{
    /** @var CI_Controller */
    protected $CI;

    protected $palabras = NULL;
    protected $respuestas = NULL;
    protected $recursos = NULL;
    protected $cfg = array();

    /** Palabras gatillo para "solicita recurso" */
    protected $gatillos_recurso = array(
        'video','videos','tutorial','tutoriales','driver','drivers',
        'manual','manuales','instalador','instaladores','link','enlace',
        'como instalo','como instalar','donde descargo','donde lo descargo',
    );

    /** Palabras de urgencia (se sobreescriben desde la config) */
    protected $palabras_urgencia = array(
        'urgente','es urgente','urgente por favor','no puedo vender',
        'urgente para hoy','lo necesito hoy','emergencia','es para hoy',
    );

    /** Frases que indican que el cliente está mandando su comprobante */
    protected $frases_comprobante = array(
        'mi comprobante','este es mi comprobante','ese es mi comprobante',
        'aqui esta mi comprobante','aqui mi comprobante',
        'te envio mi comprobante','envio mi comprobante','le envio mi comprobante',
        'adjunto','adjunto mi comprobante','adjunto comprobante',
        'adjunto mi boleta','adjunto mi factura',
        'mi boleta','mi factura','te mando mi boleta','te mando mi factura',
        'le mando mi boleta','le mando mi factura',
    );

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->model(array(
            'crm_palabra_model',
            'crm_respuesta_model',
            'crm_categoria_model',
            'crm_config_model',
            'crm_bot_log_model',
            'crm_etiqueta_model',
            'crm_recurso_model',
        ));
    }

    /* =========================================================
     *  API PÚBLICA
     * =======================================================*/

    public function procesar_mensaje(array $conversacion, $texto, $tipo, $es_primer_mensaje = FALSE)
    {
        $this->cargar_datos();
        $this->_cargar_cfg();

        $accion = array(
            'recategorizar'       => FALSE,
            'categoria_id'        => NULL,
            'etiquetas'           => array(),
            'marcar_pendiente'    => FALSE,
            'responder'           => FALSE,
            'respuesta_texto'     => NULL,
            'tipo_respuesta'      => NULL,
            'categoria_respuesta' => NULL,
            'tipos_log'           => array(),
            'solicita_recurso'    => FALSE,
            'equipo_marca'        => NULL,
            'equipo_modelo'       => NULL,
            'verificacion_estado' => NULL,
            'no_responder'        => FALSE,
            'ficha'               => array(),  // v2.1 (mejora #4)
            'urgente'             => FALSE,    // v2.1 (mejora #7)
        );

        $modo   = isset($conversacion['modo']) ? $conversacion['modo'] : 'bot';
        $manual = !empty($conversacion['categoria_manual']);

        $texto_norm  = $this->normalizar($texto);
        $tiene_texto = ($texto_norm !== '');
        $es_imagen   = ($tipo === 'imagen');

        /* -------- 1) Detección de equipo (SIEMPRE) -------- */
        if ($tiene_texto) {
            $equipo = $this->detectar_equipo($texto_norm);
            if ($equipo !== NULL) {
                if (!empty($equipo['marca']))  $accion['equipo_marca']  = $equipo['marca'];
                if (!empty($equipo['modelo'])) $accion['equipo_modelo'] = $equipo['modelo'];
            }
        }

        /* -------- 1.b) Ficha automática (mejoras #4 y #7) -------- */
        if ($tiene_texto) {
            $ficha = $this->detectar_ficha($texto_norm, $texto);
            if (!empty($ficha)) {
                $accion['ficha'] = $ficha;
                if (!empty($ficha['urgente'])) {
                    $accion['urgente'] = TRUE;
                }
            }
        }

        /* -------- 2) Detección de solicitud de recurso (SIEMPRE) -------- */
        if ($tiene_texto && $this->detectar_solicitud_recurso($texto_norm)) {
            $accion['solicita_recurso'] = TRUE;
        }

        /* -------- 3) Detección de comprobante (SIEMPRE) -------- */
        $es_comprobante = FALSE;
        if ($tiene_texto && $this->detectar_comprobante($texto_norm)) {
            $es_comprobante = TRUE;
        } else if ($es_imagen && !$tiene_texto) {
            if (!empty($conversacion['solicita_recurso'])) {
                $es_comprobante = TRUE;
            } else if ($this->CI->crm_bot_log_model
                    ->ya_respondido_recientemente((int)$conversacion['id'], 'solicitud_recurso', 120)) {
                $es_comprobante = TRUE;
            }
        } else if ($es_imagen && $tiene_texto && $this->detectar_comprobante($texto_norm)) {
            $es_comprobante = TRUE;
        }

        if ($es_comprobante && $conversacion['verificacion_estado'] !== 'verificado') {
            $accion['verificacion_estado'] = 'pendiente';
        }

        /* -------- 4) Clasificación (solo si hay texto) -------- */
        if ($tiene_texto) {
            $clasif = $this->clasificar($texto_norm);

            // Si viene comprobante, NO queremos que gane "Pagos y comprobantes".
            $id_pagos = 7;
            if ($es_comprobante && (int)$clasif['principal_id'] === $id_pagos) {
                $clasif['principal_id'] = $conversacion['categoria_id'] ? (int)$conversacion['categoria_id'] : NULL;
                $clasif['etiquetas'] = array();
            }

            if (!$manual) {
                $accion['recategorizar'] = TRUE;
                $accion['categoria_id']  = $clasif['principal_id'];
            } else {
                $accion['categoria_id'] = $conversacion['categoria_id'];
            }
            $accion['etiquetas'] = $clasif['etiquetas'];

            if ($clasif['principal_id'] === NULL && !$manual && !$es_comprobante) {
                $accion['marcar_pendiente'] = TRUE;
            }
        } else if ($es_imagen) {
            $accion['recategorizar'] = FALSE;
            $accion['categoria_id']  = $conversacion['categoria_id'];
        }

        /* -------- 5) Respuestas numéricas -------- */
        $es_numerico = FALSE;
        if ($tiene_texto && $this->_es_respuesta_numerica($texto_norm)) {
            $es_numerico = TRUE;
            $accion['recategorizar'] = FALSE;
            $accion['categoria_id']  = $conversacion['categoria_id'];
            $accion['etiquetas']     = array();
            $accion['marcar_pendiente'] = TRUE;
        }

        /* -------- 6) Respuesta automática (solo modo bot) -------- */
        if ($modo !== 'bot') {
            return $accion;
        }

        // Pasamos el flag de urgencia a través del array de conversación,
        // para no cambiar la firma de _construir_respuesta().
        $conversacion['_urgente_msg'] = !empty($accion['urgente']);

        $respuesta = $this->_construir_respuesta(
            $conversacion,
            $es_primer_mensaje,
            $tiene_texto,
            $es_imagen,
            $es_comprobante,
            $es_numerico,
            $accion['solicita_recurso'],
            $accion['categoria_id']
        );

        if ($respuesta === NULL) {
            $accion['no_responder'] = TRUE;
            return $accion;
        }

        $accion['responder']            = TRUE;
        $accion['respuesta_texto']      = $respuesta['texto'];
        $accion['tipo_respuesta']       = $respuesta['tipo'];
        $accion['categoria_respuesta']  = $respuesta['categoria_id'];
        $accion['tipos_log']            = $respuesta['tipos_log'];
        if (!empty($respuesta['marcar_pendiente'])) {
            $accion['marcar_pendiente'] = TRUE;
        }

        return $accion;
    }

    /* =========================================================
     *  NORMALIZACIÓN Y CLASIFICACIÓN
     * =======================================================*/

    public function normalizar($texto)
    {
        $texto = trim((string) $texto);
        if ($texto === '') {
            return '';
        }
        $texto = function_exists('mb_strtolower')
            ? mb_strtolower($texto, 'UTF-8')
            : strtolower($texto);

        $reemplazos = array(
            'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','ã'=>'a',
            'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
            'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
            'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','õ'=>'o',
            'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
            'ñ'=>'n','ç'=>'c',
        );
        $texto = strtr($texto, $reemplazos);
        $texto = preg_replace('/[^a-z0-9\s]+/u', ' ', $texto);
        $texto = preg_replace('/\s+/u', ' ', $texto);
        return trim($texto);
    }

    protected function _normalizar_compacto($texto_norm)
    {
        return str_replace(' ', '', (string)$texto_norm);
    }

    public function clasificar($texto_norm)
    {
        $this->cargar_datos();

        $vacio = array(
            'puntajes'      => array(),
            'principal_id'  => NULL,
            'principal_pts' => 0,
            'etiquetas'     => array(),
        );

        if ($texto_norm === '') {
            return $vacio;
        }

        $detalle = array();
        foreach ($this->palabras as $cat_id => $lista) {
            $score    = 0;
            $peso_max = 0;
            foreach ($lista as $p) {
                if ($this->_coincide($texto_norm, $p['palabra'])) {
                    $score += (int) $p['peso'];
                    if ((int)$p['peso'] > $peso_max) {
                        $peso_max = (int)$p['peso'];
                    }
                }
            }
            if ($score > 0) {
                $detalle[(int)$cat_id] = array(
                    'score'    => $score,
                    'peso_max' => $peso_max,
                    'cat_id'   => (int)$cat_id,
                );
            }
        }

        if (empty($detalle)) {
            return $vacio;
        }

        uasort($detalle, array($this, '_cmp_detalle'));

        $ids = array_keys($detalle);
        $principal_id  = (int) $ids[0];
        $principal_pts = (int) $detalle[$principal_id]['score'];

        $etiquetas = array();
        for ($i = 1; $i < count($ids); $i++) {
            $cid = (int)$ids[$i];
            $etiquetas[] = array(
                'categoria_id' => $cid,
                'puntaje'      => (int) $detalle[$cid]['score'],
            );
        }

        return array(
            'puntajes'      => $detalle,
            'principal_id'  => $principal_id,
            'principal_pts' => $principal_pts,
            'etiquetas'     => $etiquetas,
        );
    }

    protected function _cmp_detalle($a, $b)
    {
        if ($a['score']    !== $b['score'])    return $b['score']    - $a['score'];
        if ($a['peso_max'] !== $b['peso_max']) return $b['peso_max'] - $a['peso_max'];
        return $a['cat_id'] - $b['cat_id'];
    }

    public function detectar_solicitud_recurso($texto_norm)
    {
        if ($texto_norm === '') return FALSE;
        foreach ($this->gatillos_recurso as $g) {
            if ($this->_coincide($texto_norm, $g)) {
                return TRUE;
            }
        }
        return FALSE;
    }

    public function detectar_comprobante($texto_norm)
    {
        if ($texto_norm === '') return FALSE;

        foreach ($this->frases_comprobante as $f) {
            if ($this->_coincide($texto_norm, $f)) {
                return TRUE;
            }
        }
        if (preg_match('/\b[bf]\d{3}[-\s]?\d{3,}\b/u', $texto_norm)) {
            return TRUE;
        }
        return FALSE;
    }

    /**
     * Detecta si el mensaje tiene palabras de urgencia (mejora #7).
     */
    public function detectar_urgencia($texto_norm)
    {
        if ($texto_norm === '') return FALSE;
        foreach ($this->palabras_urgencia as $p) {
            if ($this->_coincide($texto_norm, $p)) {
                return TRUE;
            }
        }
        return FALSE;
    }

    /**
     * Detecta la ficha automática del caso (mejora #4).
     * Devuelve array con las claves que haya encontrado, o array vacío.
     *   ['dni', 'ruc', 'comprobante', 'windows', 'urgente']
     */
    public function detectar_ficha($texto_norm, $texto_original = '')
    {
        $ficha = array();

        // DNI: 8 dígitos exactos, no parte de un número mayor
        if (preg_match('/(?<![0-9])(\d{8})(?![0-9])/u', $texto_norm, $m)) {
            $ficha['dni'] = $m[1];
        }

        // RUC: 11 dígitos exactos
        if (preg_match('/(?<![0-9])(\d{11})(?![0-9])/u', $texto_norm, $m)) {
            $ficha['ruc'] = $m[1];
        }

        // Comprobante: B001-123 / F001-123 / B001123 / F001123
        if (preg_match('/\b([bf]\d{3}[-\s]?\d{3,})\b/u', $texto_norm, $m)) {
            $ficha['comprobante'] = strtoupper(str_replace(' ', '', $m[1]));
        }

        // Windows 10 / Windows 11 / Win 10 / win11
        if (preg_match('/\b(?:windows|win)\s*(10|11)\b/u', $texto_norm, $m)) {
            $ficha['windows'] = 'Windows ' . $m[1];
        } elseif (preg_match('/\bwindows\b/u', $texto_norm)) {
            $ficha['windows'] = 'Windows (versión no indicada)';
        }

        // Urgencia
        if ($this->detectar_urgencia($texto_norm)) {
            $ficha['urgente'] = TRUE;
        }

        return $ficha;
    }

    public function detectar_equipo($texto_norm)
    {
        if ($texto_norm === '') return NULL;
        $this->_cargar_recursos();

        $texto_compacto = $this->_normalizar_compacto($texto_norm);
        $mejor = NULL;
        $mejor_pts = 0;

        foreach ($this->recursos as $r) {
            $score = 0;
            $palabras = $this->_parsear_palabras_clave($r['palabras_clave']);

            foreach ($palabras as $p) {
                if ($this->_coincide($texto_norm, $p)) {
                    $score += 10;
                }
                $p_compacto = str_replace(' ', '', $p);
                $len_p = function_exists('mb_strlen') ? mb_strlen($p_compacto, 'UTF-8') : strlen($p_compacto);
                if ($len_p >= 3 && strpos($texto_compacto, $p_compacto) !== FALSE) {
                    $score += 8;
                }
            }

            $modelo_norm = $this->normalizar($r['modelo']);
            if ($modelo_norm !== '' && $this->_coincide($texto_norm, $modelo_norm)) {
                $score += 20;
            }

            if ($score > $mejor_pts) {
                $mejor_pts = $score;
                $mejor = $r;
            }
        }

        if ($mejor === NULL || $mejor_pts === 0) return NULL;

        return array(
            'marca'  => $mejor['marca'],
            'modelo' => $mejor['modelo'],
            'score'  => $mejor_pts,
        );
    }

    public function esta_en_horario()
    {
        $this->_cargar_cfg();

        $dia_semana = (int) date('N');
        $hm         = date('H:i');

        if ($dia_semana === 7) {
            return !$this->_cfg_bool('horario_do_cerrado', TRUE);
        }
        if ($dia_semana === 6) {
            $ini = $this->_cfg('horario_sa_inicio', '10:00');
            $fin = $this->_cfg('horario_sa_fin',    '17:00');
            return ($hm >= $ini && $hm <= $fin);
        }
        $ini = $this->_cfg('horario_lv_inicio', '10:00');
        $fin = $this->_cfg('horario_lv_fin',    '17:00');
        return ($hm >= $ini && $hm <= $fin);
    }

    public function texto_horario()
    {
        $this->_cargar_cfg();

        $lv_ini = $this->_cfg('horario_lv_inicio', '10:00');
        $lv_fin = $this->_cfg('horario_lv_fin',    '17:00');
        $sa_ini = $this->_cfg('horario_sa_inicio', '10:00');
        $sa_fin = $this->_cfg('horario_sa_fin',    '17:00');
        $do_cerrado = $this->_cfg_bool('horario_do_cerrado', TRUE);

        $lv_ini_f = $this->_formatear_hora_12h($lv_ini);
        $lv_fin_f = $this->_formatear_hora_12h($lv_fin);
        $sa_ini_f = $this->_formatear_hora_12h($sa_ini);
        $sa_fin_f = $this->_formatear_hora_12h($sa_fin);

        if ($lv_ini === $sa_ini && $lv_fin === $sa_fin) {
            $txt = 'lunes a sábado, de ' . $lv_ini_f . ' a ' . $lv_fin_f;
        } else {
            $txt = 'lunes a viernes, de ' . $lv_ini_f . ' a ' . $lv_fin_f .
                   '; sábados, de ' . $sa_ini_f . ' a ' . $sa_fin_f;
        }
        if ($do_cerrado) {
            $txt .= '. Domingos cerrado';
        }
        return $txt;
    }

    public function aplicar_variables($texto)
    {
        if ($texto === NULL || $texto === '') return '';
        $this->_cargar_cfg();

        $empresa = $this->_cfg('empresa_comercial', NULL);
        if ($empresa === NULL || $empresa === '') {
            $empresa = $this->_cfg('empresa_nombre', 'GSP IMPORT');
        }
        $maps = $this->_cfg('link_maps', '');

        $mapa = array(
            '{empresa}' => (string)$empresa,
            '{horario}' => $this->texto_horario(),
            '{maps}'    => (string)$maps,
        );
        return strtr($texto, $mapa);
    }

    /* =========================================================
     *  CONSTRUCCIÓN DE LA RESPUESTA COMBINADA
     * =======================================================*/

    protected function _construir_respuesta(
        array $conv,
        $es_primer,
        $tiene_texto,
        $es_imagen,
        $es_comprobante,
        $es_numerico,
        $solicita_recurso,
        $cat_id
    ) {
        $conv_id     = (int) $conv['id'];
        $min_rep     = $this->_cfg_int('bot_repetir_min', 10);
        $en_horario  = $this->esta_en_horario();
        $log         = $this->CI->crm_bot_log_model;

        $partes          = array();
        $tipos_log       = array();
        $tipo_principal  = NULL;
        $cat_principal   = NULL;
        $marcar_pendiente = FALSE;

        $sin_clasificar = (int) $this->CI->crm_categoria_model->obtener_id_sin_clasificar();

        /* --- 1) Texto principal --- */
        if ($es_comprobante) {
            if (!$log->ya_respondido_recientemente($conv_id, 'comprobante', $min_rep)) {
                $partes[] = 'Recibimos tu comprobante. Un asesor lo verificará en nuestro horario de atención.';
                $tipos_log[] = array('tipo' => 'comprobante', 'categoria_id' => NULL);
                $tipo_principal = 'comprobante';
                $marcar_pendiente = TRUE;
            }
        } else if ($es_numerico) {
            if (!$log->ya_respondido_recientemente($conv_id, 'acuse', $min_rep)) {
                $partes[] = 'Gracias por tu respuesta. Ya la registramos y un asesor continuará con tu caso.';
                $tipos_log[] = array('tipo' => 'acuse', 'categoria_id' => NULL);
                $tipo_principal = 'acuse';
                $marcar_pendiente = TRUE;
            }
        } else if ($solicita_recurso && $conv['verificacion_estado'] !== 'verificado') {
            if (!$log->ya_respondido_recientemente($conv_id, 'solicitud_recurso', $min_rep)) {
                $partes[] = (string)$this->_cfg(
                    'texto_solicitud_recursos',
                    'Con gusto te compartimos el driver o video tutorial de tu equipo. Para entregarte el material necesitamos verificar tu compra: 1) Foto o número de tu boleta o factura. 2) Marca y modelo del equipo. Un asesor lo validará y te enviará el material en nuestro horario de atención ({horario}).'
                );
                $tipos_log[] = array('tipo' => 'solicitud_recurso', 'categoria_id' => NULL);
                $tipo_principal = 'solicitud_recurso';
                $marcar_pendiente = TRUE;
            }
        } else if ($es_imagen && !$tiene_texto) {
            if (!$log->ya_respondido_recientemente($conv_id, 'imagen_sin_texto', $min_rep)) {
                if ($this->_cliente_escribio_antes_reciente($conv_id, 10)) {
                    $partes[] = 'Recibimos tu imagen. Si es tu comprobante de pago, un asesor lo verificará.';
                } else {
                    $partes[] = (string)$this->_cfg(
                        'texto_imagen_sin_texto',
                        'Recibimos tu imagen. Cuéntanos brevemente qué problema presenta el equipo o qué necesitas.'
                    );
                }
                $tipos_log[] = array('tipo' => 'imagen_sin_texto', 'categoria_id' => NULL);
                $tipo_principal = 'imagen_sin_texto';
            }
        } else if ($tiene_texto && $cat_id && (int)$cat_id !== $sin_clasificar && isset($this->respuestas[$cat_id])) {
            if (!$log->ya_respondido_recientemente($conv_id, 'categoria', $min_rep, (int)$cat_id)) {
                $partes[] = (string)$this->respuestas[$cat_id];
                $tipos_log[] = array('tipo' => 'categoria', 'categoria_id' => (int)$cat_id);
                $tipo_principal = 'categoria';
                $cat_principal = (int)$cat_id;
            }
        } else if ($tiene_texto) {
            if (!$log->ya_respondido_recientemente($conv_id, 'sin_clasificar', $min_rep)) {
                $partes[] = (string)$this->_cfg(
                    'texto_sin_clasificar',
                    'Gracias por tu mensaje. Un asesor te responderá en breve.'
                );
                $tipos_log[] = array('tipo' => 'sin_clasificar', 'categoria_id' => NULL);
                $tipo_principal = 'sin_clasificar';
                $marcar_pendiente = TRUE;
            }
        }

        /* --- 2) Saludo (solo si es el primer mensaje del cliente) --- */
        $incluir_saludo = FALSE;
        if ($es_primer) {
            if (!$log->ya_respondido_recientemente($conv_id, 'saludo', 60)) {
                $incluir_saludo = TRUE;
            }
        }

        /* --- 3) Fuera de horario --- */
        $incluir_fuera = FALSE;
        if (!$en_horario && ($tiene_texto || $es_imagen)) {
            if (!$log->ya_respondido_recientemente($conv_id, 'fuera_horario', $min_rep)) {
                $incluir_fuera = TRUE;
            }
        }

        /* --- 3.b) Urgencia (mejora #7) --- */
        $incluir_urgencia = FALSE;
        if (!empty($conv['_urgente_msg'])) {
            if (!$log->ya_respondido_recientemente($conv_id, 'urgencia', 60)) {
                $incluir_urgencia = TRUE;
            }
        }

        /* --- 4) Si nada nuevo, no respondemos --- */
        if (empty($partes) && !$incluir_saludo && !$incluir_fuera && !$incluir_urgencia) {
            return NULL;
        }

        /* --- 5) Combinar: saludo + categoría + urgencia + fuera_horario --- */
        $partes_finales = array();
        if ($incluir_saludo) {
            $partes_finales[] = (string)$this->_cfg(
                'texto_saludo_inicial',
                '¡Hola! Bienvenido(a) a {empresa}. Cuéntanos brevemente qué necesitas. Nuestro horario es {horario}.'
            );
            $tipos_log[] = array('tipo' => 'saludo', 'categoria_id' => NULL);
        }
        foreach ($partes as $p) {
            $partes_finales[] = $p;
        }
        if ($incluir_urgencia) {
            $partes_finales[] = (string)$this->_cfg(
                'texto_urgencia_respuesta',
                'Entendemos la urgencia. Tu caso fue marcado como prioritario y un asesor lo atenderá lo antes posible.'
            );
            $tipos_log[] = array('tipo' => 'urgencia', 'categoria_id' => NULL);
        }
        if ($incluir_fuera) {
            $partes_finales[] = (string)$this->_cfg(
                'texto_fuera_horario',
                'En este momento estamos fuera de horario. Tu mensaje quedó registrado y te atenderemos en el siguiente horario hábil.'
            );
            $tipos_log[] = array('tipo' => 'fuera_horario', 'categoria_id' => NULL);
        }

        $texto_final = '';
        foreach ($partes_finales as $i => $p) {
            if ($i > 0) $texto_final .= "\n\n";
            $texto_final .= $this->aplicar_variables($p);
        }

        if ($tipo_principal === NULL) {
            if ($incluir_saludo)         $tipo_principal = 'saludo';
            else if ($incluir_urgencia)  $tipo_principal = 'urgencia';
            else if ($incluir_fuera)     $tipo_principal = 'fuera_horario';
        }

        return array(
            'texto'            => $texto_final,
            'tipo'             => $tipo_principal ?: 'acuse',
            'categoria_id'     => $cat_principal,
            'tipos_log'        => $tipos_log,
            'marcar_pendiente' => $marcar_pendiente,
        );
    }

    /* =========================================================
     *  CARGA DE DATOS
     * =======================================================*/

    protected function cargar_datos()
    {
        if ($this->palabras === NULL) {
            $this->palabras = $this->CI->crm_palabra_model->listar_agrupadas();
        }
        if ($this->respuestas === NULL) {
            $this->respuestas = $this->CI->crm_respuesta_model->mapa_activas();
        }
    }

    protected function _cargar_recursos()
    {
        if ($this->recursos === NULL) {
            $this->recursos = $this->CI->crm_recurso_model->listar_activos();
        }
    }

    /* =========================================================
     *  INTERNOS
     * =======================================================*/

    protected function _coincide($texto_norm, $palabra_norm)
    {
        if ($palabra_norm === '' || $palabra_norm === NULL) {
            return FALSE;
        }

        $patron = '/(?<![a-z0-9])' . preg_quote($palabra_norm, '/') . '(?![a-z0-9])/u';
        if (preg_match($patron, $texto_norm)) {
            return TRUE;
        }

        if (strpos($palabra_norm, ' ') !== FALSE) {
            return FALSE;
        }

        $raiz_clave = $this->_raiz_plural($palabra_norm);
        if ($raiz_clave === '' || $raiz_clave === $palabra_norm) {
            return FALSE;
        }

        $palabras_texto = preg_split('/\s+/u', $texto_norm);
        foreach ($palabras_texto as $w) {
            if ($w === '') continue;
            if ($this->_raiz_plural($w) === $raiz_clave) {
                return TRUE;
            }
        }
        return FALSE;
    }

    protected function _raiz_plural($w)
    {
        $len = function_exists('mb_strlen') ? mb_strlen($w, 'UTF-8') : strlen($w);
        if ($len <= 4) return $w;

        $tiene_mb = function_exists('mb_substr');

        if ($len > 5) {
            $colita = $tiene_mb ? mb_substr($w, -2, NULL, 'UTF-8') : substr($w, -2);
            if ($colita === 'es') {
                return $tiene_mb ? mb_substr($w, 0, -2, 'UTF-8') : substr($w, 0, -2);
            }
        }

        $ultimo = $tiene_mb ? mb_substr($w, -1, NULL, 'UTF-8') : substr($w, -1);
        if ($ultimo === 's') {
            return $tiene_mb ? mb_substr($w, 0, -1, 'UTF-8') : substr($w, 0, -1);
        }
        return $w;
    }

    protected function _cliente_escribio_antes_reciente($conv_id, $minutos)
    {
        $desde = date('Y-m-d H:i:s', time() - ((int)$minutos * 60));
        $n = (int) $this->CI->db
            ->where('conversacion_id', (int)$conv_id)
            ->where('emisor', 'cliente')
            ->where('creado_at >=', $desde)
            ->count_all_results('crm_mensajes');
        return $n >= 2;
    }

    protected function _es_respuesta_numerica($texto_norm)
    {
        $t = trim($texto_norm);
        if ($t === '') return FALSE;
        if (preg_match('/^\(?\d{1,2}\)?[\.\)]?$/', $t)) return TRUE;
        if (preg_match('/^opcion\s+\d{1,2}$/', $t)) return TRUE;
        return FALSE;
    }

    protected function _formatear_hora_12h($hhmm)
    {
        $partes = explode(':', (string)$hhmm);
        if (count($partes) < 2) return (string)$hhmm;
        $h = (int) $partes[0];
        $m = $partes[1];
        $sufijo = ($h < 12) ? 'a. m.' : 'p. m.';
        $h12 = $h % 12;
        if ($h12 === 0) $h12 = 12;
        return $h12 . ':' . $m . ' ' . $sufijo;
    }

    protected function _parsear_palabras_clave($csv)
    {
        if ($csv === NULL || $csv === '') return array();
        $partes = explode(',', (string)$csv);
        $out = array();
        foreach ($partes as $p) {
            $p = $this->normalizar($p);
            if ($p !== '') $out[] = $p;
        }
        return $out;
    }

    /* =========================================================
     *  HELPERS DE CONFIGURACIÓN
     * =======================================================*/

    protected function _cargar_cfg()
    {
        if (!empty($this->cfg)) return;
        $this->cfg = $this->CI->crm_config_model->obtener_todas();
        if (!is_array($this->cfg)) $this->cfg = array();

        // Cargar palabras de urgencia desde la config (una sola vez)
        $csv = $this->_cfg('palabras_urgencia', '');
        if ($csv !== '' && $csv !== NULL) {
            $partes = array_map('trim', explode(',', $csv));
            $limpias = array();
            foreach ($partes as $p) {
                $p = $this->normalizar($p);
                if ($p !== '') $limpias[] = $p;
            }
            if (!empty($limpias)) {
                $this->palabras_urgencia = $limpias;
            }
        }
    }

    protected function _cfg($clave, $default = NULL)
    {
        return array_key_exists($clave, $this->cfg) ? $this->cfg[$clave] : $default;
    }

    protected function _cfg_int($clave, $default = 0)
    {
        return (int) $this->_cfg($clave, $default);
    }

    protected function _cfg_bool($clave, $default = FALSE)
    {
        $v = $this->_cfg($clave, $default ? '1' : '0');
        return ($v === '1' || $v === 1 || $v === TRUE || $v === 'true');
    }
}