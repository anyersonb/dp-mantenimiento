<?php

namespace Tests\Feature\Fleet;

use App\Filament\Resources\FleetAttachmentResource\Pages\CreateFleetAttachment;
use App\Filament\Resources\FleetAttachmentResource\Pages\EditFleetAttachment;
use App\Filament\Resources\FleetAttachmentResource\Pages\ListFleetAttachments;
use App\Filament\Resources\FleetAttachmentResource\Pages\ViewFleetAttachment;
use App\Models\FleetAttachment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CRUD del módulo de Complementos (Attachments) — ver spec-complementos-dp.md.
 * Cubre las cuatro páginas de Filament (list/create/view/edit) y la unicidad
 * de `id_code`, el campo que el cliente pidió explícitamente por nombre.
 */
class FleetAttachmentCrudTest extends TestCase
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

    public function test_the_list_page_renders_for_an_authorized_user(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ListFleetAttachments::class)
            ->assertSuccessful();
    }

    public function test_the_list_page_shows_created_records(): void
    {
        $attachment = FleetAttachment::create(['id_code' => 'BKT-LIST', 'status' => 'active']);

        Livewire::actingAs($this->admin())
            ->test(ListFleetAttachments::class)
            ->assertCanSeeTableRecords([$attachment]);
    }

    public function test_an_administrator_can_create_an_attachment_through_the_form(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateFleetAttachment::class)
            ->fillForm([
                'id_code' => 'BKT-001',
                'name' => 'Cucharón de 1.5 yardas',
                'type' => 'bucket',
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('fleet_attachments', [
            'id_code' => 'BKT-001',
            'type' => 'bucket',
            'status' => 'active',
        ]);
    }

    public function test_a_duplicate_id_code_is_rejected_on_create(): void
    {
        FleetAttachment::create(['id_code' => 'BKT-DUP', 'status' => 'active']);

        Livewire::actingAs($this->admin())
            ->test(CreateFleetAttachment::class)
            ->fillForm(['id_code' => 'BKT-DUP', 'status' => 'active'])
            ->call('create')
            ->assertHasFormErrors(['id_code' => 'unique']);

        $this->assertSame(1, FleetAttachment::where('id_code', 'BKT-DUP')->count());
    }

    public function test_a_duplicate_id_code_is_rejected_on_edit_against_another_record(): void
    {
        FleetAttachment::create(['id_code' => 'BKT-A', 'status' => 'active']);
        $b = FleetAttachment::create(['id_code' => 'BKT-B', 'status' => 'active']);

        Livewire::actingAs($this->admin())
            ->test(EditFleetAttachment::class, ['record' => $b->getKey()])
            ->fillForm(['id_code' => 'BKT-A'])
            ->call('save')
            ->assertHasFormErrors(['id_code' => 'unique']);

        $this->assertSame('BKT-B', $b->refresh()->id_code);
    }

    public function test_editing_the_same_record_with_its_own_id_code_is_allowed(): void
    {
        $attachment = FleetAttachment::create(['id_code' => 'BKT-SELF', 'status' => 'active']);

        Livewire::actingAs($this->admin())
            ->test(EditFleetAttachment::class, ['record' => $attachment->getKey()])
            ->fillForm(['id_code' => 'BKT-SELF', 'name' => 'Actualizado'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Actualizado', $attachment->refresh()->name);
    }

    public function test_the_view_page_renders_for_an_authorized_user(): void
    {
        $attachment = FleetAttachment::create(['id_code' => 'BKT-VIEW', 'status' => 'active']);

        Livewire::actingAs($this->admin())
            ->test(ViewFleetAttachment::class, ['record' => $attachment->getKey()])
            ->assertSuccessful();
    }
}
