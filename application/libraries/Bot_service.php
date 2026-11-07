<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Bot_service (v2.2)
 * ------------------
 * Chatbot gratuito basado SOLO en palabras clave y respuestas configurables.
 *
 * Cambios v2.1 (Bloque 1 de mejoras):
 *  - Mejora #4: detección de ficha automática (DNI, RUC, comprobante, Windows).
 *  - Mejora #7: detección de urgencia y respuesta específica.
 *  - Propiedad $palabras_urgencia que se sobreescribe desde crm_configuracion.
 *
 * Cambios v2.2 (revisión de errores del chat automático):
 *  - Anti-repetición: ya no deja al cliente en silencio (acuse breve una vez).
 *  - No se reclasifica con mensajes sin categoría ("okey") ni en modo humano.
 *  - Las palabras de urgencia no puntúan como categoría de Soporte.
 *  - Equipo: ignora recursos GENERAL (Windows...), reconoce "xprinter 365",
 *    "cbxpos 89" y no usa palabras genéricas ("instalar", "lector"...).
 *  - Cliente verificado: no se le vuelve a pedir el comprobante.
 *  - Saludo + respuesta ya no duplican horario ni pedidos.
 *  - "p. m.." corregido; saltos de línea unificados.
 *  - Comprobante: ya no se confunde "adjunto" o "no tengo mi boleta".
 *  - Ficha: DNI con contexto, RUC con prefijo válido, comprobante B001-123.
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
        'adjunto mi comprobante','adjunto comprobante',
        'adjunto mi boleta','adjunto mi factura',
        'esta es mi boleta','esta es mi factura','mi voucher',
        'te mando mi boleta','te mando mi factura',
        'le mando mi boleta','le mando mi factura',
    );

    /** Términos genéricos de recursos: son temas, NO identifican un modelo */
    protected $palabras_no_equipo = array(
        'instalar','instalacion','windows','lector','lectores','gaveta','touch',
        'kit','lento','cable','cables','pantalla','pantallas','bluetooth','bt','usb',
        'android','celular','etiqueta','etiquetas','rollo','apertura','calibracion',
        'calibrar','alto rendimiento','efectos visuales','mini cpu','minicpu',
        'pantalla tactil','pantalla touch','portatil','barcode','all in one',
        'colocar etiqueta','disenar etiquetas',
    );

    /** Marcas equivalentes al buscar modelos (Redpos rebrandea Xprinter) */
    protected $alias_marca = array(
        'redpos'   => array('xprinter', 'xp'),
        'xprinter' => array('redpos', 'xp'),
    );

    /** Marcas del catálogo que representan un tema, no un equipo del cliente */
    protected $marcas_genericas = array('GENERAL');

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
        $equipo = NULL;
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
            // Una imagen cuenta como comprobante solo si el cliente aún no fue verificado.
            // (Antes, con solicita_recurso=1 permanente, TODA imagen se tomaba como comprobante.)
            $ya_verificado = (isset($conversacion['verificacion_estado'])
                              && $conversacion['verificacion_estado'] === 'verificado');
            if (!$ya_verificado) {
                if (!empty($conversacion['solicita_recurso'])) {
                    $es_comprobante = TRUE;
                } else if ($this->CI->crm_bot_log_model
                        ->ya_respondido_recientemente((int)$conversacion['id'], 'solicitud_recurso', 120)) {
                    $es_comprobante = TRUE;
                }
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
                // Solo recategorizamos si el mensaje SE PUDO clasificar. Un "okey" o un
                // "gracias" ya no borra la categoría. En modo humano tampoco pisamos
                // una categoría existente (la decide el asesor).
                $puede_cambiar = ($clasif['principal_id'] !== NULL)
                    && ($modo === 'bot' || empty($conversacion['categoria_id']));
                $accion['recategorizar'] = $puede_cambiar;
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
        $conversacion['_equipo_msg']  = ($equipo !== NULL)
            ? trim($equipo['marca'] . ' ' . $equipo['modelo']) : '';

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
            // Nada nuevo que decir: queda marcada para que un asesor la vea.
            $accion['no_responder']     = TRUE;
            $accion['marcar_pendiente'] = TRUE;
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
        $this->_cargar_cfg();

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
                // La urgencia tiene su propio flujo; no debe puntuar como categoría.
                if (in_array($this->normalizar($p['palabra']), $this->palabras_urgencia, TRUE)) {
                    continue;
                }
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

        // "no tengo mi boleta", "perdí mi comprobante": NO es un comprobante enviado
        $hay_negacion = (bool) preg_match(
            '/\b(no|sin|perdi|olvide|extravie)\b[^.]{0,30}\b(comprobante|boleta|factura|voucher)\b/u',
            $texto_norm
        );
        if (!$hay_negacion) {
            foreach ($this->frases_comprobante as $f) {
                if ($this->_coincide($texto_norm, $f)) {
                    return TRUE;
                }
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

        // DNI: 8 dígitos exactos. Solo si el mensaje dice "dni/documento" o es solo el
        // número (así no confundimos nº de pedido, teléfonos, etc. con un DNI).
        if (preg_match('/(?<![0-9])(\d{8})(?![0-9])/u', $texto_norm, $m)) {
            $solo_numero  = (bool) preg_match('/^\d{8}$/', trim($texto_norm));
            $con_contexto = (bool) preg_match('/\b(dni|documento|doc)\b/u', $texto_norm);
            if ($solo_numero || $con_contexto) {
                $ficha['dni'] = $m[1];
            }
        }

        // RUC: 11 dígitos exactos y prefijo válido en Perú (10, 15, 16, 17 o 20)
        if (preg_match('/(?<![0-9])((?:10|15|16|17|20)\d{9})(?![0-9])/u', $texto_norm, $m)) {
            $ficha['ruc'] = $m[1];
        }

        // Comprobante: B001-123 / F001-123 / B001123 → se guarda siempre como B001-123
        if (preg_match('/\b([bf]\d{3})[-\s]?(\d{3,})\b/u', $texto_norm, $m)) {
            $ficha['comprobante'] = strtoupper($m[1]) . '-' . $m[2];
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
            // Recursos de marca GENERAL (Windows, lectores...) son temas, no un equipo.
            if (in_array(strtoupper(trim((string)$r['marca'])), $this->marcas_genericas, TRUE)) {
                continue;
            }

            $score = 0;
            $palabras = $this->_parsear_palabras_clave($r['palabras_clave']);

            foreach ($palabras as $p) {
                // Términos genéricos ("instalar", "lector"...) no identifican un modelo.
                if (in_array($p, $this->palabras_no_equipo, TRUE)) {
                    continue;
                }
                if ($this->_coincide($texto_norm, $p)) {
                    $score += 10;
                }
                // Coincidencia compacta ("pos 89e" = "pos89e"): solo para términos con
                // número (modelos). Sin esto, "imin" coincidía dentro de "mi mini cpu".
                if (preg_match('/\d/', $p)) {
                    $p_compacto = str_replace(' ', '', $p);
                    $len_p = function_exists('mb_strlen') ? mb_strlen($p_compacto, 'UTF-8') : strlen($p_compacto);
                    if ($len_p >= 3 && strpos($texto_compacto, $p_compacto) !== FALSE) {
                        $score += 8;
                    }
                }
            }

            $modelo_norm = $this->normalizar($r['modelo']);
            if ($modelo_norm !== '' && $this->_coincide($texto_norm, $modelo_norm)) {
                $score += 20;
            }

            // "xprinter 365", "cbxpos 89", "xp 410": modelo escrito sin guion ni sufijo
            if ($score === 0 && $this->_coincide_modelo_parcial($texto_norm, $r)) {
                $score += 15;
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

    /**
     * Coincidencia parcial de modelo: marca (o alias) + [prefijo] + número [+ sufijo],
     * o prefijo + número. Ej.: modelo "XP-365B" ← "xprinter 365", "xp 365";
     * modelo "POS89E" ← "cbxpos 89", "pos 89".
     */
    protected function _coincide_modelo_parcial($texto_norm, array $r)
    {
        $marca = str_replace(' ', '', $this->normalizar($r['marca']));
        if ($marca === '' || $marca === 'otros') return FALSE;

        $compacto = str_replace(' ', '', $this->normalizar($r['modelo']));
        if (!preg_match('/^([a-z]{0,3})(\d{2,4})([a-z]{0,2})$/', $compacto, $m)) {
            return FALSE;
        }
        $prefijo = $m[1];
        $num     = $m[2];
        $sufijo  = $m[3];

        // Con pocos dígitos (ej. "89") exigimos el prefijo para evitar falsos positivos.
        if (strlen($num) < 3 && $prefijo === '') return FALSE;
        $prefijo_obligatorio = (strlen($num) < 3);

        $marcas = array(preg_quote($marca, '/'));
        if (isset($this->alias_marca[$marca])) {
            foreach ($this->alias_marca[$marca] as $a) {
                $marcas[] = preg_quote($a, '/');
            }
        }
        $alt = implode('|', $marcas);

        $p_suf = ($sufijo !== '') ? '(?:' . preg_quote($sufijo, '/') . ')?' : '';
        if ($prefijo === '') {
            $p_pref = '';
        } else if ($prefijo_obligatorio) {
            $p_pref = preg_quote($prefijo, '/') . '\s*';
        } else {
            $p_pref = '(?:' . preg_quote($prefijo, '/') . '\s*)?';
        }

        // A) marca + [prefijo] + número
        $patron_a = '/(?<![a-z0-9])(?:' . $alt . ')\s*' . $p_pref . $num . $p_suf . '(?![a-z0-9])/u';
        if (preg_match($patron_a, $texto_norm)) return TRUE;

        // B) prefijo + número ("pos 89", "xp 365")
        if ($prefijo !== '') {
            $patron_b = '/(?<![a-z0-9])' . preg_quote($prefijo, '/') . '\s*' . $num . $p_suf . '(?![a-z0-9])/u';
            if (preg_match($patron_b, $texto_norm)) return TRUE;
        }
        return FALSE;
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
            // Con ";" evitamos el doble punto ("p. m.. Domingos") al unir las frases.
            $txt .= '; domingos cerrado';
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
        $out = strtr($texto, $mapa);

        // Un mismo estilo de salto de línea (la config trae \r\n y los textos fijos \n)
        $out = str_replace(array("\r\n", "\r"), "\n", $out);

        // "p. m.." → "p. m." (la plantilla agrega un punto tras {horario})
        $out = preg_replace('/([ap]\. m\.)\.(?!\.)/u', '$1', $out);

        return $out;
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
        $verificado  = (isset($conv['verificacion_estado']) && $conv['verificacion_estado'] === 'verificado');
        $equipo_txt  = isset($conv['_equipo_msg']) ? (string) $conv['_equipo_msg'] : '';

        $partes          = array();
        $tipos_log       = array();
        $tipo_principal  = NULL;
        $cat_principal   = NULL;
        $marcar_pendiente = FALSE;
        $repetido        = FALSE;   // había algo que decir, pero ya se dijo hace poco

        $sin_clasificar = (int) $this->CI->crm_categoria_model->obtener_id_sin_clasificar();

        /* --- 0) ¿Corresponde saludo? (solo el primer mensaje del cliente) --- */
        $incluir_saludo = FALSE;
        if ($es_primer && !$log->ya_respondido_recientemente($conv_id, 'saludo', 60)) {
            $incluir_saludo = TRUE;
        }

        /* --- 1) Texto principal --- */
        if ($es_comprobante) {
            if (!$log->ya_respondido_recientemente($conv_id, 'comprobante', $min_rep)) {
                $partes[] = (string)$this->_cfg(
                    'texto_comprobante_recibido',
                    'Recibimos tu comprobante. Un asesor lo verificará en nuestro horario de atención.'
                );
                $tipos_log[] = array('tipo' => 'comprobante', 'categoria_id' => NULL);
                $tipo_principal = 'comprobante';
                $marcar_pendiente = TRUE;
            } else {
                $repetido = TRUE;
            }
        } else if ($es_numerico) {
            if (!$log->ya_respondido_recientemente($conv_id, 'acuse', $min_rep)) {
                $partes[] = (string)$this->_cfg(
                    'texto_acuse_numerico',
                    'Gracias por tu respuesta. Ya la registramos y un asesor continuará con tu caso.'
                );
                $tipos_log[] = array('tipo' => 'acuse', 'categoria_id' => NULL);
                $tipo_principal = 'acuse';
                $marcar_pendiente = TRUE;
            } else {
                $repetido = TRUE;
            }
        } else if ($solicita_recurso && $verificado) {
            // Cliente ya verificado: no se le vuelve a pedir el comprobante.
            if (!$log->ya_respondido_recientemente($conv_id, 'recurso_verificado', $min_rep)) {
                $partes[] = (string)$this->_cfg(
                    'texto_recurso_verificado',
                    'Ya verificamos tu comprobante. Un asesor te enviará el material por este chat en nuestro horario de atención ({horario}).'
                );
                $tipos_log[] = array('tipo' => 'recurso_verificado', 'categoria_id' => NULL);
                $tipo_principal = 'recurso_verificado';
                $marcar_pendiente = TRUE;
            } else {
                $repetido = TRUE;
            }
        } else if ($solicita_recurso) {
            if (!$log->ya_respondido_recientemente($conv_id, 'solicitud_recurso', $min_rep)) {
                $partes[] = (string)$this->_cfg(
                    'texto_solicitud_recursos',
                    'Con gusto te compartimos el driver o video tutorial de tu equipo. Para entregarte el material necesitamos verificar tu compra: 1) Foto o número de tu boleta o factura. 2) Marca y modelo del equipo. Un asesor lo validará y te enviará el material en nuestro horario de atención ({horario}).'
                );
                $tipos_log[] = array('tipo' => 'solicitud_recurso', 'categoria_id' => NULL);
                $tipo_principal = 'solicitud_recurso';
                $marcar_pendiente = TRUE;
            } else {
                $repetido = TRUE;
            }
        } else if ($es_imagen && !$tiene_texto) {
            if (!$log->ya_respondido_recientemente($conv_id, 'imagen_sin_texto', $min_rep)) {
                if ($this->_cliente_escribio_antes_reciente($conv_id, 10)) {
                    $partes[] = (string)$this->_cfg(
                        'texto_imagen_ctx',
                        'Recibimos tu imagen. Si es tu comprobante de pago, un asesor lo verificará.'
                    );
                } else {
                    $partes[] = (string)$this->_cfg(
                        'texto_imagen_sin_texto',
                        'Recibimos tu imagen. Cuéntanos brevemente qué problema presenta el equipo o qué necesitas.'
                    );
                }
                $tipos_log[] = array('tipo' => 'imagen_sin_texto', 'categoria_id' => NULL);
                $tipo_principal = 'imagen_sin_texto';
            } else {
                $repetido = TRUE;
            }
        } else if ($tiene_texto && $equipo_txt !== ''
                   && (!$cat_id || (int)$cat_id === 4 || (int)$cat_id === $sin_clasificar)) {
            // El cliente solo indicó su equipo ("XPRINTER 365"): lo registramos y lo decimos,
            // en vez de volver a preguntar "¿de qué trata tu consulta?".
            if (!$log->ya_respondido_recientemente($conv_id, 'equipo', $min_rep)) {
                $t = (string)$this->_cfg(
                    'texto_equipo_registrado',
                    'Registramos tu equipo: {equipo}. Un asesor revisará tu caso y te responderá en nuestro horario de atención ({horario}).'
                );
                $partes[] = str_replace('{equipo}', $equipo_txt, $t);
                $tipos_log[] = array('tipo' => 'equipo', 'categoria_id' => NULL);
                $tipo_principal = 'equipo';
                $marcar_pendiente = TRUE;
            } else {
                $repetido = TRUE;
            }
        } else if ($tiene_texto && $cat_id && (int)$cat_id !== $sin_clasificar && isset($this->respuestas[$cat_id])) {
            if (!$log->ya_respondido_recientemente($conv_id, 'categoria', $min_rep, (int)$cat_id)) {
                $partes[] = $this->_texto_categoria((int)$cat_id, $verificado);
                $tipos_log[] = array('tipo' => 'categoria', 'categoria_id' => (int)$cat_id);
                $tipo_principal = 'categoria';
                $cat_principal = (int)$cat_id;
            } else {
                $repetido = TRUE;
            }
        } else if ($tiene_texto) {
            if ($incluir_saludo) {
                // El saludo ya pide que cuente su consulta: no repetimos "no te entendí".
                $marcar_pendiente = TRUE;
            } else if (!$log->ya_respondido_recientemente($conv_id, 'sin_clasificar', $min_rep)) {
                $partes[] = (string)$this->_cfg(
                    'texto_sin_clasificar',
                    'Gracias por tu mensaje. Un asesor te responderá en breve.'
                );
                $tipos_log[] = array('tipo' => 'sin_clasificar', 'categoria_id' => NULL);
                $tipo_principal = 'sin_clasificar';
                $marcar_pendiente = TRUE;
            } else {
                $repetido = TRUE;
            }
        }

        /* --- 2) Fuera de horario --- */
        $incluir_fuera = FALSE;
        if (!$en_horario && ($tiene_texto || $es_imagen)) {
            if (!$log->ya_respondido_recientemente($conv_id, 'fuera_horario', $min_rep)) {
                $incluir_fuera = TRUE;
            }
        }

        /* --- 2.b) Urgencia (mejora #7) --- */
        $incluir_urgencia = FALSE;
        if (!empty($conv['_urgente_msg'])) {
            if (!$log->ya_respondido_recientemente($conv_id, 'urgencia', 60)) {
                $incluir_urgencia = TRUE;
            }
        }

        /* --- 3) Nada nuevo: un acuse breve (una vez) en vez de dejar al cliente en silencio --- */
        if (empty($partes) && !$incluir_saludo && !$incluir_fuera && !$incluir_urgencia) {
            if ($repetido && !$log->ya_respondido_recientemente($conv_id, 'repeticion', $min_rep)) {
                $partes[] = (string)$this->_cfg(
                    'texto_acuse_breve',
                    'Gracias, ya registramos tu mensaje. Un asesor revisará tu caso y te responderá en nuestro horario de atención.'
                );
                $tipos_log[] = array('tipo' => 'repeticion', 'categoria_id' => NULL);
                $tipo_principal = 'repeticion';
                $marcar_pendiente = TRUE;
            } else {
                return NULL;
            }
        }

        /* --- 4) Combinar: saludo + categoría + urgencia + fuera_horario --- */
        $partes_finales = array();
        if ($incluir_saludo) {
            $saludo = (string)$this->_cfg(
                'texto_saludo_inicial',
                '¡Hola! Bienvenido(a) a {empresa}. Cuéntanos brevemente qué necesitas. Nuestro horario es {horario}.'
            );
            // Si ya viene una respuesta específica, dejamos solo el primer párrafo del saludo
            // (así no se repite el horario ni el pedido de foto/comprobante).
            if (!empty($partes) || $incluir_fuera || $incluir_urgencia) {
                $bloques = preg_split('/\r?\n\s*\r?\n/', trim($saludo));
                if (!empty($bloques[0])) {
                    $saludo = $bloques[0];
                }
            }
            $partes_finales[] = $saludo;
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

    /**
     * Texto de la categoría. Si el cliente YA está verificado, las categorías de
     * Soporte (1), Drivers (2) y Garantía (3) usan una versión que no vuelve a
     * pedir el comprobante (se puede editar con las claves texto_verificado_cat_N).
     */
    protected function _texto_categoria($cat_id, $verificado)
    {
        if ($verificado) {
            $v = $this->_cfg('texto_verificado_cat_' . $cat_id, NULL);
            if ($v === NULL || $v === '') {
                $defaults = array(
                    1 => 'Ya tenemos tu comprobante verificado. Cuéntanos qué ocurre exactamente con tu equipo (por ejemplo: no imprime, imprime borroso o la PC no lo reconoce) y, si puedes, envíanos una foto o video del problema. Un técnico revisará tu caso y te responderá en nuestro horario de atención ({horario}).',
                    2 => 'Ya tenemos tu comprobante verificado. Indícanos la marca y modelo de tu equipo y la versión de Windows (10 u 11). Un asesor te enviará el material por este chat en nuestro horario de atención ({horario}).',
                    3 => 'Ya tenemos tu comprobante verificado. Para evaluar tu garantía descríbenos la falla, con fotos o video si puedes. Si el caso lo requiere, el equipo debe llevarse a nuestro local para su diagnóstico. Te responderemos en nuestro horario de atención ({horario}).',
                );
                $v = isset($defaults[$cat_id]) ? $defaults[$cat_id] : NULL;
            }
            if ($v !== NULL && $v !== '') {
                return (string) $v;
            }
        }
        return (string) $this->respuestas[$cat_id];
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

        // Tolerancia a plural: cable/cables, error/errores, pago/pagos, lector/lectores
        $var_clave = $this->_variantes_plural($palabra_norm);
        $palabras_texto = preg_split('/\s+/u', $texto_norm);
        foreach ($palabras_texto as $w) {
            if ($w === '') continue;
            if (count(array_intersect($var_clave, $this->_variantes_plural($w))) > 0) {
                return TRUE;
            }
        }
        return FALSE;
    }

    /**
     * Formas "sin plural" de una palabra (para palabras de 5+ letras).
     * cables → [cables, cable]; errores → [errores, errore, error].
     */
    protected function _variantes_plural($w)
    {
        $len = function_exists('mb_strlen') ? mb_strlen($w, 'UTF-8') : strlen($w);
        $out = array($w);
        if ($len < 5) {
            return $out;
        }
        $sub = function ($s, $n) {
            return function_exists('mb_substr') ? mb_substr($s, 0, -$n, 'UTF-8') : substr($s, 0, -$n);
        };
        $cola = function_exists('mb_substr') ? mb_substr($w, -2, NULL, 'UTF-8') : substr($w, -2);
        if (substr($w, -1) === 's') {
            $out[] = $sub($w, 1);
        }
        if ($cola === 'es') {
            $out[] = $sub($w, 2);
        }
        return $out;
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