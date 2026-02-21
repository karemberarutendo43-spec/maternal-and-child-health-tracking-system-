/* Minimal offline-first cache for static assets.
 * - Does NOT cache authenticated HTML pages (to avoid stale/incorrect data).
 * - Caches CSS/images + an offline fallback page.
 */

const CACHE_NAME = 'mchts-static-v1';
const PRECACHE_URLS = [
  '/mch_system/assets/output.css',
  '/mch_system/public/offline.html',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.map((k) => (k === CACHE_NAME ? Promise.resolve() : caches.delete(k))))
    ).then(() => self.clients.claim())
  );
});

function isStaticAsset(requestUrl) {
  return (
    requestUrl.pathname.startsWith('/mch_system/assets/') ||
    requestUrl.pathname.startsWith('/mch_system/public/') ||
    requestUrl.pathname.match(/\.(css|js|png|jpg|jpeg|gif|svg|ico)$/i)
  );
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // Only handle our app scope.
  if (!url.pathname.startsWith('/mch_system/')) return;

  // Cache-first for static assets.
  if (isStaticAsset(url)) {
    event.respondWith(
      caches.match(req).then((cached) => {
        if (cached) return cached;
        return fetch(req).then((res) => {
          const copy = res.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(req, copy)).catch(() => {});
          return res;
        });
      })
    );
    return;
  }

  // Network-first for everything else. If offline, show offline page for navigation requests.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match('/mch_system/public/offline.html'))
    );
  }
});

