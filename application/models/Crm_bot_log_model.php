<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Registro de respuestas automáticas del bot.
 * Sirve para no repetir la misma respuesta en menos de X minutos.
 */
class Crm_bot_log_model extends CI_Model
{
    protected $tabla = 'crm_bot_log';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function registrar($conversacion_id, $tipo, $categoria_id = NULL)
    {
        $this->db->insert($this->tabla, array(
            'conversacion_id' => (int) $conversacion_id,
            'tipo'            => (string) $tipo,
            'categoria_id'    => $categoria_id === NULL ? NULL : (int) $categoria_id,
            'creado_at'       => date('Y-m-d H:i:s'),
        ));
    }

    /**
     * Cuenta cuántas veces se respondió con ese tipo (y opcionalmente esa
     * categoría) desde hace $minutos.
     */
    public function contar_recientes($conversacion_id, $tipo, $minutos, $categoria_id = NULL)
    {
        $desde = date('Y-m-d H:i:s', time() - ((int) $minutos * 60));
        $this->db
            ->where('conversacion_id', (int) $conversacion_id)
            ->where('tipo', $tipo)
            ->where('creado_at >=', $desde);

        if ($categoria_id !== NULL) {
            $this->db->where('categoria_id', (int) $categoria_id);
        }
        return (int) $this->db->count_all_results($this->tabla);
    }

    /**
     * True si ya se envió ese tipo/categoría en los últimos $minutos.
     */
    public function ya_respondido_recientemente($conversacion_id, $tipo, $minutos, $categoria_id = NULL)
    {
        return $this->contar_recientes($conversacion_id, $tipo, $minutos, $categoria_id) > 0;
    }
}