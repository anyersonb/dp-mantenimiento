<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\FieldReport;
use App\Models\HorometerReading;
use App\Models\Machine;
use App\Models\MachinePart;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * Etapa 06 — prerrequisito para las sesiones de prueba: limpia datos QA sin
 * depender de que alguien corra SQL a mano. Nace de dos tropiezos reales
 * documentados en CLAUDE.md ("Notas de esquema"):
 *
 * 1. `machines` no tiene `code`, tiene `id_code` — un DELETE con la columna
 *    equivocada da "Unknown column" o, peor, dentro de un WHERE compuesto
 *    puede no matchear nada y parecer "ok" sin borrar.
 * 2. Un DELETE de usuario hecho por SQL a mano deja la fila huérfana en
 *    `model_has_roles` e infla el conteo de usuarios del rol en el listado
 *    de Roles y permisos. Por eso acá se usa `$user->roles()->detach()`
 *    antes de borrar, no un DELETE crudo.
 *
 * Orden de borrado (obligatorio por las FK): field_reports ->
 * horometer_readings -> work_order_attachments (archivo + fila) -> work
 * orders de prueba -> alerts/machine_parts de esas máquinas (barrido extra,
 * ver nota abajo) -> machines. Todo filtrado por `id_code LIKE 'QA-%'` para
 * lo que cuelga de máquinas; las OT de prueba también entran por su propio
 * marcador (`code`/`description` que empiece con "QA-").
 *
 * Nota sobre alerts/machine_parts: sus FK a `machines.id` ya tienen
 * `cascadeOnDelete()` (ver migraciones), así que MySQL las borraría solas
 * al borrar la máquina. Este comando las borra explícitamente ANTES igual,
 * para poder reportar un conteo exacto de qué se limpió en esta corrida en
 * vez de confiar en un cascade silencioso.
 */
class QaCleanup extends Command
{
    protected $signature = 'qa:cleanup {--dry-run : Muestra qué se borraría, sin borrar nada}';

    protected $description = 'Limpia datos de prueba (marcador QA-) antes de una sesión de pruebas, en el orden que exigen las FK.';

    /**
     * Línea base conocida del entorno (ver CLAUDE.md / brief de Etapa 06).
     * Sirve solo para el reporte de cierre, no condiciona qué se borra.
     *
     * @var array<string, int>
     */
    private const BASELINE = [
        'machines' => 99,
        'needs_review' => 35,
        'work_orders' => 1,
        // 5 -> 6: EX027 cruzó el umbral de servicio al cargar el PM report del
        // 24/07/2026 y su alerta es legítima (hallazgo E6-15).
        'alerts' => 6,
        // 62 -> 63: el reporte del 24/07 dejó ancla verificada en una máquina
        // que no la tenía.
        'anchors' => 63,
        'users' => 7,
        // 93 -> 121: las 28 lecturas del PM report del 24/07/2026.
        'horometer_readings' => 121,
        'field_reports' => 0,
        'work_order_attachments' => 0,
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun
            ? 'qa:cleanup --dry-run — no se borra nada, solo se reporta qué se borraría.'
            : 'qa:cleanup — borrando datos de prueba (marcador QA-)...');

        $machineIds = Machine::query()->where('id_code', 'like', 'QA-%')->pluck('id');

        $workOrderIds = WorkOrder::query()
            ->where(function ($query) use ($machineIds) {
                $query->where('code', 'like', 'QA-%')
                    ->orWhere('description', 'like', 'QA-%')
                    ->orWhereIn('machine_id', $machineIds);
            })
            ->pluck('id');

        $userIds = User::query()
            ->where('email', 'like', '%QA-%')
            ->orWhere('name', 'like', '%QA-%')
            ->pluck('id');

        // 1. field_reports colgando de máquinas QA-
        $fieldReportsCount = $this->purge(
            FieldReport::query()->whereIn('machine_id', $machineIds),
            'field_reports',
            $dryRun
        );

