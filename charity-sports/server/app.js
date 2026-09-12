'use strict';
/* Builds the Express app. Exported without listening so tests can drive it. */
const express = require('express');
const cookieParser = require('cookie-parser');
const path = require('path');
const fs = require('fs');
const fsp = fs.promises;

const schema = require('./lib/schema');
const csrf = require('./lib/csrf');
const { createStore } = require('./lib/store');
const { createAudit } = require('./lib/audit');
const { createSessions } = require('./lib/sessions');
const { createLimiter, clientIp } = require('./lib/ratelimit');

const security = require('./middleware/security');
const { createRequireAuth } = require('./middleware/requireAuth');
const { createRequireCsrf } = require('./middleware/requireCsrf');
const { notFound, createErrorHandler } = require('./middleware/errors');

const { createPublicRoutes } = require('./routes/public');
const { createAuthRoutes } = require('./routes/auth');
const { createContentRoutes } = require('./routes/content');
const { createUserRoutes } = require('./routes/users');
const { createUploadRoutes } = require('./routes/uploads');
const { createPublishRoutes } = require('./routes/publish');
const { createAuditRoutes } = require('./routes/audit');
const { createBackupRoutes } = require('./routes/backup');
const { createSignupRoutes, createSignupAdminRoutes } = require('./routes/signup');

async function createApp(config) {
  const audit = createAudit({ auditPath: config.auditPath, logLevel: config.logLevel });
  const store = createStore(config, audit);
  await store.loadContent();
  await store.loadUsers();

  const sessions = createSessions({
    secret: config.sessionSecret,
    sessionsPath: config.sessionsPath,
    idleMinutes: config.sessionIdleMinutes,
    absoluteHours: config.sessionAbsoluteHours,
    cookieSecure: config.cookieSecure,
    logLevel: config.logLevel
  });
  await sessions.load();
  sessions.startSweeper();

  const app = express();
  if (config.trustProxy) app.set('trust proxy', 1);
  security.apply(app, config);

  app.use(cookieParser());
  app.use(express.json({ limit: '512kb' }));
  app.use(express.urlencoded({ extended: false, limit: '64kb' }));

  /* A broad ceiling on the API as a whole; the login route has its own,
     much tighter, limit inside its handler. */
  const apiLimiter = createLimiter({ windowMs: 60 * 1000, max: config.rateLimitApiPerMin });
  app.use('/api', (req, res, next) => {
    const gate = apiLimiter.take(clientIp(req));
    if (gate.allowed) return next();
    res.setHeader('Retry-After', String(gate.retryAfter));
    return res.status(429).json({ error: 'too_many', message: 'Too many requests. Slow down a moment.' });
  });

  app.get('/api/csrf', (req, res) => {
    res.setHeader('Cache-Control', 'no-store');
    res.json({ token: csrf.issue(res, { secure: config.cookieSecure }) });
  });

  /* -------------------------------------------------------------- public */
  app.use('/api', createPublicRoutes({ config, store, schema }));
  app.use('/api/signup', createSignupRoutes({ config, audit }));

  /* ---------------------------------------------------------------- auth */
  const requireCsrf = createRequireCsrf(config);
  const requireAuth = createRequireAuth({ sessions, store });

  app.use('/api/auth', createAuthRoutes({ config, store, sessions, audit, requireAuth, requireCsrf }));

  /* --------------------------------------------------------------- admin */
  const admin = express.Router();
  admin.use(requireAuth);
  admin.use((req, res, next) => {
    if (csrf.SAFE_METHODS.has(req.method)) return next();
    return requireCsrf(req, res, next);
  });
  /* The named routers go first. The content router ends with a catch-all
     "/:collection/:id", which would otherwise swallow /users/<id> and
     /uploads/<name> and answer 404 for them. */
  admin.use('/users', createUserRoutes({ store, sessions, audit }));
  admin.use('/uploads', createUploadRoutes({ config, store, audit }));
  admin.use('/publish', createPublishRoutes({ config, store, audit }));
  admin.use('/audit', createAuditRoutes({ audit }));
  admin.use('/backup', createBackupRoutes({ store, audit }));
  admin.use('/signups', createSignupAdminRoutes({ config, audit }));
  admin.use(createContentRoutes({ store, audit }));
  app.use('/api/admin', admin);

  /* ------------------------------------------------------- admin web pages */
  const loginHtmlPath = path.join(__dirname, 'views', 'login.html');
  app.get(['/admin/login', '/admin/login.html'], async (req, res, next) => {
    try {
      const token = csrf.issue(res, { secure: config.cookieSecure });
      const html = await fsp.readFile(loginHtmlPath, 'utf8');
      res.setHeader('Cache-Control', 'no-store');
      res.type('html').send(html.replace('__CSRF_TOKEN__', token));
    } catch (err) { next(err); }
  });

  app.use('/admin', (req, res, next) => {
    res.setHeader('Cache-Control', 'no-store');
    next();
  }, express.static(path.join(config.publicDir, 'admin'), { index: 'index.html', extensions: ['html'] }));

  /* ------------------------------------------------------- static content */
  /* Uploads may live on a mounted disk outside the repo, so they get their
     own mount rather than riding on the public directory. */
  app.use(`/${config.uploadUrlBase}`, express.static(config.uploadDir, {
    maxAge: '7d',
    setHeaders(res) { res.setHeader('X-Content-Type-Options', 'nosniff'); }
  }));

  app.use(express.static(config.publicDir, {
    index: 'index.html',
    extensions: ['html'],
    setHeaders(res, filePath) {
      if (filePath.endsWith(path.join('data', 'site-data.js')) || filePath.endsWith('index.html')) {
        res.setHeader('Cache-Control', 'no-cache');
      } else if (/\.(png|jpe?g|webp|svg|woff2?)$/i.test(filePath)) {
        res.setHeader('Cache-Control', 'public, max-age=604800');
      }
    }
  }));

  app.use(notFound);
  app.use(createErrorHandler(config));

  app.locals.store = store;
  app.locals.sessions = sessions;
  app.locals.audit = audit;
  app.locals.config = config;
  app.locals.shutdown = async () => { await sessions.stop(); };

  return app;
}

module.exports = { createApp };
