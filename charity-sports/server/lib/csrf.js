'use strict';
/* Double-submit CSRF tokens plus an Origin check.
 *
 * The token lives in a readable cookie and must be echoed in a header that
 * only same-origin JavaScript can set. A cross-site form post carries the
 * cookie but cannot read it, so it cannot produce the header. */
const crypto = require('crypto');
const { safeEqual } = require('./auth');

const COOKIE = 'cs_csrf';
const HEADER = 'x-csrf-token';
const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

function issue(res, { secure }) {
  const token = crypto.randomBytes(32).toString('base64url');
  res.cookie(COOKIE, token, {
    httpOnly: false,          // the admin's fetch wrapper has to read it
    sameSite: 'lax',
    secure,
    path: '/'
  });
  return token;
}

function tokenFrom(req) {
  return req.get(HEADER) || (req.body && req.body._csrf) || '';
}

/** Same-origin check, falling back to Referer when Origin is absent. */
function originAllowed(req, allowedOrigins) {
  const origin = req.get('origin');
  const referer = req.get('referer');
  const source = origin || (referer ? safeOrigin(referer) : null);
  if (!source) return false;                    // mutations must declare themselves
  const self = `${req.protocol}://${req.get('host')}`;
  if (source === self) return true;
  return allowedOrigins.includes(source);
}

function safeOrigin(url) {
  try { return new URL(url).origin; } catch (err) { return null; }
}

function verify(req, allowedOrigins) {
  if (SAFE_METHODS.has(req.method)) return { ok: true };
  if (!originAllowed(req, allowedOrigins)) {
    return { ok: false, reason: 'origin', message: 'This request did not come from the admin panel.' };
  }
  const cookie = (req.cookies && req.cookies[COOKIE]) || '';
  const sent = tokenFrom(req);
  if (!cookie || !sent || !safeEqual(cookie, sent)) {
    return { ok: false, reason: 'csrf', message: 'Your session token expired. Reload the page and try again.' };
  }
  return { ok: true };
}

module.exports = { issue, verify, COOKIE, HEADER, SAFE_METHODS };
