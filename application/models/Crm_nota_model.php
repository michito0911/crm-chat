<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Notas internas del administrador. Nunca visibles al cliente.
 */
class Crm_nota_model extends CI_Model
{
    protected $tabla = 'crm_notas';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function crear($conversacion_id, $admin_id, $nota)
    {
        $nota = trim((string) $nota);
        if ($nota === '') {
            return FALSE;
        }
        $this->db->insert($this->tabla, array(
            'conversacion_id' => (int) $conversacion_id,
            'admin_id'        => $admin_id ? (int) $admin_id : NULL,
            'nota'            => $nota,
            'creado_at'       => date('Y-m-d H:i:s'),
        ));
        return (int) $this->db->insert_id();
    }

    public function listar_por_conversacion($conversacion_id)
    {
        return $this->db
            ->where('conversacion_id', (int) $conversacion_id)
            ->order_by('id', 'ASC')
            ->get($this->tabla)
            ->result_array();
    }

    public function eliminar($id)
    {
        $this->db->where('id', (int) $id)->delete($this->tabla);
        return $this->db->affected_rows() > 0;
    }
}