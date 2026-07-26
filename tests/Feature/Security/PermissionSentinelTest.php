<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
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

    /** @var array<string, string> */
    private const RELATION_MANAGER_EXCEPTIONS = [];

    /**
     * Acciones de Filament que ESCRIBEN. La clave es el nombre corto tal como
     * aparece en el código; el valor, el método can*() del que Filament toma
     * la autorización cuando la acción es estándar. null = acción propia
     * (Action/BulkAction), que no tiene ningún can*() del que heredar.
     *
     * @var array<string, string|null>
     */
    private const WRITE_ACTIONS = [
        'CreateAction' => 'canCreate',
        'EditAction' => 'canEdit',
        'DeleteAction' => 'canDelete',
        'DeleteBulkAction' => 'canDeleteAny',
        'ForceDeleteAction' => 'canForceDelete',
        'ForceDeleteBulkAction' => 'canForceDeleteAny',
        'RestoreAction' => 'canRestore',
        'RestoreBulkAction' => 'canRestoreAny',
        'ReplicateAction' => 'canReplicate',
        'AttachAction' => 'canAttach',
        'DetachAction' => 'canDetach',
        'DetachBulkAction' => 'canDetachAny',
        'AssociateAction' => 'canAssociate',
        'DissociateAction' => 'canDissociate',
        'DissociateBulkAction' => 'canDissociateAny',
        'Action' => null,
        'BulkAction' => null,
        'ImportAction' => null,
        'ExportAction' => null,
    ];

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
     * Tercera aparición del mismo patrón (ver CLAUDE.md → "Lección de método").
     *
     * Los RelationManagers declaran CreateAction/EditAction/DeleteAction y
     * Filament los autoriza con $this->can('create'|'update'|'delete'), que
     * termina en Filament\authorize(...). Ese helper, cuando NO existe una
     * Policy para el modelo relacionado, devuelve Response::allow(). Este
     * proyecto no tiene app/Policies. Conclusión: en un RelationManager la
     * autorización "heredada" no existe, es permiso abierto para cualquiera
     * que alcance la página del Resource dueño.
     *
     * Asimetría que hizo invisible el hueco: las Pages de Resource SÍ heredan
     * de verdad, porque Filament les inyecta ->authorize($resource::canX())
     * en configureCreateAction()/configureEditAction()/etc.
     */
    public function test_every_relation_manager_protects_its_write_actions(): void
    {
        $failures = [];

        foreach ($this->discoverRelationManagerClasses() as $class) {
            if (array_key_exists($class, self::RELATION_MANAGER_EXCEPTIONS)) {
                continue;
            }

            foreach ($this->writeActionChains($class) as $action) {
                if ($action['authorized']) {
                    continue;
                }

                $inherited = self::WRITE_ACTIONS[$action['name']] ?? null;

                if ($inherited !== null && $this->canOverrideHasPermissionMarker($class, $inherited)) {
                    continue;
                }

                $failures[] = sprintf(
                    '%s:%d — %s sin control de permisos. %s Agregá ->authorize(fn () => '
                    .'Auth::user()?->can(<permiso de la matriz>) ?? false) a la acción, o '
                    .'sobreescribí %s() en el RelationManager con el permiso que corresponda.',
                    str_replace('App\\Filament\\', '', $class),
                    $action['line'],
                    $action['name'],
                    $inherited === null
                        ? 'Es una acción propia: no hay ningún can*() que pueda heredar.'
                        : sprintf(
                            'No sobreescribe %s(), y lo que hereda pasa por una Policy que no '
                            .'existe (Filament\authorize() devuelve allow() sin Policy).',
                            $inherited
                        ),
                    $inherited ?? 'el can*() correspondiente'
                );
            }
        }

        $this->assertSame([], $failures, $this->formatFailures(
            'RelationManagers con acciones de escritura sin control de permisos',
            $failures
        ));
    }

    /**
     * Hace explícito el supuesto del test anterior: mientras no haya Policy
     * para el modelo relacionado, "heredado" significa "abierto". Si algún día
     * se agregan Policies, este test se pone verde solo y el anterior deja de
     * exigir overrides.
     */
    public function test_no_relation_manager_relies_on_a_policy_that_does_not_exist(): void
    {
        $findings = [];

        foreach ($this->discoverRelationManagerClasses() as $class) {
            $related = $this->relatedModelFor($class);

            if ($related === null) {
                continue;
            }

            if (Gate::getPolicyFor($related) !== null) {
                continue;
            }

            $unprotected = array_filter(
                $this->writeActionChains($class),
                fn (array $action): bool => ! $action['authorized']
                    && ! $this->canOverrideHasPermissionMarker(
                        $class,
                        self::WRITE_ACTIONS[$action['name']] ?? 'canCreate'
                    )
            );

            if ($unprotected !== []) {
                $findings[] = sprintf(
                    '%s: %s no tiene Policy y el RelationManager deja %d acción(es) de '
                    .'escritura a la autorización heredada, que sin Policy es allow().',
                    str_replace('App\\Filament\\', '', $class),
                    class_basename($related),
                    count($unprotected)
                );
            }
        }

        $this->assertSame([], $findings, $this->formatFailures(
            'Autorización "heredada" que en realidad es permiso abierto',
            $findings
        ));
    }

    /**
     * Barrido del resto del panel: cualquier Action propia declarada fuera de
     * un *Resource.php (Pages, Widgets, componentes) tiene que traer su propio
     * control. Las acciones estándar dentro de Pages de Resource quedan
     * exentas porque Filament les inyecta el ->authorize() del Resource.
     */
    public function test_every_custom_write_action_outside_a_resource_is_authorized(): void
    {
        $failures = [];

        foreach ($this->discoverActionDeclaringFiles() as $file) {
            foreach ($this->writeActionChainsInFile($file) as $action) {
                if ($action['authorized']) {
                    continue;
                }

                // Acción estándar en una Page de Resource: Filament le inyecta
                // ->authorize($resource::canX()) en configureXAction().
                if ((self::WRITE_ACTIONS[$action['name']] ?? null) !== null) {
                    continue;
                }

                $failures[] = sprintf(
                    '%s:%d — %s::make(\'%s\') ejecuta una acción sin ->authorize() ni '
                    .'->visible() con permiso. Está fuera de un Resource: no hay can*() '
                    .'que pueda heredar.',
                    str_replace(base_path().DIRECTORY_SEPARATOR, '', $file),
                    $action['line'],
                    $action['name'],
                    $action['label']
                );
            }
        }

        $this->assertSame([], $failures, $this->formatFailures(
            'Acciones propias de escritura sin control de permisos fuera de un Resource',
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

    /**
     * @return array<int, string>
     */
    private function discoverRelationManagerClasses(): array
    {
        $classes = [];

        foreach (glob(app_path('Filament/Resources/*/RelationManagers/*.php')) as $file) {
            $resource = basename(dirname($file, 2));
            $class = 'App\\Filament\\Resources\\'.$resource.'\\RelationManagers\\'.basename($file, '.php');

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * Archivos que pueden declarar Actions fuera de un *Resource.php raíz:
     * Pages y Widgets del panel, y componentes Livewire propios. Los
     * RelationManagers quedan afuera porque tienen su propio test, para no
     * reportar el mismo hueco dos veces.
     *
     * @return array<int, string>
     */
    private function discoverActionDeclaringFiles(): array
    {
        $files = [];

        foreach ([app_path('Filament'), app_path('Livewire')] as $directory) {
            if (! File::isDirectory($directory)) {
                continue;
            }

            foreach (File::allFiles($directory) as $file) {
                $path = $file->getPathname();
                $normalizedPath = str_replace('\\', '/', $path);

                if (preg_match('#/Filament/Resources/[A-Za-z]+Resource\.php$#', $normalizedPath)) {
                    continue;
                }

                if (str_contains($normalizedPath, '/RelationManagers/')) {
                    continue;
                }

                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<int, array{name: string, label: string, line: int, authorized: bool}>
     */
    private function writeActionChains(string $class): array
    {
        $path = (new ReflectionClass($class))->getFileName();

        return $path === false ? [] : $this->writeActionChainsInFile($path);
    }

    /**
     * Encuentra cada Action de escritura declarada en el archivo y decide si
     * su propia cadena de llamadas trae control de permisos.
     *
     * @return array<int, array{name: string, label: string, line: int, authorized: bool}>
     */
    private function writeActionChainsInFile(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $source = $this->normalizeForChainScan(File::get($path));
        $found = [];

        if (! preg_match_all('/Actions\\\\(\w*Action)::make\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($matches[1] as $index => [$name, $_]) {
            if (! array_key_exists($name, self::WRITE_ACTIONS)) {
                continue;
            }

            $offset = $matches[0][$index][1];
            $chain = $this->chainAt($source, $offset);

            // Una Action propia que no ejecuta nada (solo ->url() de lectura)
            // no es escritura. Se exige ->action() o ->form() para contarla.
            if (self::WRITE_ACTIONS[$name] === null && ! preg_match('/->(action|form|submit)\s*\(/', $chain)) {
                continue;
            }

            $found[] = [
                'name' => $name,
                'label' => $this->actionNameFrom(File::get($path), $offset, $source),
                'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                'authorized' => $this->chainIsAuthorized($chain),
            ];
        }

        return $found;
    }

    /**
     * Recorta la cadena de una sola Action: desde ::make( hasta la coma o el
     * cierre del array que la contiene, al nivel de anidamiento cero. Los
     * literales de texto ya vienen vaciados, así que ningún paréntesis dentro
     * de un mensaje puede desbalancear el conteo.
     */
    private function chainAt(string $source, int $start): string
    {
        $depth = 0;
        $length = strlen($source);

        for ($i = $start; $i < $length; $i++) {
            $character = $source[$i];

            if (in_array($character, ['(', '[', '{'], true)) {
                $depth++;

                continue;
            }

            if (in_array($character, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }

                $depth--;

                continue;
            }

            if ($depth === 0 && in_array($character, [',', ';'], true)) {
                return substr($source, $start, $i - $start);
            }
        }

        return substr($source, $start);
    }

    /**
     * Control válido = un envoltorio de autorización (->authorize(),
     * ->visible(), ->hidden()) que además consulta un permiso. En Filament v3
     * los tres son equivalentes para una Action: isHidden() cubre visible,
     * hidden y authorize, isDisabled() incluye isHidden(), y tanto
     * mountTableAction() como callMountedTableAction() cortan si isDisabled().
     * O sea: ->visible() en una Action SÍ es control de servidor (no así en
     * un campo de formulario o una columna).
     */
    private function chainIsAuthorized(string $chain): bool
    {
        if (! preg_match('/->(authorize|visible|hidden)\s*\(/', $chain, $match, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        $fromWrapper = substr($chain, $match[0][1]);

        return (bool) preg_match('/(->can\(|hasRole\(|hasAnyRole\(|Gate::|::allows\()/', $fromWrapper);
    }

    private function actionNameFrom(string $rawSource, int $offset, string $normalized): string
    {
        $chain = $this->chainAt($normalized, $offset);

        // El nombre real vive en el fuente sin vaciar: se ubica por línea.
        $line = substr_count(substr($normalized, 0, $offset), "\n");
        $rawLine = explode("\n", $rawSource)[$line] ?? '';

        if (preg_match('/::make\(\s*\'([^\']+)\'/', $rawLine, $match)) {
            return $match[1];
        }

        return preg_match('/->(action|form)\s*\(/', $chain) ? '(sin nombre)' : '';
    }

    /**
     * Vacía comentarios y literales de texto conservando los saltos de línea,
     * para que los números de línea del informe sigan siendo los del archivo.
     */
    private function normalizeForChainScan(string $source): string
    {
        $output = '';

        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                $output .= $token;

                continue;
            }

            [$id, $text] = $token;

            if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                $output .= str_repeat("\n", substr_count($text, "\n"));

                continue;
            }

            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $output .= "''".str_repeat("\n", substr_count($text, "\n"));

                continue;
            }

            $output .= $text;
        }

        return $output;
    }

    private function canOverrideHasPermissionMarker(string $class, string $method): bool
    {
        if (! $this->methodOwnedByClass($class, $method)) {
            return false;
        }

        $reflection = new ReflectionMethod($class, $method);
        $path = $reflection->getFileName();

        if ($path === false || ! File::exists($path)) {
            return false;
        }

        // normalizeForChainScan y no stripComments: hace falta que los números
        // de línea sigan alineados con el archivo para poder recortar el método.
        $lines = array_slice(
            explode("\n", $this->normalizeForChainScan(File::get($path))),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        );

        return (bool) preg_match(
            '/(->can\(|hasRole\(|hasAnyRole\(|Gate::|::allows\()/',
            implode("\n", $lines)
        );
    }

    private function relatedModelFor(string $class): ?string
    {
        $owner = preg_replace('/\\\\RelationManagers\\\\\w+$/', '', $class);

        if (! is_string($owner) || ! class_exists($owner) || ! method_exists($owner, 'getModel')) {
            return null;
        }

        $ownerModel = $owner::getModel();
        $relationship = $class::getRelationshipName();

        if (! class_exists($ownerModel) || ! method_exists($ownerModel, $relationship)) {
            return null;
        }

        return (new $ownerModel)->{$relationship}()->getRelated()::class;
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
