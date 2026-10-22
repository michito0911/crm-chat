<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Helper del CRM Chat.
 */

if (!function_exists('crm_esc')) {
    /**
     * Escapa salida HTML (atajo de htmlspecialchars).
     */
    function crm_esc($str)
    {
        return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('crm_fecha_relativa')) {
    /**
     * Devuelve una etiqueta corta tipo "hace 5 min", "ayer 15:32", etc.
     */
    function crm_fecha_relativa($fecha)
    {
        if (empty($fecha)) {
            return '';
        }
        $ts = is_numeric($fecha) ? (int) $fecha : strtotime($fecha);
        if (!$ts) {
            return '';
        }
        $diff = time() - $ts;

        if ($diff < 60) {
            return 'hace un momento';
        }
        if ($diff < 3600) {
            $m = (int) floor($diff / 60);
            return 'hace ' . $m . ' min';
        }
        if ($diff < 86400) {
            $h = (int) floor($diff / 3600);
            return 'hace ' . $h . ' h';
        }

        $hoy   = date('Y-m-d');
        $ayer  = date('Y-m-d', strtotime('-1 day'));
        $fdia  = date('Y-m-d', $ts);

        if ($fdia === $hoy) {
            return 'hoy ' . date('H:i', $ts);
        }
        if ($fdia === $ayer) {
            return 'ayer ' . date('H:i', $ts);
        }
        if (date('Y', $ts) === date('Y')) {
            return date('d/m H:i', $ts);
        }
        return date('d/m/Y', $ts);
    }
}

if (!function_exists('crm_hora_corta')) {
    function crm_hora_corta($fecha)
    {
        if (empty($fecha)) {
            return '';
        }
        $ts = is_numeric($fecha) ? (int) $fecha : strtotime($fecha);
        return $ts ? date('H:i', $ts) : '';
    }
}

if (!function_exists('crm_recortar')) {
    /**
     * Recorta texto para vistas previas.
     */
    function crm_recortar($texto, $max = 60)
    {
        $texto = trim((string) $texto);
        $texto = preg_replace('/\s+/u', ' ', $texto);
        if (function_exists('mb_strlen') && mb_strlen($texto, 'UTF-8') > $max) {
            return mb_substr($texto, 0, $max - 1, 'UTF-8') . '…';
        }
        if (strlen($texto) > $max) {
            return substr($texto, 0, $max - 1) . '…';
        }
        return $texto;
    }
}

if (!function_exists('crm_estado_legible')) {
    function crm_estado_legible($estado)
    {
        $mapa = array(
            'abierta'     => 'Abierta',
            'en_atencion' => 'En atención',
            'cerrada'     => 'Cerrada',
        );
        return isset($mapa[$estado]) ? $mapa[$estado] : $estado;
    }
}

if (!function_exists('crm_color_estado')) {
    function crm_color_estado($estado)
    {
        $mapa = array(
            'abierta'     => 'primary',
            'en_atencion' => 'warning',
            'cerrada'     => 'secondary',
        );
        return isset($mapa[$estado]) ? $mapa[$estado] : 'secondary';
    }
}

if (!function_exists('crm_modo_legible')) {
    function crm_modo_legible($modo)
    {
        return ($modo === 'humano') ? 'Atención humana' : 'Bot';
    }
}

if (!function_exists('crm_archivo_url')) {
    /**
     * Genera la URL protegida para servir una imagen.
     * El controlador Crm_media verifica el token o la sesión.
     */
    function crm_archivo_url($archivo, $token = NULL)
    {
        $url = site_url('crm/media/' . rawurlencode($archivo));
        if ($token) {
            $url .= '?t=' . rawurlencode($token);
        }
        return $url;
    }
    if (!function_exists('crm_links_clicables')) {
    /**
     * Escapa HTML y convierte URLs http/https en enlaces clicables.
     * Seguro contra XSS porque escapa ANTES de crear los <a>.
     */
    function crm_links_clicables($texto)
    {
        $texto = (string) $texto;
        if ($texto === '') {
            return '';
        }
        // 1) Escapar todo
        $seguro = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');

        // 2) Convertir URLs http/https en enlaces.
        //    El patrón también captura los ya escapados (& → &amp;), así que
        //    los volvemos a codificar dentro del href.
        $patron = '~(?<!["\'=])(https?://[^\s<>"\']+)~iu';

        $seguro = preg_replace_callback($patron, function ($m) {
            $url_raw = $m[1];
            // La URL viene ya escapada por htmlspecialchars; la decodificamos
            // para usarla en el href y volvemos a escapar solo la parte visible.
            $url_real = html_entity_decode($url_raw, ENT_QUOTES, 'UTF-8');
            $url_href = htmlspecialchars($url_real, ENT_QUOTES, 'UTF-8');
            $url_txt  = htmlspecialchars($url_real, ENT_QUOTES, 'UTF-8');
            return '<a href="' . $url_href . '" target="_blank" rel="noopener noreferrer">' . $url_txt . '</a>';
        }, $seguro);

        // 3) Respetar saltos de línea
        return nl2br($seguro);
    }
}
}