<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/**
 * Los filtros del reporte de costos, en un solo objeto.
 *
 * Existe para que la pantalla, el PDF y el Excel no puedan interpretar de
 * distinta forma la misma URL. Los tres caminos construyen este objeto a partir
 * de un array (el estado del formulario o el query string) y se lo pasan al
 * builder; si el parseo viviera en cada camino, un día el PDF filtraría por un
 * mes y la pantalla por otro, y nadie lo notaría hasta que los totales no
 * cuadraran contra la factura.
 */
class CostReportFilters
{
    /**
     * @param  array<int, int>  $machineIds
     * @param  array<int, int>  $categoryIds
     * @param  array<int, int>  $locationIds
     * @param  array<int, int>  $completedBy
     * @param  array<int, string>  $types
     * @param  array<int, string>  $statuses
     */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?string $idCode = null,
        public readonly array $machineIds = [],
        public readonly array $categoryIds = [],
        public readonly array $locationIds = [],
        public readonly array $completedBy = [],
        public readonly array $types = [],
        public readonly array $statuses = ['completed'],
    ) {}

    /**
     * Estados de OT que el reporte acepta. `completed` es el default porque un
     * costo de una OT abierta todavía puede cambiar; incluir las abiertas es una
     * decisión explícita de quien pide el reporte, no el default.
     */
    public const ALLOWED_STATUSES = ['open', 'assigned', 'in_progress', 'completed', 'cancelled'];

    public const ALLOWED_TYPES = ['preventive', 'corrective', 'inspection'];

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        // Default: el mes en curso. Es la pregunta que hizo el cliente
        // ("cuánto gastamos en el mes X"), así que abrir el reporte ya responde
        // algo en vez de mostrar una pantalla vacía.
        $from = static::parseDate($data['from'] ?? null) ?? CarbonImmutable::now()->startOfMonth();
        $to = static::parseDate($data['to'] ?? null) ?? CarbonImmutable::now()->endOfMonth();

        // Un rango invertido no es un error del usuario que valga la pena
        // gritarle: se endereza y el reporte sale igual.
        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        return new self(
            from: $from->startOfDay(),
            to: $to->endOfDay(),
            idCode: static::cleanString($data['id_code'] ?? null),
            machineIds: static::intList($data['machine_ids'] ?? []),
            categoryIds: static::intList($data['category_ids'] ?? []),
            locationIds: static::intList($data['location_ids'] ?? []),
            completedBy: static::intList($data['completed_by'] ?? []),
            types: static::allowList($data['types'] ?? [], self::ALLOWED_TYPES),
            statuses: static::allowList($data['statuses'] ?? [], self::ALLOWED_STATUSES) ?: ['completed'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return array_filter([
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'id_code' => $this->idCode,
            'machine_ids' => $this->machineIds,
            'category_ids' => $this->categoryIds,
            'location_ids' => $this->locationIds,
            'completed_by' => $this->completedBy,
            'types' => $this->types,
            'statuses' => $this->statuses,
        ], fn ($value) => $value !== null && $value !== []);
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        // Una fecha ilegible en el query string no debe tumbar el reporte con un
        // 500: se ignora y manda el default del periodo.
        try {
            return CarbonImmutable::parse(trim($value));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<int, int>
     */
    private static function intList(mixed $value): array
    {
        if (! is_array($value)) {
            $value = $value === null || $value === '' ? [] : [$value];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($item) => (int) $item, $value),
            static fn (int $item) => $item > 0,
        )));
    }

    /**
     * @param  array<int, string>  $allowed
     * @return array<int, string>
     */
    private static function allowList(mixed $value, array $allowed): array
    {
        if (! is_array($value)) {
            $value = $value === null || $value === '' ? [] : [$value];
        }

        return array_values(array_intersect(
            array_map(static fn ($item) => (string) $item, $value),
            $allowed,
        ));
    }
}
