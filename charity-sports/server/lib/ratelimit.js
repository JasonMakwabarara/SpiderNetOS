'use strict';
/* A sliding-window counter kept in memory. Small enough not to need a
   dependency, and it does exactly what a single-process server needs. */

function createLimiter({ windowMs, max }) {
  const hits = new Map();

  function sweep(now) {
    for (const [key, times] of hits) {
      const live = times.filter((t) => now - t < windowMs);
      if (live.length) hits.set(key, live); else hits.delete(key);
    }
  }

  let lastSweep = 0;
  return {
    /** @returns {{allowed: boolean, remaining: number, retryAfter: number}} */
    take(key) {
      const now = Date.now();
      if (now - lastSweep > windowMs) { sweep(now); lastSweep = now; }

      const times = (hits.get(key) || []).filter((t) => now - t < windowMs);
      if (times.length >= max) {
        const retryAfter = Math.ceil((windowMs - (now - times[0])) / 1000);
        hits.set(key, times);
        return { allowed: false, remaining: 0, retryAfter: Math.max(retryAfter, 1) };
      }
      times.push(now);
      hits.set(key, times);
      return { allowed: true, remaining: max - times.length, retryAfter: 0 };
    },
    reset(key) { hits.delete(key); },
    clear() { hits.clear(); }
  };
}

/** The client's address, honouring X-Forwarded-For only behind a known proxy. */
function clientIp(req) {
  return req.ip || (req.socket && req.socket.remoteAddress) || 'unknown';
}

module.exports = { createLimiter, clientIp };
