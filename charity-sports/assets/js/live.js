/* Optional live content.
 *
 * The page has already rendered from data/site-data.js by the time this runs.
 * If an admin server happens to be reachable, and it offers content that is
 * genuinely newer and the same shape, the page quietly re-renders with it.
 * Every other outcome — no server, 404 on a static host, offline, opened from
 * a file:// path — leaves the built-in content exactly as it is. One console
 * line, no banner, no retry, no empty state.
 */
(function () {
  'use strict';
  var CS = (window.CS = window.CS || {});

  var SECTIONS = [
    'org', 'hero', 'impact', 'about', 'accountability', 'causes', 'events',
    'waysToSupport', 'sponsorTiers', 'sponsors', 'sponsorsMeta', 'gallery',
    'share', 'signup', 'donate', 'contact'
  ];

  function endpoint(snapshot) {
    var base = CS.get(snapshot, 'api.baseUrl', '') || '';
    if (base) {
      if (!/^https:\/\//i.test(base)) return null;   // only https for a remote API
      return base.replace(/\/+$/, '') + '/api/content';
    }
    return 'api/content';                            // relative: survives a sub-path
  }

  function usable(payload, snapshot) {
    if (!payload || typeof payload !== 'object') return false;
    if (payload.schemaVersion !== snapshot.schemaVersion) return false;
    var data = payload.data && typeof payload.data === 'object' ? payload.data : null;
    if (!data) return false;
    if (!payload.updatedAt || !snapshot.updatedAt) return false;
    return new Date(payload.updatedAt) > new Date(snapshot.updatedAt);
  }

  function merge(snapshot, payload) {
    var merged = Object.assign({}, snapshot);
    SECTIONS.forEach(function (key) {
      if (Object.prototype.hasOwnProperty.call(payload.data, key)) merged[key] = payload.data[key];
    });
    merged.updatedAt = payload.updatedAt;
    return merged;
  }

  function changedSections(before, after) {
    return SECTIONS.filter(function (key) {
      try {
        return JSON.stringify(before[key]) !== JSON.stringify(after[key]);
      } catch (err) {
        return true;
      }
    });
  }

  CS.live = {
    start: function (snapshot, onMerged) {
      if (!window.fetch || typeof Promise === 'undefined') return;
      if (window.location.protocol === 'file:') return;
      try {
        if (navigator.connection && navigator.connection.saveData) return;
      } catch (err) { /* no connection info, carry on */ }

      var url = endpoint(snapshot);
      if (!url) return;

      var run = function () {
        var options = { cache: 'no-store', credentials: 'omit', headers: { Accept: 'application/json' } };
        if (window.AbortSignal && AbortSignal.timeout) {
          try { options.signal = AbortSignal.timeout(2500); } catch (err) { /* older browser */ }
        }

        fetch(url, options)
          .then(function (res) {
            if (!res.ok) return null;
            var type = res.headers.get('content-type') || '';
            if (type.indexOf('application/json') !== 0) return null;
            return res.json();
          })
          .then(function (payload) {
            if (!usable(payload, snapshot)) {
              console.info('[live] using built-in content');
              return;
            }
            var merged = merge(snapshot, payload);
            var changed = changedSections(snapshot, merged);
            if (!changed.length) return;
            console.info('[live] refreshed:', changed.join(', '));
            onMerged(merged, changed);
          })
          .catch(function () {
            console.info('[live] using built-in content');
          });
      };

      /* Never make first paint wait for this. */
      if (window.requestIdleCallback) window.requestIdleCallback(run, { timeout: 2000 });
      else setTimeout(run, 300);
    },

    _sections: SECTIONS
  };
})();
