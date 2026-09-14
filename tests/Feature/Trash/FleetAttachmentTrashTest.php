<?php

namespace Tests\Feature\Trash;

use App\Filament\Resources\FleetAttachmentResource\Pages\ListFleetAttachments;
use App\Models\FleetAttachment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * Papelera del módulo de Complementos — mismo patrón que
 * `MachineTrashAuthorizationTest`/`MachineForceDeleteImpactTest`: borrar
 * manda a la papelera (soft delete), restaurar lo recupera, y el borrado
 * DEFINITIVO purga los archivos físicos (image/gallery/documents) del disco
 * —el complemento es independiente, así que a diferencia de Machine no hay
 * ningún hijo con FK que perder, solo sus propios archivos—.
 */
class FleetAttachmentTrashTest extends TestCase
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

    private function taller(): User
    {
        return User::where('email', 'taller@dp.local')->firstOrFail();
    }

    private function attachment(): FleetAttachment
    {
        return FleetAttachment::create(['id_code' => 'TRASH-'.random_int(100000, 999999), 'status' => 'active']);
    }

    /* ------------------------------------------------------------------ *
     * 1. Borrar -> papelera -> restaurar.
     * ------------------------------------------------------------------ */

    public function test_deleting_sends_the_attachment_to_the_trash_and_it_can_be_restored(): void
    {
        $attachment = $this->attachment();

        $attachment->delete();
        $this->assertSoftDeleted('fleet_attachments', ['id' => $attachment->id]);

        Livewire::actingAs($this->admin())
            ->test(ListFleetAttachments::class)
            ->filterTable('trashed', true)
            ->callTableAction('restore', $attachment)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('fleet_attachments', ['id' => $attachment->id, 'deleted_at' => null]);
    }

    /* ------------------------------------------------------------------ *
     * 2. Sin el permiso, ni el filtro ni las acciones están disponibles, y
     *    el bypass directo (callTableAction sin el botón) queda bloqueado.
     * ------------------------------------------------------------------ */

    public function test_only_the_administrator_passes_the_papelera_gates(): void
    {
        $attachment = $this->attachment();
        $attachment->delete();

        $this->actingAs($this->taller());
        $this->assertFalse(\App\Filament\Resources\FleetAttachmentResource::canRestore($attachment));
        $this->assertFalse(\App\Filament\Resources\FleetAttachmentResource::canForceDelete($attachment));

        $this->actingAs($this->admin());
        $this->assertTrue(\App\Filament\Resources\FleetAttachmentResource::canRestore($attachment));
        $this->assertTrue(\App\Filament\Resources\FleetAttachmentResource::canForceDelete($attachment));
    }

    public function test_the_trashed_filter_is_hidden_without_view_trash_permission(): void
    {
        Livewire::actingAs($this->taller())
            ->test(ListFleetAttachments::class)
            ->assertTableFilterHidden('trashed');

        Livewire::actingAs($this->admin())
            ->test(ListFleetAttachments::class)
            ->assertTableFilterVisible('trashed');
    }

    public function test_executing_restore_without_the_permission_does_not_restore_the_record(): void
    {
        $attachment = $this->attachment();
        $attachment->delete();

        $blocked = false;

        try {
            Livewire::actingAs($this->taller())
                ->test(ListFleetAttachments::class)
                ->callTableAction('restore', $attachment);
        } catch (AssertionFailedError) {
            $blocked = true;
        }

        $this->assertTrue($blocked, 'Filament debe impedir invocar "restore" sin el permiso restore_attachments.');
        $this->assertSoftDeleted('fleet_attachments', ['id' => $attachment->id]);
    }

    public function test_executing_force_delete_without_the_permission_does_not_delete_the_record(): void
    {
        $attachment = $this->attachment();
        $attachment->delete();

        $blocked = false;

        try {
            Livewire::actingAs($this->taller())
                ->test(ListFleetAttachments::class)
                ->callTableAction('forceDelete', $attachment);
        } catch (AssertionFailedError) {
            $blocked = true;
        }

        $this->assertTrue($blocked, 'Filament debe impedir invocar "forceDelete" sin el permiso force_delete_attachments.');
        $this->assertDatabaseHas('fleet_attachments', ['id' => $attachment->id]);
    }

    /* ------------------------------------------------------------------ *
     * 3. Borrado DEFINITIVO: purga image/gallery/documents del disco.
     * ------------------------------------------------------------------ */

    public function test_force_deleting_removes_its_image_gallery_and_document_files_from_disk(): void
    {
        // `documents` vive en disk('local') desde el fix del hallazgo Alto
        // (auditoría de seguridad post 01e6a24e); `image`/`gallery` se
        // quedan en disk('public') (decisión ya razonada en
        // MachineResource: son fotos, moverlas obligaría a un proxy
        // autenticado por cada miniatura).
        Storage::fake('public');
        Storage::fake('local');

        $imagePath = 'fleet-attachments/images/foto-principal.jpg';
        $galleryPath = 'fleet-attachments/gallery/foto-a.jpg';
        $documentPath = 'fleet-attachments/documents/manual.pdf';

        Storage::disk('public')->put($imagePath, 'contenido real de la foto principal');
        Storage::disk('public')->put($galleryPath, 'contenido real de la foto de galeria');
        Storage::disk('local')->put($documentPath, 'contenido real del manual en pdf');

        $attachment = $this->attachment();
        $attachment->forceFill([
            'image' => $imagePath,
            'gallery' => [$galleryPath],
            'documents' => [$documentPath],
        ])->save();

        $this->assertTrue(Storage::disk('public')->exists($imagePath));
        $this->assertTrue(Storage::disk('public')->exists($galleryPath));
        $this->assertTrue(Storage::disk('local')->exists($documentPath));

        $attachment->delete();
        $attachment = $attachment->fresh();

        Livewire::actingAs($this->admin())
            ->test(ListFleetAttachments::class)
            ->filterTable('trashed', true)
            ->callTableAction('forceDelete', $attachment)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('fleet_attachments', ['id' => $attachment->id]);

        $this->assertFalse(
            Storage::disk('public')->exists($imagePath),
            'El borrado definitivo tiene que borrar la imagen principal del disco.'
        );
        $this->assertFalse(
            Storage::disk('public')->exists($galleryPath),
            'El borrado definitivo tiene que borrar las fotos de galería del disco.'
        );
        $this->assertFalse(
            Storage::disk('local')->exists($documentPath),
            'El borrado definitivo tiene que borrar los documentos del disco.'
        );
    }

    /**
     * Control: un borrado SUAVE (papelera) no debe tocar ningún archivo.
     */
    public function test_a_soft_delete_does_not_touch_the_files(): void
    {
        Storage::fake('public');

        $imagePath = 'fleet-attachments/images/foto-control.jpg';
        Storage::disk('public')->put($imagePath, 'contenido real de control');

        $attachment = $this->attachment();
        $attachment->forceFill(['image' => $imagePath])->save();

        $attachment->delete();

        $this->assertTrue(
            Storage::disk('public')->exists($imagePath),
            'Un borrado SUAVE (papelera) no tiene que tocar el archivo físico.'
        );
    }
}
