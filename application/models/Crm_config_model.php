<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Configuración general del CRM (clave / valor).
 * Se cachea en memoria durante la petición para evitar consultas repetidas.
 */
class Crm_config_model extends CI_Model
{
    protected $tabla = 'crm_configuracion';
    protected $cache = NULL;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    /**
     * Precarga TODA la configuración en memoria.
     */
    protected function _cargar_cache()
    {
        if ($this->cache !== NULL) {
            return;
        }
        $this->cache = array();
        foreach ($this->db->get($this->tabla)->result_array() as $r) {
            $this->cache[$r['clave']] = $r['valor'];
        }
    }

    public function obtener($clave, $default = NULL)
    {
        $this->_cargar_cache();
        return array_key_exists($clave, $this->cache) ? $this->cache[$clave] : $default;
    }

    public function obtener_int($clave, $default = 0)
    {
        return (int) $this->obtener($clave, $default);
    }

    public function obtener_todas()
    {
        $this->_cargar_cache();
        return $this->cache;
    }

    public function guardar($clave, $valor)
    {
        $existe = $this->db
            ->where('clave', $clave)
            ->count_all_results($this->tabla) > 0;

        if ($existe) {
            $this->db
                ->where('clave', $clave)
                ->update($this->tabla, array('valor' => $valor));
        } else {
            $this->db->insert($this->tabla, array(
                'clave'     => $clave,
                'valor'     => $valor,
                'creado_at' => date('Y-m-d H:i:s'),
            ));
        }
        // Invalida caché local
        $this->cache = NULL;
    }

    /**
     * Guarda varias claves de un solo golpe.
     * $pares = [ 'clave1' => 'valor1', ... ]
     */
    public function guardar_multiples(array $pares)
    {
        foreach ($pares as $clave => $valor) {
            $this->guardar($clave, $valor);
        }
    }
}