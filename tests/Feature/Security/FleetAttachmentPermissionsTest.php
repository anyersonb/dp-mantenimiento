<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\FleetAttachmentResource;
use App\Models\FleetAttachment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Módulo de Complementos (Attachments) — las SEIS puertas de permisos, lado
 * "con permiso" y lado "sin permiso" por igual (ver spec-complementos-dp.md).
 *
 * `foreman` tiene `view_attachments` pero NO `manage_attachments` ni
 * `delete_attachments` (matriz de RolesAndPermissionsSeeder): sirve para
 * probar el reparto asimétrico sin depender de un usuario ad hoc.
 * `operador_cisterna` tampoco tiene ninguno de los dos, mismo perfil.
 */
class FleetAttachmentPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@dp.local')->firstOrFail();
    }

    private function responsable(): User
    {
        return User::where('email', 'responsable@dp.local')->firstOrFail();
    }

    private function foreman(): User
    {
        return User::where('email', 'foreman@dp.local')->firstOrFail();
    }

    private function attachment(): FleetAttachment
    {
        return FleetAttachment::create(['id_code' => 'PERM-'.random_int(100000, 999999), 'status' => 'active']);
    }

    /* ------------------------------------------------------------------ *
     * Lado CON permiso: administrador y responsable_mantenimiento operan.
     * ------------------------------------------------------------------ */

    public function test_administrator_passes_every_gate(): void
    {
        $attachment = $this->attachment();
        $this->actingAs($this->admin());

        $this->assertTrue(FleetAttachmentResource::canViewAny());
        $this->assertTrue(FleetAttachmentResource::canView($attachment));
        $this->assertTrue(FleetAttachmentResource::canCreate());
        $this->assertTrue(FleetAttachmentResource::canEdit($attachment));
        $this->assertTrue(FleetAttachmentResource::canDelete($attachment));
        $this->assertTrue(FleetAttachmentResource::canDeleteAny());
    }

    public function test_responsable_can_manage_but_a_view_only_role_cannot(): void
    {
        $attachment = $this->attachment();

        $this->actingAs($this->responsable());
        $this->assertTrue(FleetAttachmentResource::canViewAny());
        $this->assertTrue(FleetAttachmentResource::canCreate());
        $this->assertTrue(FleetAttachmentResource::canEdit($attachment));

        $this->actingAs($this->foreman());
        $this->assertTrue(FleetAttachmentResource::canViewAny());
        $this->assertFalse(FleetAttachmentResource::canCreate());
        $this->assertFalse(FleetAttachmentResource::canEdit($attachment));
    }

    /**
     * `delete_attachments` es exclusivo de administrador (mismo reparto que
     * `delete_machines`): ni siquiera responsable_mantenimiento, que sí
     * gestiona, puede borrar.
     */
    public function test_only_the_administrator_can_delete(): void
    {
        $attachment = $this->attachment();

        $this->actingAs($this->admin());
        $this->assertTrue(FleetAttachmentResource::canDelete($attachment));

        $this->actingAs($this->responsable());
        $this->assertFalse(FleetAttachmentResource::canDelete($attachment));
        $this->assertFalse(FleetAttachmentResource::canDeleteAny());
    }

    /* ------------------------------------------------------------------ *
     * Lado SIN permiso: el menú no aparece y la URL directa da 403.
     * ------------------------------------------------------------------ */

    /**
     * operador_cisterna es un rol de campo con `view_fleet` pero sin
     * `manage_machines`: mismo reparto exacto se aplica a `view_attachments`
     * (lo ve, pero no lo gestiona) — confirma que el reparto nuevo siguió la
     * matriz existente y no un criterio distinto para este rol puntual.
     */
    public function test_a_field_role_can_view_attachments_but_cannot_manage_them(): void
    {
        $operador = User::where('email', 'combustible@dp.local')->firstOrFail();
        $this->actingAs($operador);

        $this->assertTrue(FleetAttachmentResource::canViewAny());
        $this->assertFalse(FleetAttachmentResource::canCreate());
    }

    /**
     * Rol de control: entra al panel (`access_panel`) pero no tiene
     * `view_attachments`. Sirve para los tres tests de abajo (menú, URL
     * directa, y el contraste con quien sí tiene el permiso).
     */
    private function userWithoutAttachmentsAccess(): User
    {
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'sin_attachments']);
        $role->syncPermissions(['access_panel']);

        $user = User::factory()->create(['active' => true]);
        $user->assignRole('sin_attachments');

        return $user;
    }

    public function test_a_role_without_view_attachments_gets_a_403_on_direct_url_access(): void
    {
        $user = $this->userWithoutAttachmentsAccess();
        $this->assertFalse($user->can('view_attachments'));

        $this->actingAs($user)->get('/admin/fleet-attachments')->assertForbidden();
    }

    public function test_a_role_with_view_attachments_gets_a_200_on_direct_url_access(): void
    {
        $this->actingAs($this->admin())->get('/admin/fleet-attachments')->assertOk();
    }

    /**
     * `registerNavigationItems()` (Filament\Resources\Resource) solo agrega
     * el ítem del menú si `canAccess()` (= `canViewAny()` por defecto, sin
     * override en este Resource) es true — `shouldRegisterNavigation()` es
     * una propiedad estática aparte que no depende del permiso, así que
     * probar esa no probaría nada del gate real.
     */
    public function test_the_navigation_gate_is_closed_without_the_permission_and_open_with_it(): void
    {
        $this->actingAs($this->userWithoutAttachmentsAccess());
        $this->assertFalse(FleetAttachmentResource::canAccess());

        $this->actingAs($this->admin());
        $this->assertTrue(FleetAttachmentResource::canAccess());
    }

    /**
     * `manage_attachments` gobierna crear/editar; probar el bypass directo a
     * las URL de creación/edición sin ese permiso, no solo el can*() estático.
     */
    public function test_create_and_edit_urls_are_forbidden_without_manage_attachments(): void
    {
        $attachment = $this->attachment();

        // foreman tiene view_attachments pero no manage_attachments.
        $this->actingAs($this->foreman())->get('/admin/fleet-attachments/create')->assertForbidden();
        $this->actingAs($this->foreman())->get("/admin/fleet-attachments/{$attachment->id}/edit")->assertForbidden();
    }
}
