<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Auditoría de envíos de recursos a clientes (crm_recursos_envios).
 * Guarda qué recurso se envió a qué conversación, en qué mensaje y por qué admin.
 */
class Crm_recurso_envio_model extends CI_Model
{
    protected $tabla = 'crm_recursos_envios';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    /**
     * Registra un envío. $recursos es un array de IDs (int).
     * Devuelve el número de filas insertadas.
     */
    public function registrar_lote($conversacion_id, $mensaje_id, $admin_id, array $recurso_ids)
    {
        $conv_id = (int) $conversacion_id;
        $msg_id  = (int) $mensaje_id;
        $adm_id  = $admin_id ? (int) $admin_id : NULL;
        $ahora   = date('Y-m-d H:i:s');
        $n       = 0;

        foreach ($recurso_ids as $rid) {
            $rid = (int) $rid;
            if ($rid <= 0) continue;
            $this->db->insert($this->tabla, array(
                'conversacion_id' => $conv_id,
                'recurso_id'      => $rid,
                'mensaje_id'      => $msg_id ?: NULL,
                'admin_id'        => $adm_id,
                'creado_at'       => $ahora,
            ));
            $n++;
        }
        return $n;
    }

    /**
     * IDs de recursos ya enviados a esta conversación (para marcar "ya enviado").
     * Devuelve [recurso_id => cantidad_enviada].
     */
    public function mapa_recursos_enviados($conversacion_id)
    {
        $res = array();
        $rows = $this->db
            ->select('recurso_id, COUNT(*) AS veces')
            ->where('conversacion_id', (int) $conversacion_id)
            ->where('recurso_id IS NOT NULL', NULL, FALSE)
            ->group_by('recurso_id')
            ->get($this->tabla)
            ->result_array();
        foreach ($rows as $r) {
            $res[(int) $r['recurso_id']] = (int) $r['veces'];
        }
        return $res;
    }

    /**
     * Cuenta total de envíos de un recurso (para la pantalla de gestión).
     */
    public function contar_por_recurso($recurso_id)
    {
        return (int) $this->db
            ->where('recurso_id', (int) $recurso_id)
            ->count_all_results($this->tabla);
    }

    /**
     * Detalle de envíos de una conversación (para debug/auditoría).
     */
    public function listar_por_conversacion($conversacion_id, $limit = 100)
    {
        return $this->db
            ->select('e.*, r.titulo, r.url, r.marca, r.modelo, r.tipo')
            ->from($this->tabla . ' e')
            ->join('crm_recursos r', 'r.id = e.recurso_id', 'left')
            ->where('e.conversacion_id', (int) $conversacion_id)
            ->order_by('e.id', 'DESC')
            ->limit((int) $limit)
            ->get()
            ->result_array();
    }
}