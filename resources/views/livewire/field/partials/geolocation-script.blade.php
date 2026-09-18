{{--
    Script de geolocalización compartido por las pantallas de campo que la
    usan (report-form, fuel-log). Antes estaba copiado y pegado en cada
    vista con un callback de error vacío (`() => {}`): un permiso denegado,
    un GPS sin señal o un timeout se descartaban en silencio y el texto
    "Obteniendo tu ubicación…" se quedaba puesto para siempre, aunque el
    navegador ya se hubiera rendido (reproducido en escritorio con permiso
    CONCEDIDO: el timeout corto expiraba sin avisar nada).

    Cada componente que incluye este parcial debe exponer:
      - setLocation($lat, $lng)     — éxito (ya existía)
      - setLocationError($reason)   — 'denied' | 'unavailable' | 'unsupported'
      - retryLocation()             — vuelve el estado a "obteniendo" y
                                       despacha el evento 'geolocation-retry'
--}}
<script>
    document.addEventListener('livewire:init', () => {
        function requestLocation() {
            if (!navigator.geolocation) {
                @this.call('setLocationError', 'unsupported');
                return;
            }

            navigator.geolocation.getCurrentPosition(
                (pos) => { @this.call('setLocation', pos.coords.latitude, pos.coords.longitude); },
                (err) => {
                    // err.code 1 = permiso denegado; cualquier otro (sin señal, timeout) = no disponible.
                    @this.call('setLocationError', err.code === 1 ? 'denied' : 'unavailable');
                },
                // 15s: buscar señal GPS desde un celular en obra toma más que
                // en escritorio con wifi. maximumAge permite reusar una
                // lectura reciente en vez de exigir siempre una fija nueva.
                { enableHighAccuracy: false, timeout: 15000, maximumAge: 60000 }
            );
        }

        requestLocation();

        Livewire.on('geolocation-retry', () => requestLocation());
    });
</script>
