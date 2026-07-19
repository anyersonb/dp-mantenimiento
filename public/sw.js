// DP Fleet Maintenance — service worker (baseline)
// Estrategia: network-first para navegación (datos siempre frescos cuando hay red),
// con fallback a caché para permitir apertura offline. La cola offline completa de
// captura en campo (horómetro/combustible) se implementa en la etapa 02 (app de campo).

const CACHE = 'dp-fleet-v1';
const APP_SHELL = ['/offline.html'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll(APP_SHELL)).catch(() => {})
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;

    // Solo navegación HTML: network-first con fallback offline
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match('/offline.html'))
        );
        return;
    }
});
