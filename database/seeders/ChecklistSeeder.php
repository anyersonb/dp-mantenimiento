<?php

namespace Database\Seeders;

use App\Models\ChecklistTemplate;
use Illuminate\Database\Seeder;

class ChecklistSeeder extends Seeder
{
    public function run(): void
    {
        // Checklist ÚNICO para toda la maquinaria (confirmado por el cliente).
        $template = ChecklistTemplate::firstOrCreate(
            ['name' => 'Preventive Maintenance Checklist'],
            ['active' => true]
        );

        $items = [
            'Engine' => [
                'Engine oil level & condition',
                'Coolant level & condition',
                'Belts tension & wear',
                'Hoses & connections (leaks)',
                'Air intake / turbo',
            ],
            'Filters' => [
                'Engine oil filter replaced',
                'Fuel filters (primary/secondary) replaced',
                'Air filter elements (inner/outer) checked',
                'Hydraulic filter checked/replaced',
                'Transmission filter checked',
            ],
            'Fluids & Lubrication' => [
                'Hydraulic oil level',
                'Transmission / final drive fluid',
                'Grease all fittings',
                'DEF level (if applicable)',
            ],
            'Undercarriage / Tires' => [
                'Tires / tracks condition & pressure',
                'Cutting edge / bucket wear',
                'Undercarriage bolts torque',
            ],
            'Electrical' => [
                'Battery & terminals',
                'Alternator / starter operation',
                'Lights & gauges',
                'Hour-meter reading recorded',
            ],
            'Safety & General' => [
                'Fire extinguisher present',
                'Backup alarm & horn',
                'Seatbelt & ROPS',
                'General visual inspection (structure/leaks)',
                'Decals & ID number legible',
            ],
        ];

        $sort = 0;
        foreach ($items as $section => $labels) {
            foreach ($labels as $label) {
                $template->items()->firstOrCreate(
                    ['section' => $section, 'label' => $label],
                    ['sort' => $sort++]
                );
            }
        }
    }
}
