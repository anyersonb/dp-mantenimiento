<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Este test venía del esqueleto de Laravel y afirmaba que `/` devuelve 200.
     * En esta aplicación `/` **no** sirve contenido: `routes/web.php:19` redirige
     * al panel. Estuvo rojo desde el primer día, lo que hacía imposible exigir
     * "suite verde" como gate de commit — el único fallo real quedaba escondido
     * detrás del fallo de siempre.
     *
     * Ahora afirma el comportamiento verdadero: la raíz redirige al panel.
     */
    public function test_the_root_redirects_to_the_admin_panel(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }
}
