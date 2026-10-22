<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Conversaciones del chat (una por cliente que entra al chat público).
 * El acceso del cliente se hace siempre mediante el token de 64 caracteres.
 */
class Crm_conversacion_model extends CI_Model
{
    protected $tabla = 'crm_conversaciones';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    /**
     * Crea una nueva conversación con token aleatorio de 64 chars.
     */
    public function crear($datos)
    {
        $token = bin2hex(random_bytes(32)); // 64 caracteres hex
        $ahora = date('Y-m-d H:i:s');

        $insert = array(
            'token'             => $token,
            'nombre'            => trim($datos['nombre']),
            'telefono'          => !empty($datos['telefono']) ? trim($datos['telefono']) : NULL,
            'categoria_id'      => isset($datos['categoria_id']) ? (int) $datos['categoria_id'] : NULL,
            'categoria_manual'  => 0,
            'estado'            => 'abierta',
            'modo'              => 'bot',
            'pendiente_revision'=> 0,
            'ultimo_mensaje_at' => $ahora,
            'no_leidos_admin'   => 0,
            'acepto_datos'      => !empty($datos['acepto_datos']) ? 1 : 0,
            'ip'                => !empty($datos['ip']) ? $datos['ip'] : NULL,
            'user_agent'        => !empty($datos['user_agent']) ? substr($datos['user_agent'], 0, 255) : NULL,
            'creado_at'         => $ahora,
        );

        $this->db->insert($this->tabla, $insert);
        return array(
            'id'    => (int) $this->db->insert_id(),
            'token' => $token,
        );
    }

    public function obtener($id)
    {
        return $this->db
            ->where('id', (int) $id)
            ->get($this->tabla)
            ->row_array();
    }

    /**
     * Obtiene una conversación por token. Es el único modo en que el
     * cliente puede consultar su historial.
     */
    public function obtener_por_token($token)
    {
        if (!is_string($token) || strlen($token) !== 64) {
            return NULL;
        }
        return $this->db
            ->where('token', $token)
            ->get($this->tabla)
            ->row_array();
    }

    public function actualizar_ultimo_mensaje($id)
    {
        $this->db
            ->where('id', (int) $id)
            ->update($this->tabla, array('ultimo_mensaje_at' => date('Y-m-d H:i:s')));
    }

    public function incrementar_no_leidos($id)
    {
        $this->db
            ->where('id', (int) $id)
            ->set('no_leidos_admin', 'no_leidos_admin + 1', FALSE)
            ->update($this->tabla);
    }

    public function resetear_no_leidos($id)
    {
        $this->db
            ->where('id', (int) $id)
            ->update($this->tabla, array('no_leidos_admin' => 0));
    }

    public function cambiar_estado($id, $estado)
    {
        $permitidos = array('abierta', 'en_atencion', 'cerrada');
        if (!in_array($estado, $permitidos, TRUE)) {
            return FALSE;
        }
        $this->db
            ->where('id', (int) $id)
            ->update($this->tabla, array('estado' => $estado));
        return TRUE;
    }

    public function cambiar_modo($id, $modo)
    {
        if (!in_array($modo, array('bot', 'humano'), TRUE)) {
            return FALSE;
        }
        $this->db
            ->where('id', (int) $id)
            ->update($this->tabla, array('modo' => $modo));
        return TRUE;
    }

    /**
     * Cambia la categoría a mano. Marca categoria_manual = 1 para que
     * el bot ya no la recategorice en esa conversación.
     */
    public function cambiar_categoria_manual($id, $categoria_id)
    {
        $this->db
            ->where('id', (int) $id)
            ->update($this->tabla, array(
                'categoria_id'     => $categoria_id === NULL ? NULL : (int) $categoria_id,
                'categoria_manual' => 1,
            ));
    }

    /**
     * Actualiza la categoría detectada por el bot (sin marcar manual).
     */
    public function cambiar_categoria_bot($id, $categoria_id)
    {
        $this->db
            ->where('id', (int) $id)
            ->update($this->tabla, array(
                'categoria_id' => $categoria_id === NULL ? NULL : (int) $categoria_id,
            ));
    }

    public function marcar_pendiente_revision($id, $valor = TRUE)
    {
        $this->db
            ->where('id', (int) $id)
            ->update($this->tabla, array('pendiente_revision' => $valor ? 1 : 0));
    }