        // 2. horometer_readings colgando de máquinas QA-
        $readingsCount = $this->purge(
            HorometerReading::query()->whereIn('machine_id', $machineIds),
            'horometer_readings',
            $dryRun
        );

        // 3. work_order_attachments de las OT de prueba: archivo en disco + fila.
        $attachmentsCount = $this->purgeAttachments($workOrderIds, $dryRun);

        // 4. Alerts y machine_parts de las máquinas QA- (ver nota de clase:
        //    cascadeOnDelete ya las cubriría, esto es para reportar exacto).
        $alertsCount = $this->purge(
            Alert::query()->whereIn('machine_id', $machineIds),
            'alerts',
            $dryRun
        );
        $partsCount = $this->purge(
            MachinePart::query()->whereIn('machine_id', $machineIds),
            'machine_parts',
            $dryRun
        );

        // 5. Las OT de prueba en sí.
        $workOrdersCount = $this->purge(
            WorkOrder::query()->whereIn('id', $workOrderIds),
            'work_orders',
            $dryRun
        );

        // 6. Las máquinas QA- en sí.
        $machinesCount = $this->purge(
            Machine::query()->whereIn('id', $machineIds),
            'machines',
            $dryRun
        );

        // 7. Usuarios QA- — hay que soltar el rol ANTES de borrar el usuario
        //    o la fila de model_has_roles queda huérfana (ya nos pasó).
        $usersCount = $this->purgeUsers($userIds, $dryRun);

        $this->newLine();
        $this->line(sprintf(
            '%s field_reports, %s horometer_readings, %s work_order_attachments, '
            .'%s alerts, %s machine_parts, %s work_orders, %s machines, %s usuarios %s.',
            $fieldReportsCount,
            $readingsCount,
            $attachmentsCount,
            $alertsCount,
            $partsCount,
            $workOrdersCount,
            $machinesCount,
            $usersCount,
            $dryRun ? 'se borrarían' : 'borrados'
        ));

        $this->reportClosingCounters();
        $this->reportOrphanedActivityLog();

