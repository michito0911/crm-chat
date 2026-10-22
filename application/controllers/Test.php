<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Test extends CI_Controller {

    public function index()
    {
        $this->load->library('bot_service');
        $this->load->model('crm_categoria_model');

        echo '<pre style="font-family:monospace">';

        echo "=== CATEGORÍAS ===\n";
        foreach ($this->crm_categoria_model->listar() as $c) {
            echo $c['id'] . ' - ' . $c['nombre'] . "\n";
        }

        echo "\n=== NORMALIZACIÓN ===\n";
        echo $this->bot_service->normalizar("¡Hola! ¿Cómo estás? Mi IMPRESORA no imprime 😩") . "\n";

        echo "\n=== CLASIFICACIÓN ===\n";
        $norm = $this->bot_service->normalizar('la impresora no imprime y da error de usb');
        print_r($this->bot_service->clasificar($norm));

        echo "\n=== ¿EN HORARIO? ===\n";
        echo $this->bot_service->esta_en_horario() ? 'SÍ' : 'NO';
    }
}