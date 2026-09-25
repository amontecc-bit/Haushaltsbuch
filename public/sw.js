/* Minimaler Service Worker: ermöglicht die Installation als App und cached statische Dateien.
   Seiten mit Finanzdaten werden bewusst NICHT zwischengespeichert. */
const CACHE = 'hb-static-v1';
self.addEventListener('install', (e) => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim())
));
self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);
    if (e.request.method !== 'GET' || url.origin !== location.origin || !url.pathname.includes('/assets/')) return;
    e.respondWith(caches.open(CACHE).then(async (cache) => {
        const hit = await cache.match(e.request);
        if (hit) return hit;
        const res = await fetch(e.request);
        if (res.ok) cache.put(e.request, res.clone());
        return res;
    }));
});
