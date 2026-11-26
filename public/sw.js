// Service worker do Finanças de Casa: existe para o app poder ser instalado (PWA).
// Não guarda dados financeiros: as páginas vêm sempre do servidor. Só a página "Sem conexão"
// e o ícone dela ficam em cache, para aparecer quando o servidor estiver fora do ar.
const CACHE = 'fc-offline-v1';
const OFFLINE_ASSETS = ['/offline.html', '/icons/icon.svg'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(OFFLINE_ASSETS)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') {
        return;
    }

    event.respondWith(fetch(event.request).catch(() => caches.match('/offline.html')));
});
