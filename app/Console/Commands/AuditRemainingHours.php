<?php

namespace App\Console\Commands;

use App\Models\Machine;
use Illuminate\Console\Command;

/**
 * Solo lectura: NO escribe nada. Previsualiza a qué máquinas tocaría el
 * backfill de remaining_anchor_* (regla-horometro.md) recalculando
 * remaining_hours según la regla nueva (sin ancla, porque el backfill aún no
 * corrió) y comparándolo contra el valor hoy guardado en la BD.
 *
 * Reporta como inconsistente cualquier máquina donde:
 *   - el valor actual es negativo,
 *   - el valor actual supera el intervalo de servicio,
 *   - last_service_hours quedó en una escala distinta a current_hours
 *     (horómetro reemplazado sin re-anclar), o
 *   - el valor actual no coincide con el que daría la regla nueva (incluye
 *     el caso del ajuste asimétrico de hours_adjustment y cualquier otro
 *     desalineamiento de datos).
 *
 * LD032 queda fuera del backfill por decisión del jefe (sec. 4 del spec),
 * pero no se excluye a mano aquí: sus números ya son coherentes con la regla
 * nueva, así que no aparece en el listado.
 */
class AuditRemainingHours extends Command
{
    protected $signature = 'horometer:audit-remaining';

    protected $description = 'Read-only: lists machines whose remaining_hours is inconsistent with the new anchor-and-discount rule.';

    public function handle(): int
    {
        $rows = [];

        Machine::query()
            ->select(['id', 'id_code', 'hourmeter_status', 'current_hours', 'last_service_hours', 'service_interval_hours', 'remaining_hours', 'hours_adjustment'])
            ->orderBy('id_code')
            ->chunkById(100, function ($machines) use (&$rows) {
                foreach ($machines as $machine) {
                    $current = $machine->remaining_hours;
                    $expected = $this->expectedRemaining($machine);
                    $reasons = $this->reasons($machine, $current, $expected);

                    if ($reasons === []) {
                        continue;
                    }

                    $rows[] = [
                        'id_code' => $machine->id_code,
                        'hourmeter_status' => $machine->hourmeter_status,
                        'current_hours' => $machine->current_hours,
                        'last_service_hours' => $machine->last_service_hours,
                        'hours_adjustment' => $machine->hours_adjustment,
                        'current_value' => $current ?? 'NULL',
                        'expected_value' => $expected ?? 'NULL',
                        'reason' => implode(', ', $reasons),
                    ];
                }
            });

        if ($rows === []) {
            $this->info('No se encontraron máquinas con remaining_hours inconsistente. 0 filas.');

            return self::SUCCESS;
        }

        $this->warn(count($rows).' máquina(s) con remaining_hours inconsistente según la regla nueva:');
        $this->table(
            ['id_code', 'hourmeter_status', 'current_hours', 'last_service_hours', 'hours_adjustment', 'valor actual', 'valor con regla nueva', 'motivo'],
            array_map(fn ($r) => [
                $r['id_code'], $r['hourmeter_status'], $r['current_hours'], $r['last_service_hours'],
                $r['hours_adjustment'], $r['current_value'], $r['expected_value'], $r['reason'],
            ], $rows)
        );

        return self::SUCCESS;
    }

    /**
     * Recalcula remaining_hours con la regla nueva (regla-horometro.md, sec.
     * 2.1), sin ancla (el backfill que la fijaría todavía no corrió).
     */
    private function expectedRemaining(Machine $machine): ?int
    {
        if (in_array($machine->hourmeter_status, ['broken', 'no_info'], true)) {
            return null;
        }

        if (
            $machine->last_service_hours !== null
            && $machine->current_hours !== null
            && $machine->last_service_hours <= $machine->current_hours
        ) {
            $used = $machine->current_hours - $machine->last_service_hours;

            return $machine->service_interval_hours - $used;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function reasons(Machine $machine, ?int $current, ?int $expected): array
    {
        $reasons = [];

        if ($current !== null && $current < 0) {
            $reasons[] = 'negativo';
        }

        if ($current !== null && $current > $machine->service_interval_hours) {
            $reasons[] = 'mayor_que_intervalo';
        }

        if (
            $machine->last_service_hours !== null
            && $machine->current_hours !== null
            && $machine->last_service_hours > $machine->current_hours
        ) {
            $reasons[] = 'escalas_distintas';
        }

        if ($current !== $expected) {
            $reasons[] = $machine->hours_adjustment !== 0 ? 'ajuste_asimetrico' : 'desalineado_con_regla_nueva';
        }

        return array_unique($reasons);
    }
}