        return self::SUCCESS;
    }

    /**
     * Borra (o solo cuenta, en dry-run) los registros de la query dada,
     * uno por uno vía Eloquent para que disparen sus eventos/observers
     * normalmente (igual que borrarlos desde el panel).
     */
    private function purge($query, string $label, bool $dryRun): int
    {
        $records = $query->get();
        $count = $records->count();

        if ($count === 0) {
            return 0;
        }

        if ($dryRun) {
            $this->line("  [dry-run] {$label}: {$count} fila(s) que se borrarían.");

            return $count;
        }

        foreach ($records as $record) {
            // forceDelete cuando el modelo usa SoftDeletes (Machine, desde
            // E6-05): un dato de prueba tiene que desaparecer de verdad, no
            // quedar como fila con `deleted_at` inflando la tabla y
            // descuadrando los contadores de la próxima corrida.
            method_exists($record, 'forceDelete') ? $record->forceDelete() : $record->delete();
        }

        $this->line("  {$label}: {$count} fila(s) borradas.");

        return $count;
    }

    /**
     * @param  Collection<int, int>  $workOrderIds
     */
    private function purgeAttachments($workOrderIds, bool $dryRun): int
    {
        $attachments = WorkOrderAttachment::query()->whereIn('work_order_id', $workOrderIds)->get();
        $count = $attachments->count();

        if ($count === 0) {
            return 0;
        }

        if ($dryRun) {
            $this->line("  [dry-run] work_order_attachments: {$count} fila(s) + archivo en disco que se borrarían.");

            return $count;
        }

        foreach ($attachments as $attachment) {
            if ($attachment->path && Storage::disk('local')->exists($attachment->path)) {
                Storage::disk('local')->delete($attachment->path);
            }

            $attachment->delete();
        }

        $this->line("  work_order_attachments: {$count} fila(s) + archivo borrados.");

        return $count;
    }

    /**
     * @param  Collection<int, int>  $userIds
     */
    private function purgeUsers($userIds, bool $dryRun): int
    {
        $users = User::query()->whereIn('id', $userIds)->get();
        $count = $users->count();

        if ($count === 0) {
            return 0;
        }

        if ($dryRun) {
            $this->line("  [dry-run] users: {$count} usuario(s) (+ su fila en model_has_roles) que se borrarían.");

            return $count;
        }

        foreach ($users as $user) {
            // Soltar el rol ANTES de borrar: un DELETE del usuario sin esto
            // deja la fila huérfana en model_has_roles e infla el conteo de
            // usuarios por rol en el panel (Roles y permisos).
            $user->roles()->detach();
            $user->delete();
        }

        $this->line("  users: {$count} usuario(s) + su(s) fila(s) de model_has_roles borrados.");

        return $count;
    }

    private function reportClosingCounters(): void
    {
        $current = [
            'machines' => Machine::count(),
            'needs_review' => Machine::where('needs_review', true)->count(),
            'work_orders' => WorkOrder::count(),
            'alerts' => Alert::count(),
            'anchors' => Machine::whereNotNull('remaining_anchor_hours')->whereNotNull('remaining_anchor_at_hours')->count(),
            'users' => User::count(),
            'horometer_readings' => HorometerReading::count(),
            'field_reports' => FieldReport::count(),
            'work_order_attachments' => WorkOrderAttachment::count(),
        ];

        $labels = [
            'machines' => 'máquinas',
            'needs_review' => 'needs_review',
            'work_orders' => 'OT',
            'alerts' => 'alertas',
            'anchors' => 'anclas',
            'users' => 'usuarios',
            'horometer_readings' => 'lecturas',
            'field_reports' => 'reportes de campo',
            'work_order_attachments' => 'adjuntos de OT',
        ];

        $this->newLine();
        $this->line('Contadores de cierre vs. línea base:');

        $matchesAll = true;

        foreach (self::BASELINE as $key => $expected) {
            $actual = $current[$key];
            $matches = $actual === $expected;
            $matchesAll = $matchesAll && $matches;

            $this->line(sprintf(
                '  %s: %d (línea base %d) %s',
                $labels[$key],
                $actual,
                $expected,
                $matches ? 'OK' : '<-- DIFIERE'
            ));
        }

        $this->newLine();
        $this->line($matchesAll
            ? 'Coincide con la línea base conocida (99 máquinas · 35 needs_review · 1 OT · 6 alertas · 63 anclas · 7 usuarios · 121 lecturas · 0 reportes de campo · 0 adjuntos de OT).'
            : 'NO coincide con la línea base — revisar las filas marcadas "<-- DIFIERE" arriba.');
    }

    /**
     * `activity_log` es append-only: se declara la deriva, nunca se borra
     * (ver CLAUDE.md, "Notas de esquema").
     */
    private function reportOrphanedActivityLog(): void
    {
        $orphanedMachineLogs = Activity::query()
            ->where('subject_type', Machine::class)
            ->whereNotIn('subject_id', Machine::query()->pluck('id'))
            ->count();

        $orphanedWorkOrderLogs = Activity::query()
            ->where('subject_type', WorkOrder::class)
            ->whereNotIn('subject_id', WorkOrder::query()->pluck('id'))
            ->count();

        $total = $orphanedMachineLogs + $orphanedWorkOrderLogs;

        $this->newLine();
        if ($total === 0) {
            $this->line('activity_log: 0 asientos huérfanos (todo subject_id sigue existiendo). No se toca la bitácora.');

            return;
        }

        $this->line(sprintf(
            'activity_log: %d asiento(s) huérfano(s) detectado(s) — %d de Machine, %d de WorkOrder. '
            .'NO se borran (bitácora append-only): quedan como referencia histórica de datos ya eliminados.',
            $total,
            $orphanedMachineLogs,
            $orphanedWorkOrderLogs
        ));
    }
}
