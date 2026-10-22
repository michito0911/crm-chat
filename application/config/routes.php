<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/* =========================================================
 *  RUTAS CRM CHAT
 * ========================================================= */

$route['default_controller']   = 'chat';
$route['404_override']         = '';
$route['translate_uri_dashes'] = FALSE;

/* --- Chat público --- */
$route['chat']                 = 'chat/index';
$route['chat/iniciar']         = 'chat/iniciar';
$route['chat/conversacion']    = 'chat/conversacion';
$route['chat/mensajes']        = 'chat/mensajes';
$route['chat/enviar']          = 'chat/enviar';
$route['chat/subir_imagen']    = 'chat/subir_imagen';

/* --- Login / sesión admin --- */
$route['crm']                  = 'crm_auth/login';
$route['crm/login']            = 'crm_auth/login';
$route['crm/logout']           = 'crm_auth/logout';
$route['crm/cambiar_password'] = 'crm_auth/cambiar_password';

/* --- Panel del admin --- */
$route['crm/panel']                        = 'crm_panel/index';
$route['crm/panel/configuracion']          = 'crm_panel/configuracion';
$route['crm/panel/conversacion/(:num)']    = 'crm_panel/conversacion/$1';

/* --- API AJAX del panel --- */
$route['crm/api/no_leidos']            = 'crm_api/no_leidos';
$route['crm/api/conversaciones']       = 'crm_api/conversaciones';
$route['crm/api/mensajes/(:num)']      = 'crm_api/mensajes/$1';
$route['crm/api/enviar/(:num)']        = 'crm_api/enviar/$1';
$route['crm/api/estado/(:num)']        = 'crm_api/estado/$1';
$route['crm/api/categoria/(:num)']     = 'crm_api/categoria/$1';
$route['crm/api/modo/(:num)']          = 'crm_api/modo/$1';
$route['crm/api/nota/(:num)']          = 'crm_api/nota/$1';
$route['crm/api/categorias']           = 'crm_api/categorias';
$route['crm/api/categorias_guardar']   = 'crm_api/categorias_guardar';
$route['crm/api/categorias_eliminar']  = 'crm_api/categorias_eliminar';
$route['crm/api/palabras']             = 'crm_api/palabras';
$route['crm/api/palabras_guardar']     = 'crm_api/palabras_guardar';
$route['crm/api/palabras_eliminar']    = 'crm_api/palabras_eliminar';
$route['crm/api/respuestas']           = 'crm_api/respuestas';
$route['crm/api/respuestas_guardar']   = 'crm_api/respuestas_guardar';
$route['crm/api/respuestas_eliminar']  = 'crm_api/respuestas_eliminar';
$route['crm/api/configuracion']        = 'crm_api/configuracion';

/* --- Imágenes protegidas --- */
$route['crm/api/enviar_imagen/(:num)'] = 'crm_api/enviar_imagen/$1';

$route['crm/api/verificar/(:num)']            = 'crm_api/verificar/$1';
$route['crm/api/quitar_verificacion/(:num)']  = 'crm_api/quitar_verificacion/$1';
$route['crm/api/recursos_listar']             = 'crm_api/recursos_listar';
$route['crm/api/recursos_sugeridos/(:num)']   = 'crm_api/recursos_sugeridos/$1';
$route['crm/api/enviar_recursos/(:num)']      = 'crm_api/enviar_recursos/$1';
$route['crm/panel/recursos']                        = 'crm_panel/recursos';
$route['crm/api/recursos_admin_listar']             = 'crm_api/recursos_admin_listar';
$route['crm/api/recursos_admin_obtener/(:num)']     = 'crm_api/recursos_admin_obtener/$1';
$route['crm/api/recursos_admin_guardar']            = 'crm_api/recursos_admin_guardar';
$route['crm/api/recursos_admin_eliminar/(:num)']    = 'crm_api/recursos_admin_eliminar/$1';
$route['crm/api/recursos_admin_duplicar/(:num)']    = 'crm_api/recursos_admin_duplicar/$1';
$route['crm/api/recursos_admin_toggle/(:num)']      = 'crm_api/recursos_admin_toggle/$1';
$route['crm/api/recursos_admin_marcas']             = 'crm_api/recursos_admin_marcas';
$route['crm/api/recursos_admin_importar']           = 'crm_api/recursos_admin_importar';
$route['crm/api/recursos_admin_exportar_csv']       = 'crm_api/recursos_admin_exportar_csv';

$route['crm/api/rr_listar']        = 'crm_api/rr_listar';
$route['crm/api/rr_guardar']       = 'crm_api/rr_guardar';
$route['crm/api/rr_eliminar']      = 'crm_api/rr_eliminar';