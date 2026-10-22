<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Modelo de administradores del CRM.
 * Un solo administrador (o varios si luego lo necesitas).
 */
class Crm_admin_model extends CI_Model
{
    /** Máximo de intentos fallidos antes de bloquear */
    const MAX_INTENTOS = 5;

    /** Minutos de bloqueo tras superar los intentos fallidos */
    const MINUTOS_BLOQUEO = 15;

    protected $tabla = 'crm_admins';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
    }

    /**
     * Obtiene un admin por su nombre de usuario.
     */
    public function obtener_por_usuario($usuario)
    {
        return $this->db
            ->where('usuario', $usuario)
            ->where('activo', 1)
            ->get($this->tabla)
            ->row_array();
    }

    /**
     * Obtiene un admin por su ID.
     */
    public function obtener($id)
    {
        return $this->db
            ->where('id', (int) $id)
            ->get($this->tabla)
            ->row_array();
    }

    /**
     * Devuelve TRUE si el admin está bloqueado temporalmente.
     */
    public function esta_bloqueado($admin)
    {
        if (empty($admin['bloqueado_hasta'])) {
            return FALSE;
        }
        return strtotime($admin['bloqueado_hasta']) > time();
    }

    /**
     * Suma un intento fallido. Si alcanza el máximo, bloquea.
     */
    public function sumar_intento_fallido($admin_id)
    {
        $admin = $this->obtener($admin_id);
        if (!$admin) {
            return;
        }

        $intentos = (int) $admin['intentos_fallidos'] + 1;

        $datos = array('intentos_fallidos' => $intentos);

        if ($intentos >= self::MAX_INTENTOS) {
            $datos['bloqueado_hasta'] = date(
                'Y-m-d H:i:s',
                time() + (self::MINUTOS_BLOQUEO * 60)
            );
        }

        $this->db->where('id', (int) $admin_id)->update($this->tabla, $datos);
    }

    /**
     * Limpia intentos y bloqueos (login correcto o cambio de contraseña).
     */
    public function resetear_intentos($admin_id)
    {
        $this->db
            ->where('id', (int) $admin_id)
            ->update($this->tabla, array(
                'intentos_fallidos' => 0,
                'bloqueado_hasta'   => NULL,
            ));
    }

    /**
     * Marca el último login.
     */
    public function registrar_ultimo_login($admin_id)
    {
        $this->db
            ->where('id', (int) $admin_id)
            ->update($this->tabla, array('ultimo_login' => date('Y-m-d H:i:s')));
    }

    /**
     * Guarda el hash de la nueva contraseña.
     */
    public function cambiar_password($admin_id, $nuevo_hash)
    {
        $this->db
            ->where('id', (int) $admin_id)
            ->update($this->tabla, array(
                'password_hash'           => $nuevo_hash,
                'debe_cambiar_password'   => 0,
                'intentos_fallidos'       => 0,
                'bloqueado_hasta'         => NULL,
            ));
    }

    /**
     * Registra un intento de login (para auditoría).
     */
    public function registrar_intento($usuario, $ip, $exito)
    {
        $this->db->insert('crm_intentos_login', array(
            'usuario'   => $usuario,
            'ip'        => $ip,
            'exito'     => $exito ? 1 : 0,
            'creado_at' => date('Y-m-d H:i:s'),
        ));
    }

    /**
     * Cuenta intentos fallidos recientes por IP (defensa adicional).
     */
    public function contar_intentos_fallidos_ip($ip, $minutos = 15)
    {
        $desde = date('Y-m-d H:i:s', time() - ((int) $minutos * 60));
        return (int) $this->db
            ->where('ip', $ip)
            ->where('exito', 0)
            ->where('creado_at >=', $desde)
            ->count_all_results('crm_intentos_login');
    }
}