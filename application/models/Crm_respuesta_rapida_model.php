<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Respuestas rápidas con atajos (/saludo, /precio, etc.).
 */
class Crm_respuesta_rapida_model extends CI_Model
{
    protected $tabla = 'crm_respuestas_rapidas';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function listar($solo_activas = FALSE)
    {
        if ($solo_activas) $this->db->where('activa', 1);
        return $this->db
            ->order_by('orden', 'ASC')
            ->order_by('atajo', 'ASC')
            ->get($this->tabla)
            ->result_array();
    }

    public function obtener($id)
    {
        return $this->db
            ->where('id', (int) $id)
            ->get($this->tabla)
            ->row_array();
    }

    public function obtener_por_atajo($atajo)
    {
        $atajo = trim((string) $atajo);
        if ($atajo === '') return NULL;
        return $this->db
            ->where('atajo', $atajo)
            ->where('activa', 1)
            ->get($this->tabla)
            ->row_array();
    }

    public function crear($datos)
    {
        $atajo = $this->_normalizar_atajo($datos['atajo']);
        if ($atajo === '') return FALSE;

        $this->db->insert($this->tabla, array(
            'atajo'     => $atajo,
            'titulo'    => trim((string) $datos['titulo']),
            'texto'     => trim((string) $datos['texto']),
            'activa'    => isset($datos['activa']) ? (int) $datos['activa'] : 1,
            'orden'     => isset($datos['orden']) ? (int) $datos['orden'] : 100,
            'creado_at' => date('Y-m-d H:i:s'),
        ));
        return (int) $this->db->insert_id();
    }

    public function actualizar($id, $datos)
    {
        $upd = array();
        if (isset($datos['atajo'])) {
            $a = $this->_normalizar_atajo($datos['atajo']);
            if ($a === '') return FALSE;
            $upd['atajo'] = $a;
        }
        if (isset($datos['titulo'])) $upd['titulo'] = trim((string) $datos['titulo']);
        if (isset($datos['texto']))  $upd['texto']  = trim((string) $datos['texto']);
        if (isset($datos['activa'])) $upd['activa'] = (int) $datos['activa'];
        if (isset($datos['orden']))  $upd['orden']  = (int) $datos['orden'];

        if (empty($upd)) return FALSE;
        $this->db->where('id', (int) $id)->update($this->tabla, $upd);
        return $this->db->affected_rows() >= 0;
    }

    public function eliminar($id)
    {
        $this->db->where('id', (int) $id)->delete($this->tabla);
        return $this->db->affected_rows() > 0;
    }

    protected function _normalizar_atajo($a)
    {
        $a = trim((string) $a);
        $a = function_exists('mb_strtolower') ? mb_strtolower($a, 'UTF-8') : strtolower($a);
        $a = preg_replace('/[^a-z0-9\-_]+/u', '', $a);
        return $a;
    }
}