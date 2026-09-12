/* Charity Sports service worker.
 *
 * The point is a repeat visit on a dropped connection: somebody opens the link
 * from a WhatsApp forward on the edge of coverage and still sees the page.
 *
 * Two rules, and they matter in this order:
 *   - the content file and the page itself go to the network first, so an edit
 *     that has been published is never hidden behind a stale copy;
 *   - everything else static is served from the cache first, because it only
 *     changes when the version below changes.
 *
 * The admin panel and the API are never touched. Caching a signed-in admin
 * response would be a genuine hazard, so those requests are passed straight
 * through and are not even inspected.
 *
 * This file has to sit at the site root. A service worker can only control
 * pages at or below its own directory, so one served from assets/ would cover
 * nothing but assets/. At the root it covers the whole site, and on a GitHub
 * Pages sub-path it covers that sub-path, which is what is wanted in both.
 */
var VERSION = 'charity-sports-v1';

var SHELL = [
  './',
  './index.html',
  './404.html',
  './data/site-data.js',
  './assets/css/styles.css',
  './assets/js/utils.js',
  './assets/js/nav.js',
  './assets/js/counter.js',
  './assets/js/causes.js',
  './assets/js/events.js',
  './assets/js/sponsors.js',
  './assets/js/gallery.js',
  './assets/js/engage.js',
  './assets/js/live.js',
  './assets/js/main.js',
  './assets/img/favicon.svg',
  './assets/img/hero-doctors-800.webp',
  './assets/img/hero-doctors-1280.webp'
];

/* Anything under these paths is none of the worker's business. */
function isPrivate(url) {
  return url.pathname.indexOf('/api/') > -1 ||
         url.pathname.indexOf('/admin') > -1;
}

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(VERSION)
      /* addAll fails the whole install if one file is missing, which would
         leave the site with no worker at all. Add them individually. */
      .then(function (cache) {
        return Promise.all(SHELL.map(function (path) {
          return cache.add(path).catch(function () { return null; });
        }));
      })
      .then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys()
      .then(function (keys) {
        return Promise.all(keys
          .filter(function (key) { return key !== VERSION; })
          .map(function (key) { return caches.delete(key); }));
      })
      .then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') return;

  var url;
  try { url = new URL(request.url); } catch (err) { return; }
  if (url.origin !== self.location.origin) return;   // fonts and YouTube go direct
  if (isPrivate(url)) return;

  var freshFirst = url.pathname.endsWith('/site-data.js') ||
    url.pathname.endsWith('/') ||
    url.pathname.endsWith('.html') ||
    request.mode === 'navigate';

  if (freshFirst) {
    event.respondWith(
      fetch(request)
        .then(function (response) {
          if (response && response.ok) {
            var copy = response.clone();
            caches.open(VERSION).then(function (cache) { cache.put(request, copy); });
          }
          return response;
        })
        .catch(function () {
          return caches.match(request).then(function (hit) {
            return hit || caches.match('./index.html');
          });
        })
    );
    return;
  }

  event.respondWith(
    caches.match(request).then(function (hit) {
      if (hit) return hit;
      return fetch(request).then(function (response) {
        if (response && response.ok && response.type === 'basic') {
          var copy = response.clone();
          caches.open(VERSION).then(function (cache) { cache.put(request, copy); });
        }
        return response;
      });
    })
  );
});
