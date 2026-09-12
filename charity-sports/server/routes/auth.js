'use strict';
/* Sign in, sign out, change password.
 *
 * A wrong username and a wrong password produce byte-identical responses, and
 * both cost an argon2 verify, so neither the wording nor the timing says
 * whether an account exists. */
const express = require('express');
const auth = require('../lib/auth');
const csrf = require('../lib/csrf');
const { createLimiter, clientIp } = require('../lib/ratelimit');

const GENERIC = { error: 'bad_credentials', message: 'Incorrect username or password.' };

function lockMinutesFor(user, config) {
  const strikes = Math.max(0, Math.floor((user.failedCount || 0) / config.loginMaxFails) - 1);
  return Math.min(config.loginLockMinutes * Math.pow(2, strikes), config.loginMaxLockMinutes);
}

function createAuthRoutes({ config, store, sessions, audit, requireAuth, requireCsrf }) {
  const router = express.Router();
  const loginLimiter = createLimiter({
    windowMs: config.loginWindowMinutes * 60 * 1000,
    max: config.rateLimitLoginPer15
  });

  router.post('/login', requireCsrf, async (req, res, next) => {
    try {
      const ip = clientIp(req);
      const gate = loginLimiter.take(ip);
      if (!gate.allowed) {
        res.setHeader('Retry-After', String(gate.retryAfter));
        return res.status(429).json({
          error: 'too_many',
          message: `Too many attempts. Try again in ${Math.ceil(gate.retryAfter / 60)} minute(s).`
        });
      }

      const username = String((req.body && req.body.username) || '').trim();
      const password = String((req.body && req.body.password) || '');
      const ua = req.get('user-agent');

      const user = store.findUser(username);

      if (!user || user.disabled) {
        /* Verify against a throwaway hash so this path costs the same as a
           real one. */
        await auth.dummyVerify(password);
        await audit.write({ action: 'login', result: 'fail', summary: 'unknown or disabled account', ip, ua });
        return res.status(401).json(GENERIC);
      }

      const lockedUntil = user.lockedUntil ? new Date(user.lockedUntil).getTime() : 0;
      const locked = lockedUntil > Date.now();
      const correct = await auth.verifyPassword(user.passwordHash, password);

      if (locked) {
        const minutes = Math.ceil((lockedUntil - Date.now()) / 60000);
        await audit.write({ actor: user, action: 'login', result: 'locked', ip, ua });
        /* Only someone who already has the right password learns that the
           account is locked rather than simply wrong. */
        if (correct) {
          return res.status(423).json({
            error: 'locked',
            message: `Too many failed attempts. This account is locked for another ${minutes} minute(s).`
          });
        }
        return res.status(401).json(GENERIC);
      }

      if (!correct) {
        await store.updateUsers((users) => {
          const row = users.find((u) => u.id === user.id);
          if (!row) return;
          row.failedCount = (row.failedCount || 0) + 1;
          if (row.failedCount % config.loginMaxFails === 0) {
            row.lockedUntil = new Date(Date.now() + lockMinutesFor(row, config) * 60000).toISOString();
          }
          row.updatedAt = new Date().toISOString();
        });
        await audit.write({ actor: user, action: 'login', result: 'fail', ip, ua });
        return res.status(401).json(GENERIC);
      }

      /* Success: clear the strikes, upgrade an old hash, start a session. */
      await store.updateUsers(async (users) => {
        const row = users.find((u) => u.id === user.id);
        if (!row) return;
        row.failedCount = 0;
        row.lockedUntil = null;
        row.lastLoginAt = new Date().toISOString();
        if (auth.needsRehash(row.passwordHash)) {
          row.passwordHash = await auth.hashPassword(password);
        }
      });

      const id = sessions.create(user, req);
      res.cookie(sessions.cookieName, sessions.cookieValue(id), sessions.cookieOptions());
      csrf.issue(res, { secure: config.cookieSecure });
      loginLimiter.reset(ip);

      await audit.write({ actor: user, action: 'login', result: 'ok', ip, ua });
      return res.json({
        ok: true,
        user: store.publicUser(store.findUserById(user.id)),
        mustChangePassword: !!user.mustChangePassword
      });
    } catch (err) { next(err); }
  });

  router.post('/logout', requireCsrf, async (req, res) => {
    const raw = req.cookies && req.cookies[sessions.cookieName];
    const row = sessions.read(raw);
    if (row) {
      sessions.destroy(row.id);
      const user = store.findUserById(row.userId);
      await audit.write({ actor: user, action: 'logout', ip: clientIp(req), ua: req.get('user-agent') });
    }
    res.clearCookie(sessions.cookieName, sessions.cookieOptions());
    res.json({ ok: true });
  });

  router.get('/me', requireAuth, (req, res) => {
    res.json({ user: store.publicUser(req.user) });
  });

  router.post('/password', requireAuth, requireCsrf, async (req, res, next) => {
    try {
      const current = String((req.body && req.body.currentPassword) || '');
      const next_ = String((req.body && req.body.newPassword) || '');
      const user = req.user;

      const correct = await auth.verifyPassword(user.passwordHash, current);
      if (!correct) {
        await audit.write({ actor: user, action: 'password_change', result: 'fail', ip: clientIp(req) });
        return res.status(401).json({
          errors: [{ path: 'currentPassword', message: 'That is not your current password.' }]
        });
      }

      const problems = auth.checkPasswordPolicy(next_, { username: user.username });
      if (problems.length) return res.status(422).json({ errors: problems });
      if (await auth.verifyPassword(user.passwordHash, next_)) {
        return res.status(422).json({
          errors: [{ path: 'newPassword', message: 'That is the password you already have. Pick a different one.' }]
        });
      }

      const hash = await auth.hashPassword(next_);
      await store.updateUsers((users) => {
        const row = users.find((u) => u.id === user.id);
        row.passwordHash = hash;
        row.mustChangePassword = false;
        row.updatedAt = new Date().toISOString();
      });

      /* Every other session for this person dies, then a fresh id is issued
         here, so a stolen cookie does not survive a password change. */
      sessions.destroyForUser(user.id);
      const id = sessions.create(user, req);
      res.cookie(sessions.cookieName, sessions.cookieValue(id), sessions.cookieOptions());

      await audit.write({ actor: user, action: 'password_change', result: 'ok', ip: clientIp(req) });
      res.json({ ok: true, user: store.publicUser(store.findUserById(user.id)) });
    } catch (err) { next(err); }
  });

  return router;
}

module.exports = { createAuthRoutes };
