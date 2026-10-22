<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Mensajes de cada conversación. Guardamos texto o ruta de imagen.
 */
class Crm_mensaje_model extends CI_Model
{
    protected $tabla = 'crm_mensajes';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    /**
     * Crea un mensaje y actualiza la conversación en una transacción.
     * $emisor: 'cliente' | 'bot' | 'admin'
     * $tipo:   'texto'   | 'imagen' | 'sistema'
     */
    public function crear($conversacion_id, $emisor, $tipo, $contenido = NULL, $archivo = NULL)
    {
        $conv_id = (int) $conversacion_id;
        $ahora   = date('Y-m-d H:i:s');

        $this->db->trans_start();

        $this->db->insert($this->tabla, array(
            'conversacion_id' => $conv_id,
            'emisor'          => $emisor,
            'tipo'            => $tipo,
            'contenido'       => $contenido,
            'archivo'         => $archivo,
            'leido'           => 0,
            'creado_at'       => $ahora,
        ));
        $id = (int) $this->db->insert_id();

        // Actualiza la conversación
        $this->db->where('id', $conv_id)->update('crm_conversaciones', array(
            'ultimo_mensaje_at' => $ahora,
        ));

        // Si el mensaje es del cliente, sumamos no leídos al admin
        if ($emisor === 'cliente') {
            $this->db
                ->where('id', $conv_id)
                ->set('no_leidos_admin', 'no_leidos_admin + 1', FALSE)
                ->update('crm_conversaciones');
        }

        $this->db->trans_complete();

        return $this->db->trans_status() ? $id : FALSE;
    }

    /**
     * Lista los mensajes de una conversación.
     * $desde_id sirve para polling incremental.
     */
    public function listar($conversacion_id, $desde_id = 0, $limit = 300)
    {
        return $this->db
            ->where('conversacion_id', (int) $conversacion_id)
            ->where('id >', (int) $desde_id)
            ->order_by('id', 'ASC')
            ->limit((int) $limit)
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

    public function ultimo($conversacion_id)
    {
        return $this->db
            ->where('conversacion_id', (int) $conversacion_id)
            ->order_by('id', 'DESC')
            ->limit(1)
            ->get($this->tabla)
            ->row_array();
    }

    public function contar_por_conversacion($conversacion_id)
    {
        return (int) $this->db
            ->where('conversacion_id', (int) $conversacion_id)
            ->count_all_results($this->tabla);
    }

    public function contar_por_emisor($conversacion_id, $emisor)
    {
        return (int) $this->db
            ->where('conversacion_id', (int) $conversacion_id)
            ->where('emisor', $emisor)
            ->count_all_results($this->tabla);
    }

    /**
     * Marca como leídos todos los mensajes del cliente en una conversación
     * (lo llama el admin al abrirla).
     */
    public function marcar_leidos_cliente($conversacion_id)
    {
        $this->db
            ->where('conversacion_id', (int) $conversacion_id)
            ->where('emisor', 'cliente')
            ->where('leido', 0)
            ->update($this->tabla, array('leido' => 1));
    }

    /**
     * Cuenta mensajes del cliente en la última hora por conversación
     * (anti-spam).
     */
    public function contar_cliente_ultima_hora_conv($conversacion_id)
    {
        $desde = date('Y-m-d H:i:s', time() - 3600);
        return (int) $this->db
            ->where('conversacion_id', (int) $conversacion_id)
            ->where('emisor', 'cliente')
            ->where('creado_at >=', $desde)
            ->count_all_results($this->tabla);
    }

    /**
     * Cuenta mensajes del cliente en la última hora desde una IP.
     */
    public function contar_cliente_ultima_hora_ip($ip)
    {
        $desde = date('Y-m-d H:i:s', time() - 3600);
        return (int) $this->db
            ->where('c.creado_at >=', $desde)
            ->where('m.emisor', 'cliente')
            ->where('c.ip', $ip)
            ->from('crm_mensajes m')
            ->join('crm_conversaciones c', 'c.id = m.conversacion_id', 'inner')
            ->count_all_results();
    }

    /**
     * Cuenta conversaciones abiertas recientes desde una IP (anti-spam).
     */
    public function contar_conversaciones_ip_ultimo_dia($ip)
    {
        $desde = date('Y-m-d H:i:s', time() - 86400);
        return (int) $this->db
            ->where('ip', $ip)
            ->where('creado_at >=', $desde)
            ->count_all_results('crm_conversaciones');
    }
}