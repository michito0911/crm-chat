<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Recursos (drivers, videos, manuales).
 * - listar_activos() y listar_sugeridos(): para el selector del chat.
 * - listar_admin()/crear()/actualizar()/eliminar(): para la gestión.
 */
class Crm_recurso_model extends CI_Model
{
    protected $tabla = 'crm_recursos';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    /* =========================================================
     *  LECTURA (bot + selector)
     * =======================================================*/

    public function listar_activos()
    {
        return $this->db
            ->where('activo', 1)
            ->order_by('marca', 'ASC')
            ->order_by('modelo', 'ASC')
            ->order_by('tipo', 'ASC')
            ->get($this->tabla)
            ->result_array();
    }

    public function listar_con_filtros($filtros = array(), $limit = 500, $offset = 0)
    {
        if (isset($filtros['activo']) && $filtros['activo'] !== NULL && $filtros['activo'] !== '') {
            $this->db->where('activo', (int) $filtros['activo']);
        }
        if (!empty($filtros['tipo'])) {
            $this->db->where('tipo', $filtros['tipo']);
        }
        if (!empty($filtros['q'])) {
            $q = trim($filtros['q']);
            $this->db->group_start();
            $this->db->like('marca', $q);
            $this->db->or_like('modelo', $q);
            $this->db->or_like('titulo', $q);
            $this->db->or_like('palabras_clave', $q);
            $this->db->group_end();
        }
        return $this->db
            ->order_by('marca', 'ASC')
            ->order_by('modelo', 'ASC')
            ->order_by('tipo', 'ASC')
            ->limit((int) $limit, (int) $offset)
            ->get($this->tabla)
            ->result_array();
    }

    public function listar_sugeridos($marca, $modelo, $limit = 6)
    {
        $marca  = trim((string) $marca);
        $modelo = trim((string) $modelo);

        if ($marca === '' && $modelo === '') return array();

        if ($modelo === '') {
            return $this->db
                ->where('activo', 1)
                ->where('marca', $marca)
                ->order_by('tipo', 'ASC')
                ->order_by('modelo', 'ASC')
                ->limit((int) $limit)
                ->get($this->tabla)
                ->result_array();
        }

        $resultado = array();
        $drivers = $this->db
            ->where('activo', 1)
            ->where('marca', $marca)
            ->where('modelo', $modelo)
            ->where('tipo', 'driver')
            ->order_by('id', 'ASC')
            ->limit(1)
            ->get($this->tabla)
            ->result_array();
        foreach ($drivers as $d) $resultado[] = $d;

        $videos = $this->db
            ->where('activo', 1)
            ->where('marca', $marca)
            ->where('modelo', $modelo)
            ->where('tipo', 'video')
            ->order_by('id', 'ASC')
            ->limit(2)
            ->get($this->tabla)
            ->result_array();
        foreach ($videos as $v) $resultado[] = $v;

        if (empty($resultado)) {
            $tokens = array();
            if ($marca !== '')  $tokens[] = $marca;
            if ($modelo !== '') $tokens[] = $modelo;
            $this->db->where('activo', 1);
            $this->db->group_start();
            foreach ($tokens as $t) $this->db->or_like('palabras_clave', $t);
            $this->db->group_end();
            $resultado = $this->db
                ->order_by('tipo', 'ASC')
                ->order_by('marca', 'ASC')
                ->limit((int) $limit)
                ->get($this->tabla)
                ->result_array();
        }

        return $resultado;
    }

    public function listar_marcas()
    {
        $rows = $this->db
            ->select('marca')
            ->distinct()
            ->order_by('marca', 'ASC')
            ->get($this->tabla)
            ->result_array();
        $out = array();
        foreach ($rows as $r) {
            $m = trim((string) $r['marca']);
            if ($m !== '') $out[] = $m;
        }
        return $out;
    }

    public function contar_activos()
    {
        return (int) $this->db
            ->where('activo', 1)
            ->count_all_results($this->tabla);
    }

    public function obtener($id)
    {
        return $this->db
            ->where('id', (int) $id)
            ->get($this->tabla)
            ->row_array();
    }

    /* =========================================================
     *  ADMIN (pantalla de gestión)
     * =======================================================*/

    /**
     * Listado para la pantalla de administración, con "veces_enviado".
     * Filtros: q, tipo, activo, marca ('OTROS' también cuenta).
     */
    public function listar_admin($filtros = array(), $limit = 25, $offset = 0)
    {
        $this->_admin_where($filtros);

        $this->db->select('r.*', FALSE);
        $this->db->select(
            '(SELECT COUNT(*) FROM crm_recursos_envios e WHERE e.recurso_id = r.id) AS veces_enviado',
            FALSE
        );
        return $this->db
            ->from($this->tabla . ' r')
            ->order_by('r.marca', 'ASC')
            ->order_by('r.modelo', 'ASC')
            ->order_by('r.tipo', 'ASC')
            ->limit((int) $limit, (int) $offset)
            ->get()
            ->result_array();
    }

    public function contar_admin($filtros = array())
    {
        $this->_admin_where($filtros);
        $this->db->from($this->tabla . ' r');
        return (int) $this->db->count_all_results();
    }

