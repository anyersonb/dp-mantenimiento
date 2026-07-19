<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

/**
 * Backfill of APPROXIMATE lat/lng for existing yards/jobsites so the Fleet
 * Map page (Stage 04) has something to render.
 *
 * IMPORTANT: none of these locations were geocoded from a real street
 * address. They are placeholder town/road-level coordinates in the
 * West Palm Beach / Broward County, FL area (where the fleet operates),
 * picked from public place names only. The client (DP Development) must
 * confirm/correct the exact coordinates for each yard and jobsite.
 *
 * Idempotent: matches by exact `name` and always sets the same fixed
 * value, so running this seeder more than once is safe and never
 * creates duplicates.
 */
class LocationCoordinatesSeeder extends Seeder
{
    public function run(): void
    {
        $coordinates = [
            // yards
            'Broadview Yd.' => [26.1234, -80.1953],   // Broadview Park, Broward County (approx.)
            'WPB Yd.' => [26.7153, -80.0534],   // West Palm Beach city center (approx.)
            'Davie Yd.' => [26.0765, -80.2521],   // Davie, Broward County (approx.)
            'Atlantic Yd.' => [26.2712, -80.2075],   // Atlantic Blvd corridor, Coconut Creek/Margate (approx.)

            // jobsites
            'Blount Rd.' => [26.2568, -80.1367],   // Blount Rd corridor, Pompano/Deerfield area (approx.)
            'Pompano Bch' => [26.2379, -80.1248],   // Pompano Beach city center (approx.)
            'Douglas Rd.' => [26.0387, -80.1734],   // Douglas Rd corridor, Dania Beach/Hollywood area (approx.)
            'McNab' => [26.2016, -80.2172],   // McNab Rd corridor, North Lauderdale (approx.)
            'Turnpike' => [26.1224, -80.2431],   // Florida's Turnpike / Sawgrass area, Sunrise (approx.)
            'Rapid Milling & Paving' => [26.1901, -80.2660],   // Broward County center (unidentified exact site — approx.)
        ];

        foreach ($coordinates as $name => [$lat, $lng]) {
            Location::where('name', $name)->update([
                'latitude' => $lat,
                'longitude' => $lng,
            ]);
        }
    }
}
