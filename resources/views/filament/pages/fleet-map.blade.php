@push('styles')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
@endpush

@push('scripts')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
@endpush

<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
        @php
            $locations = $this->locations;
            $locationsJson = json_encode($locations->map(fn ($l) => [
                'name' => $l->name,
                'type' => $l->type,
                'lat' => (float) $l->latitude,
                'lng' => (float) $l->longitude,
                'machines' => $l->machines_count,
            ])->values());
        @endphp

        <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">
            {{ __('mgmt.coords_approx_notice') }}
        </p>

        @if($locations->isEmpty())
            <div class="text-center text-gray-500 dark:text-gray-400 py-16">
                {{ __('mgmt.no_coords') }}
            </div>
        @else
            <div
                wire:ignore
                id="dp-fleet-map"
                x-data
                x-init="
                    const locations = {{ $locationsJson }};

                    const map = L.map($el).setView([locations[0].lat, locations[0].lng], 10);

                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 18,
                        attribution: '&copy; OpenStreetMap contributors',
                    }).addTo(map);

                    const bounds = [];
                    locations.forEach((loc) => {
                        const icon = L.divIcon({
                            className: '',
                            html: '&lt;div style=&quot;background:' + (loc.type === 'yard' ? '#3b82f6' : '#f59e0b') + ';color:#fff;border-radius:9999px;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.4);&quot;&gt;' + loc.machines + '&lt;/div&gt;',
                            iconSize: [28, 28],
                        });
                        L.marker([loc.lat, loc.lng], { icon }).addTo(map)
                            .bindPopup('&lt;strong&gt;' + loc.name + '&lt;/strong&gt;&lt;br&gt;' + loc.type + '&lt;br&gt;' + loc.machines + ' active machine(s)');
                        bounds.push([loc.lat, loc.lng]);
                    });

                    if (bounds.length > 1) {
                        map.fitBounds(bounds, { padding: [30, 30] });
                    }
                "
                style="height: 560px; border-radius: 0.75rem; z-index: 0;"
            ></div>
        @endif
    </div>
</x-filament-panels::page>
