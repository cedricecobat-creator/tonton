/* Service worker minimal : rend l'application installable.
   Aucune donnée n'est mise en cache : tout passe par le réseau. */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function () { /* réseau uniquement */ });
