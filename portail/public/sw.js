/* Simple Commerce : application installable sur téléphone.
   Les pages ne sont jamais gardées en mémoire (elles contiennent des données privées) :
   seuls les styles, scripts, polices et icônes le sont, plus une page « hors connexion ». */
var CACHE = 'sc-v2';
var OFFLINE = '/assets/hors-connexion.html';

self.addEventListener('install', function (event) {
  event.waitUntil(caches.open(CACHE).then(function (c) {
    return c.addAll([OFFLINE, '/assets/app.css', '/assets/logo-96.png', '/assets/fonts/figtree-latin-400-normal.woff2', '/assets/fonts/figtree-latin-600-normal.woff2']);
  }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (event) {
  event.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') { return; }
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) { return; }
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(function () { return caches.match(OFFLINE); }));
    return;
  }
  if (url.pathname.indexOf('/assets/') === 0 && !/widget\.js$/.test(url.pathname)) {
    // Réseau d'abord (pour avoir la dernière version), mémoire si pas de connexion.
    event.respondWith(fetch(req).then(function (res) {
      if (res.ok) {
        var copy = res.clone();
        caches.open(CACHE).then(function (c) { c.put(req, copy); });
      }
      return res;
    }).catch(function () { return caches.match(req); }));
  }
});
