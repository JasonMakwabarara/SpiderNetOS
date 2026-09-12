'use strict';
/* Managing who can sign in. The guards matter more than the features: the
   panel must never be able to lock everyone out of itself. */
const express = require('express');
const crypto = require('crypto');
const auth = require('../lib/auth');
const { clientIp } = require('../lib/ratelimit');

const USERNAME = /^[a-z0-9][a-z0-9._-]{2,31}$/i;

function createUserRoutes({ store, sessions, audit }) {
  const router = express.Router();

  router.get('/', (req, res) => {
    res.setHeader('Cache-Control', 'no-store');
    res.json({ users: store.listUsers() });
  });

  router.post('/', async (req, res, next) => {
    try {
      const username = String((req.body && req.body.username) || '').trim();
      const displayName = String((req.body && req.body.displayName) || '').trim();

      if (!USERNAME.test(username)) {
        return res.status(422).json({
          errors: [{ path: 'username', message: 'Between 3 and 32 letters, numbers, dots, dashes or underscores.' }]
        });
      }
      if (store.findUser(username)) {
        return res.status(422).json({ errors: [{ path: 'username', message: 'Somebody already has that username.' }] });
      }

      const temporary = auth.generatePassword(20);
      const hash = await auth.hashPassword(temporary);
      const now = new Date().toISOString();
      const user = {
        id: crypto.randomUUID(),
        username,
        displayName: displayName || username,
        passwordHash: hash,
        role: 'admin',
        mustChangePassword: true,
        disabled: false,
        failedCount: 0,
        lockedUntil: null,
        createdAt: now,
        updatedAt: now,
        lastLoginAt: null
      };

      await store.updateUsers((users) => { users.push(user); });
      await audit.write({ actor: req.user, action: 'create_user', target: username, ip: clientIp(req) });

      /* Shown once, never stored in the clear, never written to the log. */
      res.status(201).json({ ok: true, user: store.publicUser(user), temporaryPassword: temporary });
    } catch (err) { next(err); }
  });

  router.post('/:id/reset-password', async (req, res, next) => {
    try {
      const target = store.findUserById(req.params.id);
      if (!target) return res.status(404).json({ error: 'not_found', message: 'No such person.' });

      const temporary = auth.generatePassword(20);
      const hash = await auth.hashPassword(temporary);
      await store.updateUsers((users) => {
        const row = users.find((u) => u.id === target.id);
        row.passwordHash = hash;
        row.mustChangePassword = true;
        row.failedCount = 0;
        row.lockedUntil = null;
        row.updatedAt = new Date().toISOString();
      });
      sessions.destroyForUser(target.id);
      await audit.write({ actor: req.user, action: 'reset_password', target: target.username, ip: clientIp(req) });
      res.json({ ok: true, temporaryPassword: temporary });
    } catch (err) { next(err); }
  });

  router.put('/:id', async (req, res, next) => {
    try {
      const target = store.findUserById(req.params.id);
      if (!target) return res.status(404).json({ error: 'not_found', message: 'No such person.' });

      const wantsDisabled = req.body && req.body.disabled === true;
      if (wantsDisabled) {
        if (target.id === req.user.id) {
          return res.status(422).json({ errors: [{ path: 'disabled', message: 'You cannot switch off your own account.' }] });
        }
        if (store.enabledAdmins().length <= 1) {
          return res.status(422).json({ errors: [{ path: 'disabled', message: 'This is the last account that can sign in. Add another one first.' }] });
        }
      }

      await store.updateUsers((users) => {
        const row = users.find((u) => u.id === target.id);
        if (req.body.displayName !== undefined) row.displayName = String(req.body.displayName).trim().slice(0, 80) || row.username;
        if (req.body.disabled !== undefined) row.disabled = !!req.body.disabled;
        row.updatedAt = new Date().toISOString();
      });
      if (wantsDisabled) sessions.destroyForUser(target.id);

      await audit.write({ actor: req.user, action: 'update_user', target: target.username, ip: clientIp(req) });
      res.json({ ok: true, user: store.publicUser(store.findUserById(target.id)) });
    } catch (err) { next(err); }
  });

  router.delete('/:id', async (req, res, next) => {
    try {
      const target = store.findUserById(req.params.id);
      if (!target) return res.status(404).json({ error: 'not_found', message: 'No such person.' });
      if (target.id === req.user.id) {
        return res.status(422).json({ errors: [{ path: 'id', message: 'You cannot delete your own account.' }] });
      }
      if (store.enabledAdmins().length <= 1) {
        return res.status(422).json({ errors: [{ path: 'id', message: 'This is the last account that can sign in. Add another one first.' }] });
      }

      await store.updateUsers((users) => {
        const index = users.findIndex((u) => u.id === target.id);
        if (index > -1) users.splice(index, 1);
      });
      sessions.destroyForUser(target.id);
      await audit.write({ actor: req.user, action: 'delete_user', target: target.username, ip: clientIp(req) });
      res.json({ ok: true });
    } catch (err) { next(err); }
  });

  return router;
}

module.exports = { createUserRoutes, USERNAME };
