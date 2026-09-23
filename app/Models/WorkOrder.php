<?php

namespace App\Models;

use App\Models\Concerns\HasManualOrder;
use App\Models\Concerns\LogsPapeleraActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class WorkOrder extends Model
{
    /**
     * SoftDeletes (Papelera, Lote A): mandar una OT a la papelera no debe
     * llevarse sus repuestos, adjuntos ni checklist, que son el respaldo de lo
     * que se hizo y de lo que se cobró. El FK `cascadeOnDelete()` de esas tres
     * tablas hacia `work_orders` NO se dispara con un soft delete —no hay
     * DELETE real—, así que sin la cascada lógica de más abajo esos hijos
     * quedarían visibles y huérfanos (con su OT dueña en la papelera).
     */
    use HasManualOrder, LogsActivity, LogsPapeleraActivity, SoftDeletes;

    public function papeleraLabel(): string
    {
        return (string) $this->code;
    }

    /**
     * Relaciones hijas que viajan CON la OT al mandarla a la papelera y
     * vuelven CON ella al restaurarla. `parts`/`attachments`/`checklistResults`
     * ya existen más abajo; se listan acá para que la cascada y la
     * restauración lean de una sola fuente.
     *
     * @return array<int, string>
     */
    public const TRASH_CASCADE_RELATIONS = ['parts', 'attachments', 'checklistResults'];

    /**
     * Propiedad REAL (no un atributo del modelo): declarada así a propósito
     * para que la asignación `$workOrder->pendingTrashBatch = ...` no pase
     * por el `__set` mágico de Eloquent, que la trataría como una columna y
     * la mandaría al próximo `save()`. Es un marcador de un solo request,
     * entre `restoring` y `restored`, y nunca se persiste bajo este nombre
     * (lo que SÍ se persiste es `trash_batch`, ver más abajo).
     */
    protected $pendingTrashBatch = null;

    protected static function booted(): void
    {
        static::deleted(function (WorkOrder $workOrder) {
            if ($workOrder->isForceDeleting()) {
                // La eliminación definitiva se resuelve aparte (ver
                // ForceDeleteAction en WorkOrderResource): los adjuntos
                // necesitan que se les borre el archivo físico ANTES de que
                // la cascada real de la base se lleve la fila.
                return;
            }

            /*
             * Cascada LÓGICA, marcada con un ULID propio (`trash_batch`) y NO
             * con el `deleted_at` de la OT.
             *
             * Primera versión: se comparaba por igualdad de `deleted_at`
             * entre la OT y sus hijos. Falló en la corrida de control contra
             * MySQL (invisible en SQLite): las columnas `datetime` de este
             * proyecto tienen precisión de UN SEGUNDO, así que un repuesto
             * borrado a mano y la OT borrada dentro del MISMO segundo de
             * prueba terminaban con el MISMO `deleted_at` — y al restaurar la
             * OT, ese repuesto que NO debía volver, volvía igual. Un ULID
             * generado por operación no colisiona nunca por esto.
             */
            $batch = (string) Str::ulid();

            $workOrder->newQueryWithoutScopes()
                ->whereKey($workOrder->getKey())
                ->update(['trash_batch' => $batch]);

            // El UPDATE de arriba es una consulta aparte (para no reentrar en
            // saving/deleting): el objeto en memoria no se entera solo. Se
            // refleja acá para que, DENTRO del mismo request, restore()
            // sobre esta misma instancia encuentre el batch recién escrito.
            $workOrder->setRawAttributes(
                array_merge($workOrder->getAttributes(), ['trash_batch' => $batch]),
                true
            );

            foreach (self::TRASH_CASCADE_RELATIONS as $relation) {
                $workOrder->{$relation}()
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $workOrder->getAttributes()['deleted_at'] ?? now(), 'trash_batch' => $batch]);
            }
        });

        static::restoring(function (WorkOrder $workOrder) {
            // El marcador hay que leerlo ACÁ: es la última cadena antes de
            // que la propia OT lo pierda (se sobreescribe en el próximo
            // borrado, no antes), así que capturarlo en `restoring` es
            // seguro: todavía es el batch de ESTA baja que se está deshaciendo.
            $workOrder->pendingTrashBatch = $workOrder->trash_batch;
        });

        static::restored(function (WorkOrder $workOrder) {
            $batch = $workOrder->pendingTrashBatch;
            $workOrder->pendingTrashBatch = null;

            if ($batch === null) {
                return;
            }

            // Solo vuelven los hijos marcados con ESE ULID exacto: un
            // repuesto que ya estaba borrado ANTES de que esta OT se fuera a
            // la papelera tiene `trash_batch` distinto (o null) y no matchea,
            // así que sigue en la papelera después de restaurar la OT.
            foreach (self::TRASH_CASCADE_RELATIONS as $relation) {
                $workOrder->{$relation}()
                    ->onlyTrashed()
                    ->where('trash_batch', $batch)
                    ->update(['deleted_at' => null, 'trash_batch' => null]);
            }
        });

        static::forceDeleting(function (WorkOrder $workOrder) {
            // Los adjuntos tienen archivo físico: hay que borrarlo ANTES de
            // que la cascada real de la base (`cascadeOnDelete()`) se lleve la
            // fila, sea cual sea su estado de papelera hoy.
            $workOrder->attachments()->withTrashed()->get()->each(
                fn (WorkOrderAttachment $attachment) => $attachment->forceDelete()
            );

            // Las líneas de repuestos y los resultados de checklist no tienen
            // archivo: se dejan a la cascada real de la base (cascadeOnDelete
            // SÍ se dispara con un DELETE de verdad).
        });
    }

    /**
     * Estados en los que la OT ya no admite cambios destructivos: el trabajo
     * terminó (o se canceló) y sus adjuntos, checklist y repuestos son el
     * respaldo de lo que se hizo y de lo que se cobró.
     *
     * Única definición de "cerrada" del sistema. Ver el trait
     * App\Filament\Concerns\DeletesOnlyWhileWorkOrderIsOpen.
     */
    public const CLOSED_STATUSES = ['completed', 'cancelled'];

    protected $guarded = [];

    /**
     * Prepend, no append: la OT nueva nace en sort_order=1 (arriba), corriendo
     * el resto. Es lo que hace que "la más nueva arriba" sobreviva al mismo
     * orden ascendente que Filament fuerza en modo arrastrar — ver el
     * docblock de HasManualOrder.
     */
    protected function manualOrderPrepend(): bool
    {
        return true;
    }

    protected $casts = [
        'opened_at' => 'date',
        'completed_at' => 'date',
        'labor_hours' => 'decimal:2',
        'parts_cost' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'status', 'assigned_to', 'completed_by', 'parts_cost', 'service_tier', 'field_report_id'])
            ->logOnlyDirty();
    }

    /**
     * Pedido del cliente (2026-09-22): el número de orden no es mantenimiento
     * por horómetro, es reparación correctiva o upgrade. Se suman estas dos
     * opciones a las cuatro que ya existían (horas de intervalo).
     *
     * Únicos dos valores no numéricos de la columna: `service_tier` sigue
     * siendo `string nullable` y sigue recibiendo horas libres desde
     * `AlertResource` (una OT nacida de una alerta lleva
     * `machine->service_interval_hours`, que es un número LIBRE — no está
     * restringido a 500/1000/2000/4000, ver `MachineResource::form()`). Por
     * eso la lista de abajo es la fuente de las OPCIONES DEL FORM MANUAL, no
     * una restricción del dato en la base: validar contra ella en
     * `WorkOrderObserver` rompería una OT real creada desde una alerta sobre
     * una máquina con intervalo de servicio no estándar. El blindaje de
     * "solo estos valores" vive en el `Select` del form (`->in()`), que es el
     * único camino donde tiene sentido — el humano tecleando a mano.
     */
    public const SERVICE_TIER_REPAIR = 'repair';

    public const SERVICE_TIER_UPGRADE = 'upgrade';

    public const SERVICE_TIER_DEFAULT = self::SERVICE_TIER_REPAIR;

    /**
     * Única fuente de las opciones de `service_tier`, para que el form, la
     * tabla, el reporte de costos (pantalla, PDF y Excel) y cualquier otro
     * consumidor futuro muestren la misma etiqueta traducida. Antes de esto
     * el reporte de costos formateaba "({{ tier }} h)" a ciegas, y con
     * `service_tier = 'repair'` eso imprimía literalmente "(repair h)".
     *
     * @return array<string, string>
     */
    public static function serviceTierOptions(): array
    {
        return [
            '500' => '500 h',
            '1000' => '1000 h',
            '2000' => '2000 h',
            '4000' => '4000 h',
            self::SERVICE_TIER_REPAIR => __('wo.service_tier_repair'),
            self::SERVICE_TIER_UPGRADE => __('wo.service_tier_upgrade'),
        ];
    }

    /**
     * Etiqueta lista para mostrar. Un tier que no está en la lista de arriba
     * (una OT vieja creada desde una alerta, con horas libres tipo "750") se
     * muestra tal cual viene, sin inventarle un "h" que no pidió.
     */
    public static function serviceTierLabel(?string $tier): ?string
    {
        if ($tier === null || $tier === '') {
            return null;
        }

        return self::serviceTierOptions()[$tier] ?? $tier;
    }

    /**
     * Prefijo de los códigos que genera el sistema. Los tecleados a mano
     * pueden ser cualquier cosa (`QA-OT-01`, `OT-CLIENTE-7`); esos no entran en
     * la numeración automática.
     */
    public const CODE_PREFIX = 'WO-';

    /**
     * Siguiente código libre, garantizado libre.
     *
     * Hallazgo E6-09: antes esto era `'WO-'.str_pad(max('id') + 1, 4, '0')`, y
     * eso está mal por dos motivos medidos en la Sesión 1:
     *
     *   - `max(id) + 1` **no es el id que va a tener la fila**: con `max(id)=14`
     *     la propuesta fue `WO-0015` y la fila quedó con id 16. El número no
     *     identificaba nada.
     *   - **no estaba garantizado libre**: alcanza que alguien haya tecleado a
     *     mano un código con el mismo patrón —que es el patrón del cliente—
     *     para que la propuesta choque. Y al chocar caía en E6-07: un 500 mudo.
     *
     * Ahora se numera sobre el sufijo de los códigos existentes (no sobre el id)
     * y se avanza hasta encontrar uno que no exista. La concurrencia real la
     * cubren la validación `->unique()` del formulario y el reintento de quien
     * crea sin formulario.
     */
    public static function nextCode(): string
    {
        $ultimo = static::query()
            ->where('code', 'like', self::CODE_PREFIX.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(code, '.(strlen(self::CODE_PREFIX) + 1).') AS UNSIGNED)) as n')
            ->value('n');

        $numero = (int) $ultimo;

        do {
            $numero++;
            $candidato = self::CODE_PREFIX.str_pad((string) $numero, 4, '0', STR_PAD_LEFT);
        } while (static::query()->where('code', $candidato)->exists());

        return $candidato;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Quién ejecutó el cierre de la OT. No confundir con `assignee()`: ver el
     * comentario de App\Observers\WorkOrderObserver::saving().
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * La obra donde se hizo el trabajo, congelada al abrir la OT. No es lo mismo
     * que `machine->location`, que es dónde está la máquina ahora.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Orden manual (arrastrable) de las líneas de repuestos, respetado acá
     * para que CostReportBuilder —y por lo tanto la pantalla, el PDF y el
     * Excel del reporte de costos— muestre las líneas en el orden que el
     * taller les dio, no por id de carga.
     */
    public function parts(): HasMany
    {
        return $this->hasMany(WorkOrderPart::class)->orderBy('sort_order');
    }

    public function checklistResults(): HasMany
    {
        return $this->hasMany(ChecklistResult::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkOrderAttachment::class);
    }

    /**
     * Reporte de campo del que nació esta OT, si nació de uno. Nunca
     * obligatorio (pedido del cliente 2026-09-22): un preventivo por
     * horómetro no tiene ningún reporte detrás.
     */
    public function fieldReport(): BelongsTo
    {
        return $this->belongsTo(FieldReport::class);
    }
}
