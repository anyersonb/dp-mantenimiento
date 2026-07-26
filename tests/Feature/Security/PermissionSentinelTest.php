<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\File;
use Livewire\Component as LivewireComponent;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Test centinela — Etapa 05, Bloque 2.
 *
 * Nace del hallazgo C1: WorkOrderResource se quedó sin ningún control de
 * permisos porque el fix de flota del 21/07 tocó Machine/Location/Make/etc.
 * y no barrió el resto del panel. Este test recorre TODOS los Filament
 * Resources y TODOS los componentes Livewire de escritura del proyecto y
 * falla si alguno no declara control de permisos explícito, para que la
 * próxima vez que se agregue un Resource o un componente de escritura el
 * hueco se note en la suite, no en una auditoría manual.
 *
 * OBLIGATORIO: no se saltea. Ver CLAUDE.md en la raíz del proyecto.
 *
 * Cómo correrlo solo:
 *   php artisan test tests/Feature/Security/PermissionSentinelTest.php
 */
class PermissionSentinelTest extends TestCase
{
    /**
     * Excepciones explícitas y justificadas. Vacío hoy: todo Resource con
     * página de creación/edición declara sus propios can*() (ver el fix de
     * C1 en WorkOrderResource y QuoteResource), y los tres componentes
     * Livewire de campo (ForemanBoard, FuelLog, ReportForm) sí tienen un
     * control de acceso (abort_unless + hasRole) aunque sea por ROL y no por
     * PERMISO — ese es un hallazgo aparte (A1/C2/C3 del informe QA), no
     * ausencia total de control, y está fuera de alcance del Bloque 2.
     *
     * Si algún día hace falta una excepción real, agregarla aquí con el
     * motivo, nunca en silencio.
     *
     * @var array<string, string>
     */
    private const RESOURCE_EXCEPTIONS = [];

    /** @var array<string, string> */
    private const LIVEWIRE_EXCEPTIONS = [];

    public function test_every_filament_resource_declares_its_own_permission_gates(): void
    {
        $failures = [];

        foreach ($this->discoverResourceClasses() as $class) {
            if (array_key_exists($class, self::RESOURCE_EXCEPTIONS)) {
                continue;
            }

            $failures = array_merge($failures, $this->auditResource($class));
        }

        $this->assertSame([], $failures, $this->formatFailures(
            'Resources de Filament sin control de permisos declarado',
            $failures
        ));
    }

    public function test_every_writing_livewire_component_declares_an_authorization_check(): void
    {
        $failures = [];

        foreach ($this->discoverLivewireWriteComponents() as $class => $methods) {
            if (array_key_exists($class, self::LIVEWIRE_EXCEPTIONS)) {
                continue;
            }

            if (! $this->classSourceHasAuthorizationMarker($class)) {
                $failures[] = sprintf(
                    '%s: declara escritura (%s) sin ningún control de acceso detectable '
                    .'(se esperaba ->can(...), hasRole(...)/hasAnyRole(...), Gate::, '
                    .'abort_unless(...)/abort_if(...) o ->authorize(...) en la clase). '
                    .'Agregá el control o, si es un caso justificado, sumalo a '
                    .'PermissionSentinelTest::LIVEWIRE_EXCEPTIONS con el motivo.',
                    $class,
                    implode(', ', $methods)
                );
            }
        }

        $this->assertSame([], $failures, $this->formatFailures(
            'Componentes Livewire de escritura sin control de acceso detectable',
            $failures
        ));
    }

    /**
     * @return array<int, string>
     */
    private function auditResource(string $class): array
    {
        $failures = [];

        if (! $this->methodOwnedByClass($class, 'canViewAny')) {
            $failures[] = sprintf(
                '%s: no declara canViewAny() propio — hereda el default abierto de '
                .'Filament\Resources\Resource (permite a cualquiera). Hallazgo C1.',
                $class
            );
        }

        /** @var array<string, mixed> $pages */
        $pages = $class::getPages();

        $required = [];
        if (array_key_exists('create', $pages)) {
            $required[] = 'canCreate';
        }
        if (array_key_exists('view', $pages)) {
            $required[] = 'canView';
        }
        if (array_key_exists('edit', $pages)) {
            $required = array_merge($required, ['canEdit', 'canDelete', 'canDeleteAny']);
        }

        foreach (array_unique($required) as $method) {
            if (! $this->methodOwnedByClass($class, $method)) {
                $failures[] = sprintf(
                    '%s: tiene página que requiere %s() pero no lo declara — hereda el '
                    .'default abierto de Filament\Resources\Resource. Hallazgo C1: '
                    .'agregá el override con el permiso de la matriz que corresponda.',
                    $class,
                    $method
                );
            }
        }

        return $failures;
    }

    private function methodOwnedByClass(string $class, string $method): bool
    {
        if (! method_exists($class, $method)) {
            return false;
        }

        return (new ReflectionMethod($class, $method))->getDeclaringClass()->getName() === $class;
    }

    /**
     * @return array<int, string>
     */
    private function discoverResourceClasses(): array
    {
        $classes = [];

        foreach (glob(app_path('Filament/Resources/*Resource.php')) as $file) {
            $classes[] = 'App\\Filament\\Resources\\'.basename($file, '.php');
        }

        sort($classes);

        return $classes;
    }

    /**
     * Componentes Livewire que definen al menos un método público de
     * escritura (save/create/store/update/delete/destroy). Devuelve
     * [FQCN => [métodos encontrados]].
     *
     * @return array<string, array<int, string>>
     */
    private function discoverLivewireWriteComponents(): array
    {
        $found = [];

        if (! File::isDirectory(app_path('Livewire'))) {
            return $found;
        }

        foreach (File::allFiles(app_path('Livewire')) as $file) {
            $relative = str_replace(['/', '\\'], '\\', $file->getRelativePathname());
            $class = 'App\\Livewire\\'.substr($relative, 0, -4); // quita ".php"

            if (! class_exists($class)) {
                continue;
            }

            $ref = new ReflectionClass($class);

            if (! $ref->isSubclassOf(LivewireComponent::class) || $ref->isAbstract()) {
                continue;
            }

            $writeMethods = [];
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                if (preg_match('/^(save|create|store|update|delete|destroy)$/i', $method->getName())) {
                    $writeMethods[] = $method->getName();
                }
            }

            if ($writeMethods !== []) {
                $found[$class] = $writeMethods;
            }
        }

        ksort($found);

        return $found;
    }

    private function classSourceHasAuthorizationMarker(string $class): bool
    {
        $ref = new ReflectionClass($class);
        $path = $ref->getFileName();

        if ($path === false || ! File::exists($path)) {
            return false;
        }

        // Se descartan comentarios antes de buscar: un comentario que solo
        // MENCIONE can()/hasRole()/abort_unless() (como los que dejamos al
        // documentar hallazgos) no cuenta como control real. Sin este paso el
        // propio QaTempWriter de la prueba de fallo colaba como "protegido".
        return (bool) preg_match(
            '/(->can\(|hasRole\(|hasAnyRole\(|Gate::|abort_unless\(|abort_if\(|->authorize\()/',
            $this->stripComments(File::get($path))
        );
    }

    private function stripComments(string $source): string
    {
        $stripped = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $stripped .= is_array($token) ? $token[1] : $token;
        }

        return $stripped;
    }

    /**
     * @param  array<int, string>  $failures
     */
    private function formatFailures(string $title, array $failures): string
    {
        if ($failures === []) {
            return '';
        }

        return $title.":\n - ".implode("\n - ", $failures);
    }
}