    protected function _admin_where($filtros = array())
    {
        if (!empty($filtros['q'])) {
            $q = trim($filtros['q']);
            $this->db->group_start();
            $this->db->like('r.marca', $q);
            $this->db->or_like('r.modelo', $q);
            $this->db->or_like('r.titulo', $q);
            $this->db->or_like('r.palabras_clave', $q);
            $this->db->group_end();
        }
        if (!empty($filtros['tipo'])) {
            $this->db->where('r.tipo', $filtros['tipo']);
        }
        if (isset($filtros['activo']) && $filtros['activo'] !== '' && $filtros['activo'] !== NULL) {
            $this->db->where('r.activo', (int) $filtros['activo']);
        }
        if (!empty($filtros['marca'])) {
            $this->db->where('r.marca', $filtros['marca']);
        }
    }

    /**
     * ¿Ya existe otro recurso con la misma URL?
     * $excepto_id permite excluir el propio registro al editar.
     */
    public function url_existe($url, $excepto_id = NULL)
    {
        $url = trim((string) $url);
        if ($url === '') return FALSE;

        $this->db->where('url', $url);
        if ($excepto_id !== NULL && (int) $excepto_id > 0) {
            $this->db->where('id !=', (int) $excepto_id);
        }
        return (int) $this->db->count_all_results($this->tabla) > 0;
    }

    /**
     * Crea un recurso. Devuelve el ID insertado o FALSE.
     */
    public function crear($datos)
    {
        $insert = array(
            'marca'          => trim((string) $datos['marca']),
            'modelo'         => trim((string) $datos['modelo']),
            'palabras_clave' => $this->_normalizar_palabras_clave($datos['palabras_clave']),
            'tipo'           => $datos['tipo'],
            'titulo'         => trim((string) $datos['titulo']),
            'url'            => trim((string) $datos['url']),
            'sistema'        => isset($datos['sistema']) ? trim((string) $datos['sistema']) : NULL,
            'notas'          => isset($datos['notas']) ? trim((string) $datos['notas']) : NULL,
            'activo'         => isset($datos['activo']) ? (int) $datos['activo'] : 1,
            'creado_at'      => date('Y-m-d H:i:s'),
        );
        $this->db->insert($this->tabla, $insert);
        return (int) $this->db->insert_id();
    }

    /**
     * Actualiza un recurso. Devuelve TRUE/FALSE.
     */
    public function actualizar($id, $datos)
    {
        $upd = array();
        if (isset($datos['marca']))          $upd['marca']          = trim((string) $datos['marca']);
        if (isset($datos['modelo']))         $upd['modelo']         = trim((string) $datos['modelo']);
        if (isset($datos['palabras_clave'])) $upd['palabras_clave'] = $this->_normalizar_palabras_clave($datos['palabras_clave']);
        if (isset($datos['tipo']))           $upd['tipo']           = $datos['tipo'];
        if (isset($datos['titulo']))         $upd['titulo']         = trim((string) $datos['titulo']);
        if (isset($datos['url']))            $upd['url']            = trim((string) $datos['url']);
        if (array_key_exists('sistema', $datos)) $upd['sistema']    = $datos['sistema'] === '' ? NULL : trim((string) $datos['sistema']);
        if (array_key_exists('notas', $datos))   $upd['notas']      = $datos['notas']   === '' ? NULL : trim((string) $datos['notas']);
        if (isset($datos['activo']))         $upd['activo']         = (int) $datos['activo'];

        if (empty($upd)) return FALSE;
        $this->db->where('id', (int) $id)->update($this->tabla, $upd);
        return $this->db->affected_rows() >= 0;
    }

    public function eliminar($id)
    {
        $this->db->where('id', (int) $id)->delete($this->tabla);
        return $this->db->affected_rows() > 0;
    }

    /**
     * Duplica un recurso con " (copia)" en el título. Devuelve el nuevo ID.
     */
    public function duplicar($id)
    {
        $r = $this->obtener($id);
        if (!$r) return FALSE;

        $nuevo = $r;
        unset($nuevo['id']);
        unset($nuevo['creado_at']);
        unset($nuevo['actualizado_at']);
        $nuevo['titulo']    = $r['titulo'] . ' (copia)';
        $nuevo['activo']    = 0;
        $nuevo['creado_at'] = date('Y-m-d H:i:s');

        $this->db->insert($this->tabla, $nuevo);
        return (int) $this->db->insert_id();
    }

    public function toggle_activo($id)
    {
        $r = $this->obtener($id);
        if (!$r) return FALSE;
        $nuevo = ((int) $r['activo'] === 1) ? 0 : 1;
        $this->db->where('id', (int) $id)->update($this->tabla, array('activo' => $nuevo));
        return $nuevo;
    }

    /**
     * Normaliza la lista de palabras clave:
     *   "  XP-365B , xp365b ,  " → "xp-365b,xp365b"
     * Quita espacios alrededor de cada token, elimina vacíos y duplicados.
     */
    protected function _normalizar_palabras_clave($csv)
    {
        $csv = (string) $csv;
        if ($csv === '') return NULL;
        $partes = preg_split('/[,\n\r]+/', $csv);
        $out = array();
        foreach ($partes as $p) {
            $p = trim($p);
            if ($p === '') continue;
            $out[] = $p;
        }
        $out = array_values(array_unique($out));
        return empty($out) ? NULL : implode(',', $out);
    }
}