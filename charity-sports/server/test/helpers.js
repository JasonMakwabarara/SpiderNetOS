'use strict';
/* Test scaffolding: a real server on a throwaway content directory, plus a
   tiny client that carries cookies and CSRF tokens like a browser would. */
const fs = require('fs');
const fsp = fs.promises;
const os = require('os');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SEED = path.join(ROOT, 'content', 'seed', 'content.seed.json');

const { load } = require('../config');
const { createApp } = require('../app');
const authLib = require('../lib/auth');

async function makeConfig(overrides = {}) {
  const dir = await fsp.mkdtemp(path.join(os.tmpdir(), 'cs-test-'));
  await fsp.mkdir(path.join(dir, 'content', 'seed'), { recursive: true });
  await fsp.mkdir(path.join(dir, 'uploads'), { recursive: true });
  await fsp.copyFile(SEED, path.join(dir, 'content', 'seed', 'content.seed.json'));

  /* A copy of the real snapshot and page, so publish tests write here and
     never touch the repository. */
  await fsp.mkdir(path.join(dir, 'data'), { recursive: true });
  await fsp.copyFile(path.join(ROOT, 'data', 'site-data.js'), path.join(dir, 'data', 'site-data.js'));
  await fsp.copyFile(path.join(ROOT, 'index.html'), path.join(dir, 'index.html'));

  const config = load({
    env: 'test',
    isProd: false,
    port: 0,
    contentDir: path.join(dir, 'content'),
    uploadDir: path.join(dir, 'uploads'),
    snapshotPath: path.join(dir, 'data', 'site-data.js'),
    indexPath: path.join(dir, 'index.html'),
    publicDir: ROOT,
    rootDir: dir,
    sessionSecret: crypto.randomBytes(32).toString('base64url'),
    cookieSecure: false,
    logLevel: 'silent',
    ...overrides
  });
  config.usersPath = path.join(config.contentDir, 'users.json');
  config.contentPath = path.join(config.contentDir, 'content.json');
  config.sessionsPath = path.join(config.contentDir, 'sessions.json');
  config.auditPath = path.join(config.contentDir, 'audit.log');
  config.backupDir = path.join(config.contentDir, 'backups');
  config.seedPath = path.join(config.contentDir, 'seed', 'content.seed.json');
  config.tmpDir = dir;
  return config;
}

/** A minimal browser: keeps cookies, sends the CSRF header, parses JSON. */
function makeClient(base) {
  const jar = new Map();

  function cookieHeader() {
    return Array.from(jar.entries()).map(([k, v]) => `${k}=${v}`).join('; ');
  }
  function absorb(res) {
    const raw = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
    raw.forEach((line) => {
      const [pair] = line.split(';');
      const index = pair.indexOf('=');
      const name = pair.slice(0, index).trim();
      const value = pair.slice(index + 1).trim();
      if (value === '' || /Expires=Thu, 01 Jan 1970/i.test(line)) jar.delete(name);
      else jar.set(name, value);
    });
  }

  async function request(method, url, { body, headers = {}, raw = false, omitCsrf = false, origin } = {}) {
    const init = { method, headers: { ...headers }, redirect: 'manual' };
    const cookies = cookieHeader();
    if (cookies) init.headers.Cookie = cookies;

    if (!['GET', 'HEAD'].includes(method)) {
      if (origin !== null) init.headers.Origin = origin === undefined ? base : origin;
      /* Only fill the token in when the caller did not set one themselves,
         so a test can deliberately send a wrong token. */
      const alreadySet = Object.keys(init.headers).some((h) => h.toLowerCase() === 'x-csrf-token');
      if (!omitCsrf && !alreadySet && jar.has('cs_csrf')) init.headers['X-CSRF-Token'] = jar.get('cs_csrf');
    }
    if (body !== undefined) {
      if (raw) {
        init.body = body;
      } else {
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
      }
    }

    const res = await fetch(base + url, init);
    absorb(res);
    const type = res.headers.get('content-type') || '';
    const payload = type.includes('application/json') ? await res.json().catch(() => null) : await res.text();
    return { status: res.status, headers: res.headers, body: payload };
  }

  return {
    jar,
    get: (url, opts) => request('GET', url, opts),
    post: (url, body, opts) => request('POST', url, { body, ...opts }),
    put: (url, body, opts) => request('PUT', url, { body, ...opts }),
    del: (url, opts) => request('DELETE', url, opts),
    async primeCsrf() { await request('GET', '/api/csrf'); return jar.get('cs_csrf'); },
    clearCookie(name) { jar.delete(name); }
  };
}

/** Boot a server with one admin account whose password is returned. */
async function startServer(overrides = {}) {
  const config = await makeConfig(overrides);
  const app = await createApp(config);

  const password = 'test-password-correct-horse';
  const store = app.locals.store;
  await store.updateUsers((users) => {
    users.push({
      id: crypto.randomUUID(),
      username: 'admin',
      displayName: 'Admin',
      passwordHash: null,          // filled in below
      role: 'admin',
      mustChangePassword: false,
      disabled: false,
      failedCount: 0,
      lockedUntil: null,
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
      lastLoginAt: null
    });
  });
  const hash = await authLib.hashPassword(password);
  await store.updateUsers((users) => { users[0].passwordHash = hash; });

  const server = app.listen(0, '127.0.0.1');
  await new Promise((resolve) => server.once('listening', resolve));
  const base = `http://127.0.0.1:${server.address().port}`;

  return {
    app, config, server, base, password,
    client: makeClient(base),
    async signIn(client) {
      const c = client || makeClient(base);
      await c.primeCsrf();
      const res = await c.post('/api/auth/login', { username: 'admin', password });
      return { client: c, res };
    },
    async stop() {
      await new Promise((resolve) => server.close(resolve));
      try { await app.locals.shutdown(); } catch (err) { /* ignore */ }
      await fsp.rm(config.tmpDir, { recursive: true, force: true });
    }
  };
}

module.exports = { startServer, makeConfig, makeClient, ROOT, SEED };
