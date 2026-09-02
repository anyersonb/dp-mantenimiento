<?php

namespace Tests\Feature\Management;

use App\Models\Machine;
use App\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hallazgo [MEDIO] de seguridad, 2026-09-01, sobre App\Models\Concerns\
 * HasManualOrder (modo prepend, usado por WorkOrder — ver su docblock):
 *
 *   1. El `increment('sort_order')` corría como una query suelta ANTES del
 *      INSERT real de Eloquent. Si el INSERT fallaba después (código
 *      duplicado, columna `code` es `unique`), el incremento ya se había
 *      aplicado a la tabla entera: una operación que nunca llegó a existir
 *      corrió el orden de todas las demás filas.
 *   2. Ese mismo `increment()` pisaba `updated_at` de TODAS las filas del
 *      alcance, sin que ningún evento se disparara (rompe cualquier
 *      auditoría o sincronización que confíe en esa columna).
 *
 * Este archivo cubre las dos, reemplazando los tests exploratorios que
 * seguridad dejó en el scratchpad (que solo observaban por fwrite(STDERR)
 * sin afirmar nada).
 */
class ManualOrderTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function machine(): Machine
    {
        return Machine::create([
            'id_code' => 'MOT-'.random_int(1000, 9999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_hours' => 500,
            'last_service_hours' => 100,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
        ]);
    }

    private function workOrder(Machine $machine, ?string $code = null): WorkOrder
    {
        return WorkOrder::create([
            'code' => $code ?? 'WO-'.random_int(10000, 99999),
            'machine_id' => $machine->id,
            'type' => 'corrective',
            'status' => 'open',
            'opened_at' => now()->toDateString(),
        ]);
    }

    public function test_a_failed_insert_does_not_leave_the_other_rows_incremented(): void
    {
        $machine = $this->machine();

        $a = $this->workOrder($machine, 'WO-DUP-TXN');
        $b = $this->workOrder($machine);
        $c = $this->workOrder($machine);

        $antes = WorkOrder::query()->orderBy('id')->pluck('sort_order', 'id')->all();

        try {
            // Código duplicado (WorkOrder.code es unique): el INSERT debe
            // fallar. Si el increment previo a este INSERT no comparte
            // transacción con él, las tres filas de arriba quedan corridas
            // igual pese a que esta fila nunca llegó a existir.
            $this->workOrder($machine, 'WO-DUP-TXN');
            $this->fail('se esperaba que el código duplicado hiciera fallar el INSERT — el test no es concluyente');
        } catch (QueryException) {
            // esperado
        }

        $despues = WorkOrder::query()->orderBy('id')->pluck('sort_order', 'id')->all();

        $this->assertSame($antes, $despues, 'el INSERT fallido dejó igual el orden de las filas que no cambiaron');
        $this->assertSame(3, WorkOrder::count(), 'no debe haber quedado ninguna fila del intento fallido');
    }

    public function test_the_prepend_increment_does_not_touch_updated_at_of_the_other_rows(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');

        $machine = $this->machine();
        $a = $this->workOrder($machine);
        $b = $this->workOrder($machine);

        // Strings, no objetos Carbon: dos instancias con el mismo instante
        // no son === entre sí, y eso haría fallar assertSame aunque la
        // fecha nunca se haya movido — falso defecto de medición, no del
        // código.
        $updatedAtAntes = WorkOrder::query()->orderBy('id')->pluck('updated_at', 'id')
            ->map(fn ($value) => (string) $value)->all();

        Carbon::setTestNow('2026-01-01 11:00:00');

        // Crea una tercera OT: dispara el prepend, que corre el sort_order
        // de $a y $b un puesto. Su updated_at NO debe moverse.
        $this->workOrder($machine);

        $updatedAtDespues = WorkOrder::query()->whereIn('id', [$a->id, $b->id])->orderBy('id')->pluck('updated_at', 'id')
            ->map(fn ($value) => (string) $value)->all();

        $this->assertSame(
            [$a->id => $updatedAtAntes[$a->id], $b->id => $updatedAtAntes[$b->id]],
            $updatedAtDespues,
            'el increment del prepend pisó updated_at de filas que nadie tocó'
        );

        Carbon::setTestNow();
    }

    public function test_the_increment_update_has_no_where_clause_touching_other_tables(): void
    {
        // Confirma el mecanismo exacto del fix: la query que corre el
        // sort_order es un UPDATE con una expresión sobre la misma columna,
        // no un increment() de Eloquent (que además de incrementar toca
        // updated_at). Si alguien revierte a increment(), este assert de
        // logging detecta el cambio de forma de la query.
        $machine = $this->machine();
        $this->workOrder($machine);

        DB::enableQueryLog();
        $this->workOrder($machine);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $update = collect($log)->first(
            fn (array $q): bool => str_contains(strtolower($q['query']), 'update')
                && str_contains(strtolower($q['query']), 'work_orders')
        );

        $this->assertNotNull($update, 'no se emitió ningún UPDATE al crear la OT en modo prepend');
        $this->assertStringNotContainsString(
            'updated_at',
            strtolower($update['query']),
            'el UPDATE del prepend sigue tocando updated_at'
        );
    }
}
