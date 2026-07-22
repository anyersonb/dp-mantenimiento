<?php

namespace Database\Seeders;

use App\Models\ChecklistTemplate;
use Illuminate\Database\Seeder;

class ChecklistSeeder extends Seeder
{
    /**
     * Checklist ÚNICO para toda la maquinaria (confirmado por el cliente).
     *
     * Formato DVIR (Driver's Vehicle Inspection Report / DOT - J.J. Keller 685).
     * Semántica de negocio (ya validada en ChecklistResultsRelationManager):
     *   - "ok"    => sin novedad (equivale a casilla SIN marcar en el papel).
     *   - "alert" => ítem defectuoso (equivale a casilla MARCADA en el papel),
     *                requiere alert_detail.
     *   - "na"    => no aplica (p. ej. ítems de Trailer si la unidad no lleva remolque).
     */
    public function run(): void
    {
        $template = ChecklistTemplate::where('active', true)->first()
            ?? ChecklistTemplate::where('name', 'Preventive Maintenance Checklist')->first()
            ?? new ChecklistTemplate;

        $template->name = "Driver's Vehicle Inspection Report (DVIR)";
        $template->active = true;
        $template->save();

        // Garantiza que quede EXACTAMENTE un template activo en el sistema.
        ChecklistTemplate::where('id', '!=', $template->id)
            ->where('active', true)
            ->update(['active' => false]);

        // Limpia los ítems anteriores (checklist preventivo viejo) antes de sembrar el DVIR.
        $template->items()->delete();

        $items = [
            'Vehicle' => [
                'Air Compressor',
                'Air Lines',
                'Battery',
                'Belts and Hoses',
                'Body',
                'Brake Accessories',
                'Brakes - Parking',
                'Brakes - Service',
                'Clutch',
                'Coupling Devices',
                'Defroster/Heater',
                'Drive Line',
                'Engine',
                'Exhaust',
                'Fifth Wheel',
                'Fluid Levels',
                'Frame and Assembly',
                'Front Axle',
                'Fuel Tanks',
                'Horn',
                'Mirrors',
                'Muffler',
                'Oil Pressure',
                'Radiator',
                'Rear End',
                'Reflectors',
                'Starter',
                'Steering',
                'Suspension System',
                'Tire Chains',
                'Tires',
                'Transmission',
                'Trip Recorder',
                'Wheels and Rims',
                'Windows',
                'Windshield Wipers',
                'Other',
            ],
            'Lights' => [
                'Head/Stop',
                'Tail/Dash',
                'Turn Indicators',
                'Clearance/Marker',
            ],
            'Safety Equipment' => [
                'Fire Extinguisher',
                'Flags/Flares/Fusees',
                'Reflective Triangles',
                'Spare Bulbs and Fuses',
                'Spare Seal Beam',
            ],
            'Trailer' => [
                'Brake Connections',
                'Brakes',
                'Coupling Devices',
                'Coupling (King) Pin',
                'Doors',
                'Hitch',
                'Landing Gear',
                'Lights - All',
                'Reflectors/Reflective Tape',
                'Roof',
                'Suspension System',
                'Tarpaulin',
                'Tires',
                'Wheels and Rims',
                'Other',
            ],
        ];

        $sort = 0;
        foreach ($items as $section => $labels) {
            foreach ($labels as $label) {
                $template->items()->create([
                    'section' => $section,
                    'label' => $label,
                    'sort' => $sort++,
                ]);
            }
        }
    }
}
