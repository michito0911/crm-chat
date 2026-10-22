<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Categorías del CRM (soporte, garantía, ventas, etc.).
 * La categoría "Sin clasificar" viene marcada con es_sistema = 1
 * y no debe eliminarse.
 */
class Crm_categoria_model extends CI_Model
{
    protected $tabla = 'crm_categorias';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    /**
     * Lista todas las categorías (o solo activas).
     */
    public function listar($solo_activas = FALSE)
    {
        if ($solo_activas) {
            $this->db->where('activa', 1);
        }
        return $this->db
            ->order_by('orden', 'ASC')
            ->order_by('nombre', 'ASC')
            ->get($this->tabla)
            ->result_array();
    }

    /**
     * Devuelve id => nombre (útil para selects).
     */
    public function listar_mapa($solo_activas = FALSE)
    {
        $mapa = array();
        foreach ($this->listar($solo_activas) as $c) {
            $mapa[(int) $c['id']] = $c['nombre'];
        }
        return $mapa;
    }

    public function obtener($id)
    {
        return $this->db
            ->where('id', (int) $id)
            ->get($this->tabla)
            ->row_array();
    }

    public function obtener_por_nombre($nombre)
    {
        return $this->db
            ->where('nombre', $nombre)
            ->get($this->tabla)
            ->row_array();
    }

    /**
     * Devuelve el ID de la categoría "Sin clasificar".
     */
    public function obtener_id_sin_clasificar()
    {
        $row = $this->db
            ->where('es_sistema', 1)
            ->order_by('orden', 'ASC')
            ->limit(1)
            ->get($this->tabla)
            ->row_array();
        return $row ? (int) $row['id'] : NULL;
    }

    /**
     * Devuelve id => [nombre, color, orden] para renderizar listados.
     */
    public function listar_detalle()
    {
        $res = array();
        foreach ($this->listar() as $c) {
            $res[(int) $c['id']] = $c;
        }
        return $res;
    }

    public function crear($datos)
    {
        $insert = array(
            'nombre'     => trim($datos['nombre']),
            'color'      => !empty($datos['color']) ? $datos['color'] : '#6c757d',
            'orden'      => isset($datos['orden']) ? (int) $datos['orden'] : 100,
            'activa'     => isset($datos['activa']) ? (int) $datos['activa'] : 1,
            'es_sistema' => 0,
            'creado_at'  => date('Y-m-d H:i:s'),
        );
        $this->db->insert($this->tabla, $insert);
        return (int) $this->db->insert_id();
    }

    public function actualizar($id, $datos)
    {
        $update = array();
        if (isset($datos['nombre']))  $update['nombre']  = trim($datos['nombre']);
        if (isset($datos['color']))   $update['color']   = $datos['color'];
        if (isset($datos['orden']))   $update['orden']   = (int) $datos['orden'];
        if (isset($datos['activa']))  $update['activa']  = (int) $datos['activa'];

        if (empty($update)) {
            return FALSE;
        }

        $this->db->where('id', (int) $id)->update($this->tabla, $update);
        return $this->db->affected_rows() >= 0;
    }

    /**
     * Elimina una categoría (nunca una de sistema).
     */
    public function eliminar($id)
    {
        $cat = $this->obtener($id);
        if (!$cat || (int) $cat['es_sistema'] === 1) {
            return FALSE;
        }
        $this->db->where('id', (int) $id)->delete($this->tabla);
        return $this->db->affected_rows() > 0;
    }

    public function contar()
    {
        return (int) $this->db->count_all($this->tabla);
    }
}