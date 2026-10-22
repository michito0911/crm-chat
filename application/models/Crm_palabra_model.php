<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Palabras clave por categoría. Cada una tiene un peso 1..5.
 * La palabra se guarda normalizada (minúsculas, sin tildes, sin signos)
 * para que el Bot_service pueda compararla rápido.
 */
class Crm_palabra_model extends CI_Model
{
    protected $tabla = 'crm_palabras_clave';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    public function listar_por_categoria($categoria_id)
    {
        return $this->db
            ->where('categoria_id', (int) $categoria_id)
            ->order_by('peso', 'DESC')
            ->order_by('palabra', 'ASC')
            ->get($this->tabla)
            ->result_array();
    }

    public function listar_todas()
    {
        return $this->db
            ->order_by('categoria_id', 'ASC')
            ->order_by('peso', 'DESC')
            ->order_by('palabra', 'ASC')
            ->get($this->tabla)
            ->result_array();
    }

    /**
     * Devuelve todas las palabras agrupadas por categoría.
     * Formato: [categoria_id => [ ['palabra'=>..,'peso'=>..], ... ], ...]
     * Lo usa el Bot_service para no golpear la BD por cada mensaje.
     */
    public function listar_agrupadas()
    {
        $res = array();
        foreach ($this->listar_todas() as $p) {
            $cid = (int) $p['categoria_id'];
            if (!isset($res[$cid])) {
                $res[$cid] = array();
            }
            $res[$cid][] = array(
                'palabra' => $p['palabra'],
                'peso'    => (int) $p['peso'],
            );
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

    /**
     * Crea una palabra. Se normaliza antes de guardar.
     */
    public function crear($datos)
    {
        $palabra = $this->_normalizar($datos['palabra']);
        if ($palabra === '') {
            return FALSE;
        }
        $peso = isset($datos['peso']) ? (int) $datos['peso'] : 1;
        $peso = max(1, min(5, $peso));

        $this->db->insert($this->tabla, array(
            'categoria_id' => (int) $datos['categoria_id'],
            'palabra'      => $palabra,
            'peso'         => $peso,
            'creado_at'    => date('Y-m-d H:i:s'),
        ));
        return (int) $this->db->insert_id();
    }

    public function actualizar($id, $datos)
    {
        $update = array();
        if (isset($datos['palabra'])) {
            $norm = $this->_normalizar($datos['palabra']);
            if ($norm === '') {
                return FALSE;
            }
            $update['palabra'] = $norm;
        }
        if (isset($datos['peso'])) {
            $update['peso'] = max(1, min(5, (int) $datos['peso']));
        }
        if (isset($datos['categoria_id'])) {
            $update['categoria_id'] = (int) $datos['categoria_id'];
        }

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

    public function eliminar_por_categoria($categoria_id)
    {
        $this->db->where('categoria_id', (int) $categoria_id)->delete($this->tabla);
    }

    /**
     * Normaliza igual que Bot_service: minúsculas, sin tildes, sin signos.
     * Se usa al guardar y al comparar, para que sea consistente.
     */
    protected function _normalizar($texto)
    {
        $texto = trim((string) $texto);
        if ($texto === '') {
            return '';
        }
        $texto = function_exists('mb_strtolower')
            ? mb_strtolower($texto, 'UTF-8')
            : strtolower($texto);

        $reemplazos = array(
            'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','ã'=>'a',
            'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
            'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
            'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','õ'=>'o',
            'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
            'ñ'=>'n','ç'=>'c',
        );
        $texto = strtr($texto, $reemplazos);
        $texto = preg_replace('/[^a-z0-9\s]+/u', ' ', $texto);
        $texto = preg_replace('/\s+/u', ' ', $texto);
        return trim($texto);
    }
}