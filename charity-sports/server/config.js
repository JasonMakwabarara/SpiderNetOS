'use strict';
/* Every knob the server has, read from the environment once at boot.
   Anything invalid fails immediately rather than half-working in production. */
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..');

function str(name, fallback) {
  const value = process.env[name];
  return value === undefined || value === '' ? fallback : value;
}
function int(name, fallback) {
  const raw = process.env[name];
  if (raw === undefined || raw === '') return fallback;
  const value = Number(raw);
  if (!Number.isFinite(value)) throw new Error(`${name} must be a number, got "${raw}"`);
  return Math.trunc(value);
}
function bool(name, fallback) {
  const raw = process.env[name];
  if (raw === undefined || raw === '') return fallback;
  return /^(1|true|yes|on)$/i.test(raw);
}
function resolve(name, fallback) {
  return path.resolve(ROOT, str(name, fallback));
}

function load(overrides = {}) {
  const env = str('NODE_ENV', 'development');
  const isProd = env === 'production';

  let sessionSecret = str('SESSION_SECRET', '');
  if (!sessionSecret) {
    if (isProd) {
      throw new Error(
        'SESSION_SECRET is required in production. Generate one with:\n' +
        '  node -e "console.log(require(\'crypto\').randomBytes(32).toString(\'base64url\'))"'
      );
    }
    /* Development convenience only. It changes on every restart, so everyone
       is logged out when the server restarts, which is the right trade-off
       for a machine that is not serving the public. */
    sessionSecret = crypto.randomBytes(32).toString('base64url');
  } else if (sessionSecret.length < 32 && isProd) {
    throw new Error('SESSION_SECRET must be at least 32 characters.');
  }

  const config = {
    env,
    isProd,
    port: int('PORT', 3000),
    host: str('HOST', '0.0.0.0'),
    logLevel: str('LOG_LEVEL', isProd ? 'info' : 'debug'),

    rootDir: ROOT,
    publicDir: resolve('PUBLIC_DIR', '.'),
    contentDir: resolve('CONTENT_DIR', 'content'),
    uploadDir: resolve('UPLOAD_DIR', path.join('assets', 'img', 'uploads')),
    /* Paths the public site uses to reach an upload, so stored refs stay
       relative and keep working on a static host. */
    uploadUrlBase: str('UPLOAD_URL_BASE', 'assets/img/uploads'),
    snapshotPath: resolve('SNAPSHOT_PATH', path.join('data', 'site-data.js')),
    indexPath: resolve('INDEX_PATH', 'index.html'),

    sessionSecret,
    cookieSecure: bool('COOKIE_SECURE', isProd),
    trustProxy: bool('TRUST_PROXY', false),
    sessionIdleMinutes: int('SESSION_IDLE_MINUTES', 480),
    sessionAbsoluteHours: int('SESSION_ABSOLUTE_HOURS', 168),

    loginMaxFails: int('LOGIN_MAX_FAILS', 5),
    loginWindowMinutes: int('LOGIN_WINDOW_MINUTES', 15),
    loginLockMinutes: int('LOGIN_LOCK_MINUTES', 15),
    loginMaxLockMinutes: int('LOGIN_MAX_LOCK_MINUTES', 240),
    rateLimitApiPerMin: int('RATE_LIMIT_API_PER_MIN', 120),
    rateLimitLoginPer15: int('RATE_LIMIT_LOGIN_PER_15', 20),
    /* Sign-ups are limited per address. Mobile networks in Zimbabwe put many
       people behind one address, and a whole padel night signing up from the
       same wifi is the case this has to survive, so the ceiling is generous
       and adjustable. */
    signupRatePerMin: int('SIGNUP_RATE_PER_MIN', 10),

    uploadMaxBytes: int('UPLOAD_MAX_BYTES', 8 * 1024 * 1024),
    allowSvgUpload: bool('ALLOW_SVG_UPLOAD', false),

    corsOrigins: str('CORS_ORIGINS', '')
      .split(',').map((s) => s.trim()).filter(Boolean),

    siteUrl: str('SITE_URL', ''),
    publishTarget: str('PUBLISH_TARGET', 'file'),
    githubToken: str('GITHUB_TOKEN', ''),
    githubRepo: str('GITHUB_REPO', ''),
    githubBranch: str('GITHUB_BRANCH', 'main'),

    minPasswordLength: 12,
    maxPasswordLength: 200,

    ...overrides
  };

  if (!['file', 'github', 'git'].includes(config.publishTarget)) {
    throw new Error(`PUBLISH_TARGET must be file, github or git. Got "${config.publishTarget}".`);
  }
  if (config.publishTarget === 'github' && (!config.githubToken || !config.githubRepo)) {
    throw new Error('PUBLISH_TARGET=github needs GITHUB_TOKEN and GITHUB_REPO.');
  }

  config.usersPath = path.join(config.contentDir, 'users.json');
  config.contentPath = path.join(config.contentDir, 'content.json');
  config.sessionsPath = path.join(config.contentDir, 'sessions.json');
  config.auditPath = path.join(config.contentDir, 'audit.log');
  config.backupDir = path.join(config.contentDir, 'backups');
  config.seedPath = path.join(config.contentDir, 'seed', 'content.seed.json');

  return config;
}

module.exports = { load, ROOT };
