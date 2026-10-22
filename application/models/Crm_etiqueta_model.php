<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Etiquetas secundarias de una conversación (categorías que también
 * puntuaron pero no fueron la principal).
 */
class Crm_etiqueta_model extends CI_Model
{
    protected $tabla = 'crm_etiquetas_conversacion';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    /**
     * Reemplaza las etiquetas secundarias de una conversación.
     * $etiquetas = [ ['categoria_id'=>X,'puntaje'=>Y], ... ]
     */
    public function reemplazar($conversacion_id, array $etiquetas)
    {
        $conv_id = (int) $conversacion_id;

        $this->db->trans_start();
        $this->db->where('conversacion_id', $conv_id)->delete($this->tabla);

        $ahora = date('Y-m-d H:i:s');
        foreach ($etiquetas as $e) {
            if (empty($e['categoria_id'])) continue;
            $this->db->insert($this->tabla, array(
                'conversacion_id' => $conv_id,
                'categoria_id'    => (int) $e['categoria_id'],
                'puntaje'         => isset($e['puntaje']) ? (int) $e['puntaje'] : 0,
                'creado_at'       => $ahora,
            ));
        }
        $this->db->trans_complete();

        return $this->db->trans_status();
    }

    /**
     * Lista de etiquetas con nombre y color de categoría.
     */
    public function listar_por_conversacion($conversacion_id)
    {
        return $this->db
            ->select('e.*, c.nombre AS categoria_nombre, c.color AS categoria_color', FALSE)
            ->from($this->tabla . ' e')
            ->join('crm_categorias c', 'c.id = e.categoria_id', 'inner')
            ->where('e.conversacion_id', (int) $conversacion_id)
            ->order_by('e.puntaje', 'DESC')
            ->get()
            ->result_array();
    }
}