    /**
     * Listado paginado con filtros para el panel de administrador.
     *
     * $filtros admite:
     *   - categoria_id  (int)
     *   - estado        ('abierta'|'en_atencion'|'cerrada'|'todas')
     *   - busqueda      (texto libre)
     *   - pendiente     (bool, opcional)
     */
    public function listar_con_filtros($filtros = array(), $limit = 30, $offset = 0)
    {
        $this->_aplicar_filtros($filtros);

                $this->db->select('c.*, cat.nombre AS categoria_nombre, cat.color AS categoria_color', FALSE);
        $this->db->from($this->tabla . ' c');
        $this->db->join('crm_categorias cat', 'cat.id = c.categoria_id', 'left');

        // Último mensaje del hilo (texto o marcador de imagen)
        $this->db->select(
            "(SELECT m.contenido FROM crm_mensajes m
                WHERE m.conversacion_id = c.id
                ORDER BY m.id DESC LIMIT 1) AS ultimo_mensaje",
            FALSE
        );
        $this->db->select(
            "(SELECT m.tipo FROM crm_mensajes m
                WHERE m.conversacion_id = c.id
                ORDER BY m.id DESC LIMIT 1) AS ultimo_tipo",
            FALSE
        );

        $this->db->order_by('c.urgente', 'DESC');
        $this->db->order_by('c.ultimo_mensaje_at', 'DESC');
        $this->db->order_by('c.id', 'DESC');

        return $this->db->get()->result_array();
    }

    public function contar_con_filtros($filtros = array())
    {
        $this->_aplicar_filtros($filtros);
        $this->db->from($this->tabla . ' c');
        $this->db->join('crm_categorias cat', 'cat.id = c.categoria_id', 'left');
        return (int) $this->db->count_all_results();
    }

    protected function _aplicar_filtros($filtros)
    {
        if (!empty($filtros['categoria_id'])) {
            $this->db->where('c.categoria_id', (int) $filtros['categoria_id']);
        }
        if (!empty($filtros['estado']) && $filtros['estado'] !== 'todas') {
            $this->db->where('c.estado', $filtros['estado']);
        }
        if (!empty($filtros['pendiente'])) {
            $this->db->where('c.pendiente_revision', 1);
        }
        if (!empty($filtros['busqueda'])) {
            $q = trim($filtros['busqueda']);
            $this->db->group_start();
            $this->db->like('c.nombre', $q);
            $this->db->or_like('c.telefono', $q);
            $this->db->or_where(
                'c.id IN (SELECT DISTINCT m.conversacion_id FROM crm_mensajes m WHERE m.contenido LIKE ' .
                $this->db->escape('%' . $q . '%') . ')',
                NULL, FALSE
            );
            $this->db->group_end();
        }
    }

    /**
     * Contador total de no leídos para el badge del panel.
     */
    public function contar_no_leidos_total()
    {
        $row = $this->db
            ->select_sum('no_leidos_admin')
            ->get($this->tabla)
            ->row_array();
        return isset($row['no_leidos_admin']) ? (int) $row['no_leidos_admin'] : 0;
    }

    /**
     * Conteo por estado (para chips del panel).
     */
    public function contar_por_estado()
    {
        $res = array('abierta' => 0, 'en_atencion' => 0, 'cerrada' => 0);
        $rows = $this->db
            ->select('estado, COUNT(*) AS total')
            ->group_by('estado')
            ->get($this->tabla)
            ->result_array();
        foreach ($rows as $r) {
            if (isset($res[$r['estado']])) {
                $res[$r['estado']] = (int) $r['total'];
            }
        }
        return $res;
    }

        /**
     * Marca solicita_recurso = 1. Se usa cuando el bot detecta que el
     * cliente pidió driver/video/tutorial (sección 4.3).
     */
    public function marcar_solicita_recurso($id)
    {
        $this->db
            ->where('id', (int) $id)
            ->update($this->tabla, array('solicita_recurso' => 1));
    }

    /**
     * Guarda el equipo detectado por el bot (sección 4.4).
     * Solo sobrescribe los campos que llegan con valor.
     */
    public function actualizar_equipo($id, $marca, $modelo)
    {
        $upd = array();
        if ($marca !== NULL && $marca !== '') {
            $upd['equipo_marca'] = (string)$marca;
        }
        if ($modelo !== NULL && $modelo !== '') {
            $upd['equipo_modelo'] = (string)$modelo;
        }
        if (empty($upd)) return;
        $this->db->where('id', (int)$id)->update($this->tabla, $upd);
    }

    /**
     * Marca la conversación como "pendiente" de verificación (sección 4.6).
     * No hace nada si ya está verificada.
     */
    public function marcar_verificacion_pendiente($id)
    {
        $conv = $this->obtener($id);
        if (!$conv) return;
        if ($conv['verificacion_estado'] === 'verificado') return;
        $this->db
            ->where('id', (int)$id)
            ->update($this->tabla, array('verificacion_estado' => 'pendiente'));
    }
        /**
     * Marca la conversación como verificada (comprobante validado manualmente).
     * $tipo: boleta|factura|otro|null
     */
    public function verificar_comprobante($id, $admin_id, $tipo = NULL, $numero = NULL)
    {
        $permitidos = array('boleta', 'factura', 'otro');
        if ($tipo !== NULL && !in_array($tipo, $permitidos, TRUE)) {
            $tipo = NULL;
        }
        $numero = trim((string) $numero);
        if (function_exists('mb_substr')) {
            $numero = mb_substr($numero, 0, 40, 'UTF-8');
        } else {
            $numero = substr($numero, 0, 40);
        }

        $this->db
            ->where('id', (int) $id)
            ->update($this->tabla, array(
                'verificacion_estado'  => 'verificado',
                'comprobante_tipo'     => $tipo,
                'comprobante_numero'   => ($numero !== '') ? $numero : NULL,
                'verificado_at'        => date('Y-m-d H:i:s'),
                'verificado_por'       => $admin_id ? (int) $admin_id : NULL,
                'pendiente_revision'   => 0,
            ));
    }

    /**
     * Quita la verificación (vuelve a sin_verificar).
     */
    public function quitar_verificacion($id)
    {
        $this->db
            ->where('id', (int) $id)
            ->update($this->tabla, array(
                'verificacion_estado'  => 'sin_verificar',
                'comprobante_tipo'     => NULL,
                'comprobante_numero'   => NULL,
                'verificado_at'        => NULL,
                'verificado_por'       => NULL,
            ));
    }
        /**
     * Guarda los campos de la ficha automática (mejora #4).
     * Solo sobrescribe los campos con valor.
     */
    public function actualizar_ficha($id, array $ficha)
    {
        $upd = array();
        if (!empty($ficha['dni']))         $upd['dni_cliente']           = $ficha['dni'];
        if (!empty($ficha['ruc']))         $upd['ruc_cliente']           = $ficha['ruc'];
        if (!empty($ficha['comprobante'])) $upd['comprobante_detectado'] = $ficha['comprobante'];
        if (!empty($ficha['windows']))     $upd['windows_version']       = $ficha['windows'];

        if (empty($upd)) return;
        $this->db->where('id', (int)$id)->update($this->tabla, $upd);
    }

    /**
     * Marca la conversación como urgente (mejora #7).
     * Idempotente: si ya está marcada, solo actualiza la fecha.
     */
    public function marcar_urgente($id)
    {
        $this->db
            ->where('id', (int)$id)
            ->update($this->tabla, array(
                'urgente'    => 1,
                'urgente_at' => date('Y-m-d H:i:s'),
            ));
    }

    /**
     * Busca una conversación reciente del mismo teléfono (mejora #2).
     * Devuelve la fila más reciente con ultimo_mensaje_at >= ahora-24h, o NULL.
     */
    public function obtener_reciente_por_telefono($telefono, $horas = 24)
    {
        $telefono = trim((string)$telefono);
        if ($telefono === '') return NULL;

        $desde = date('Y-m-d H:i:s', time() - ((int)$horas * 3600));

        return $this->db
            ->where('telefono', $telefono)
            ->where('ultimo_mensaje_at >=', $desde)
            ->where('estado !=', 'cerrada')
            ->order_by('ultimo_mensaje_at', 'DESC')
            ->limit(1)
            ->get($this->tabla)
            ->row_array();
    }
}