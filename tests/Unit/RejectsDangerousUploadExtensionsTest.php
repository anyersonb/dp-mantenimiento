<?php

namespace Tests\Unit;

use App\Rules\RejectsDangerousUploadExtensions;
use Tests\TestCase;

/**
 * Etapa 05, Bloque 4 (auditoria A5, ruta de subida): la regla que blinda
 * los FileUpload de adjuntos de OT y cotizaciones. Se prueba directo sobre
 * la clase (no vía Livewire) para aislar la logica de deteccion de doble
 * extension y de path traversal del resto del stack de validacion.
 */
class RejectsDangerousUploadExtensionsTest extends TestCase
{
    private function failureCaptured(string $value, array $allowed = ['pdf', 'png', 'jpg', 'jpeg']): ?string
    {
        $rule = new RejectsDangerousUploadExtensions($allowed);
        $captured = null;

        $rule->validate('path', $value, function (string $message) use (&$captured) {
            $captured = $message;
        });

        return $captured;
    }

    public function test_a_legitimate_pdf_name_passes(): void
    {
        $this->assertNull($this->failureCaptured('invoice.pdf'));
    }

    public function test_a_name_with_accents_and_enie_passes(): void
    {
        $this->assertNull($this->failureCaptured('Informe_Mantenimiento_Peña_ñoño.pdf'));
    }

    public function test_double_extension_pdf_php_is_rejected(): void
    {
        $this->assertNotNull($this->failureCaptured('invoice.pdf.php'));
    }

    public function test_double_extension_in_the_other_order_is_also_rejected(): void
    {
        $this->assertNotNull($this->failureCaptured('invoice.php.pdf'));
    }

    public function test_an_extension_outside_the_whitelist_is_rejected(): void
    {
        $this->assertNotNull($this->failureCaptured('invoice.exe'));
    }

    public function test_a_filename_without_extension_is_rejected(): void
    {
        $this->assertNotNull($this->failureCaptured('invoice'));
    }

    /**
     * Path traversal: "../" en el nombre original se rechaza directo, sin
     * llegar siquiera a mirar la extension. No es la unica barrera (las
     * rutas de descarga sirven por id/modelo, nunca por un path recibido
     * del cliente, y Symfony ya reduce getClientOriginalName() a un
     * basename) pero cierra el caso de un string crudo llegando a esta
     * clase por cualquier otro camino.
     */
    public function test_a_path_traversal_attempt_is_rejected(): void
    {
        $this->assertNotNull($this->failureCaptured('../../etc/passwd.pdf'));
    }

    public function test_a_backslash_path_is_rejected(): void
    {
        $this->assertNotNull($this->failureCaptured('..\\..\\windows\\evil.pdf'));
    }
}
