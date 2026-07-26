<?php

namespace App\Console\Commands;

use App\Models\Machine;
use Illuminate\Console\Command;

/**
 * Reparación manual del estado del horómetro (hallazgos E6-01 y E6-02).
 *
 * Existe porque las máquinas que quedaron desalineadas ANTES de que el observer
 * recalculara en `updated`/`deleted` no se arreglan solas: hace falta poder
 * rehacer el cálculo a pedido.
 *
 * **Escribe solo con `--apply`.** El default es simulación, a propósito: en la
 * Etapa 06 un backfill diseñado sin este freno iba a sobrescribir cuatro valores
 * del PM Service Report verificados a mano (EX023 434, LD023 41, LD027 0,
 * PW009 202). El recálculo de hoy respeta el ancla, pero el freno se queda.
 */
class RecalculateMachineHours extends Command
{
    protected $signature = 'machines:recalculate-hours
                            {--machine= : id_code de una sola máquina}
                            {--apply : Escribe los cambios. Sin este flag solo simula}';

    protected $description = 'Rehace current_hours y remaining_hours desde el ancla y las lecturas sobrevivientes';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $idCode = $this->option('machine');

        $machines = Machine::query()
            ->when($idCode, fn ($q) => $q->where('id_code', $idCode))
            ->with('readings')
            ->orderBy('id_code')
            ->get();

        if ($machines->isEmpty()) {
            $this->error($idCode ? "No existe ninguna máquina con id_code '{$idCode}'." : 'No hay máquinas.');

            return self::FAILURE;
        }

        $this->line($apply
            ? '<fg=yellow>MODO ESCRITURA (--apply): los cambios se guardan.</>'
            : '<fg=green>SIMULACIÓN: no se escribe nada. Agregá --apply para guardar.</>');

        $filas = [];

        foreach ($machines as $machine) {
            $antes = [
                'current_hours' => $machine->current_hours,
                'current_hours_date' => optional($machine->current_hours_date)->toDateString(),
                'remaining_hours' => $machine->remaining_hours,
            ];

            if ($apply) {
                $machine->recalculateHoursFromReadings(allowLowering: true);
            }

            // La MISMA implementación en los dos modos: computeHoursFromReadings()
            // calcula sin guardar. No hay una copia de la fórmula acá.
            $calculado = $apply
                ? [
                    'current_hours' => $machine->current_hours,
                    'current_hours_date' => optional($machine->current_hours_date)->toDateString(),
                    'remaining_hours' => $machine->remaining_hours,
                ]
                : $machine->computeHoursFromReadings(allowLowering: true);

            $despues = [
                'current_hours' => $calculado['current_hours'],
                'current_hours_date' => $calculado['current_hours_date'] instanceof \DateTimeInterface
                    ? $calculado['current_hours_date']->format('Y-m-d')
                    : $calculado['current_hours_date'],
                'remaining_hours' => $calculado['remaining_hours'],
            ];

            if ($antes == $despues) {
                continue;
            }

            $filas[] = [
                $machine->id_code,
                $this->diff($antes['current_hours'], $despues['current_hours']),
                $this->diff($antes['current_hours_date'], $despues['current_hours_date']),
                $this->diff($antes['remaining_hours'], $despues['remaining_hours']),
                $machine->readings->count(),
            ];
        }

        if ($filas === []) {
            $this->info('Todas las máquinas ya están alineadas con sus lecturas. Nada que hacer.');

            return self::SUCCESS;
        }

        $this->table(['Máquina', 'current_hours', 'fecha', 'remaining_hours', 'lecturas'], $filas);
        $this->line(count($filas).' máquina(s) '.($apply ? 'actualizadas.' : 'cambiarían. Volvé a correr con --apply.'));

        return self::SUCCESS;
    }

    private function diff(mixed $antes, mixed $despues): string
    {
        $antes ??= '—';
        $despues ??= '—';

        return $antes == $despues ? (string) $antes : "{$antes} → {$despues}";
    }
}
