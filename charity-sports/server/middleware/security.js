'use strict';
/* Security headers.
 *
 * The admin panel gets a strict CSP with no 'unsafe-inline' anywhere, which is
 * only possible because it ships zero inline scripts and zero style
 * attributes. The public site needs Google Fonts and a YouTube frame, so its
 * policy is wider but still closed by default. */
const helmet = require('helmet');

function adminCsp() {
  return {
    'default-src': ["'none'"],
    'script-src': ["'self'"],
    'style-src': ["'self'"],
    'img-src': ["'self'", 'data:', 'blob:'],
    'font-src': ["'self'"],
    'connect-src': ["'self'"],
    'form-action': ["'self'"],
    'base-uri': ["'none'"],
    'frame-ancestors': ["'none'"]
  };
}

function publicCsp(config) {
  const connect = ["'self'"];
  config.corsOrigins.forEach((origin) => connect.push(origin));
  return {
    'default-src': ["'self'"],
    'script-src': ["'self'"],
    'style-src': ["'self'", 'https://fonts.googleapis.com'],
    'font-src': ["'self'", 'https://fonts.gstatic.com'],
    'img-src': ["'self'", 'data:', 'https://i.ytimg.com'],
    'media-src': ["'self'"],
    'frame-src': ['https://www.youtube-nocookie.com'],
    'connect-src': connect,
    'form-action': ["'self'"],
    'base-uri': ["'none'"],
    'frame-ancestors': ["'none'"]
  };
}

function apply(app, config) {
  app.disable('x-powered-by');

  app.use(helmet({
    contentSecurityPolicy: false,          // set per-area below
    crossOriginEmbedderPolicy: false,      // would block the YouTube frame
    crossOriginResourcePolicy: { policy: 'same-site' },
    referrerPolicy: { policy: 'strict-origin-when-cross-origin' },
    hsts: false                            // applied conditionally below
  }));

  app.use((req, res, next) => {
    const isAdmin = req.path.startsWith('/admin') || req.path.startsWith('/api');
    const directives = isAdmin ? adminCsp() : publicCsp(config);
    res.setHeader('Content-Security-Policy', Object.entries(directives)
      .map(([key, values]) => `${key} ${values.join(' ')}`).join('; '));
    res.setHeader('X-Frame-Options', 'DENY');
    res.setHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=()');

    /* HSTS only when we are genuinely serving over TLS. Sending it from a
       plain-HTTP box, or from an IP with no certificate, locks people out. */
    if (config.isProd && req.secure) {
      res.setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
    next();
  });
}

module.exports = { apply, adminCsp, publicCsp };
