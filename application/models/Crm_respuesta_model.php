<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Respuestas automáticas por categoría.
 * Una categoría puede tener varias; el bot usa la primera activa.
 */
class Crm_respuesta_model extends CI_Model
{
    protected $tabla = 'crm_respuestas_auto';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function listar_por_categoria($categoria_id)
    {
        return $this->db
            ->where('categoria_id', (int) $categoria_id)
            ->order_by('id', 'ASC')
            ->get($this->tabla)
            ->result_array();
    }

    public function listar_todas()
    {
        return $this->db
            ->order_by('categoria_id', 'ASC')
            ->order_by('id', 'ASC')
            ->get($this->tabla)
            ->result_array();
    }

    /**
     * Devuelve la primera respuesta activa de la categoría, o NULL.
     */
    public function obtener_activa_por_categoria($categoria_id)
    {
        return $this->db
            ->where('categoria_id', (int) $categoria_id)
            ->where('activa', 1)
            ->order_by('id', 'ASC')
            ->limit(1)
            ->get($this->tabla)
            ->row_array();
    }

    /**
     * Mapa categoria_id => texto (solo activas). Para el bot.
     */
    public function mapa_activas()
    {
        $res = array();
        foreach ($this->listar_todas() as $r) {
            $cid = (int) $r['categoria_id'];
            if ((int) $r['activa'] === 1 && !isset($res[$cid])) {
                $res[$cid] = $r['texto'];
            }
        }
        return $res;
    }

    public function obtener($id)
    {
        return $this->db
            ->where('id', (int) $id)
            ->get($this->tabla)
            ->row_array();
    }

    public function crear($datos)
    {
        $texto = trim((string) $datos['texto']);
        if ($texto === '') {
            return FALSE;
        }
        $this->db->insert($this->tabla, array(
            'categoria_id' => (int) $datos['categoria_id'],
            'texto'        => $texto,
            'activa'       => isset($datos['activa']) ? (int) $datos['activa'] : 1,
            'creado_at'    => date('Y-m-d H:i:s'),
        ));
        return (int) $this->db->insert_id();
    }

    public function actualizar($id, $datos)
    {
        $update = array();
        if (isset($datos['texto']))        $update['texto']        = trim((string) $datos['texto']);
        if (isset($datos['categoria_id'])) $update['categoria_id'] = (int) $datos['categoria_id'];
        if (isset($datos['activa']))       $update['activa']       = (int) $datos['activa'];

        if (empty($update)) {
            return FALSE;
        }
        $this->db->where('id', (int) $id)->update($this->tabla, $update);
        return $this->db->affected_rows() >= 0;
    }

    public function eliminar($id)
    {
        $this->db->where('id', (int) $id)->delete($this->tabla);
        return $this->db->affected_rows() > 0;
    }
}