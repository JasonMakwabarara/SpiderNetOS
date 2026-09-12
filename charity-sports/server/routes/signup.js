'use strict';
/* Supporter sign-ups.
 *
 * Public, so it is deliberately narrow: two short fields, a honeypot, a hard
 * rate limit per address, a cap on the file, and no reflection of anything
 * submitted. The list is stored on the charity's own server rather than a
 * mailing service, so nothing about their supporters leaves their control. */
const express = require('express');
const path = require('path');
const crypto = require('crypto');
const { readJson, writeJsonAtomic, createMutex } = require('../lib/atomic');
const { createLimiter, clientIp } = require('../lib/ratelimit');
const { cleanString } = require('../lib/schema');

const MAX_ENTRIES = 20000;
const EMAIL = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;

function createSignupRoutes({ config, audit }) {
  const router = express.Router();
  const file = path.join(config.contentDir, 'signups.json');
  const mutate = createMutex();

  /* Generous on purpose. Many Zimbabwean mobile users share one address, and
     a room full of people signing up at an event must not lock each other out.
     The honeypot and the duplicate check do most of the work; this is only a
     ceiling on how fast the file can grow. */
  const limiter = createLimiter({
    windowMs: 60 * 1000,
    max: Math.max(1, config.signupRatePerMin || 10)
  });

  router.post('/', async (req, res, next) => {
    try {
      const gate = limiter.take(clientIp(req));
      if (!gate.allowed) {
        res.setHeader('Retry-After', String(gate.retryAfter));
        return res.status(429).json({
          error: 'too_many',
          message: 'That came through very quickly. Give it a minute and try again, or message us on WhatsApp.'
        });
      }

      /* A field no person sees and no person fills in. */
      if (req.body && String(req.body.website || '').trim()) {
        return res.status(200).json({ ok: true });          // look successful, store nothing
      }

      const name = cleanString(String((req.body && req.body.name) || '')) || '';
      const contact = cleanString(String((req.body && req.body.contact) || '')) || '';
      const errors = [];

      if (name.length < 2 || name.length > 80) {
        errors.push({ path: 'name', message: 'Please give a name we can use.' });
      }
      const digits = contact.replace(/\D/g, '');
      const looksLikePhone = digits.length >= 8 && digits.length <= 15;
      const looksLikeEmail = contact.length <= 254 && EMAIL.test(contact);
      if (!looksLikePhone && !looksLikeEmail) {
        errors.push({ path: 'contact', message: 'A WhatsApp number or an email address, so we can reach you.' });
      }
      if (errors.length) return res.status(422).json({ errors });

      const entry = {
        id: crypto.randomUUID(),
        name: name.slice(0, 80),
        contact: contact.slice(0, 254),
        kind: looksLikeEmail ? 'email' : 'phone',
        source: cleanString(String((req.body && req.body.source) || 'website')).slice(0, 40),
        addedAt: new Date().toISOString()
      };

      const stored = await mutate(async () => {
        const list = (await readJson(file, [])) || [];
        if (!Array.isArray(list)) throw new Error('The sign-up list is not readable.');
        if (list.length >= MAX_ENTRIES) {
          const err = new Error('The sign-up list is full.');
          err.status = 507;
          throw err;
        }
        /* Somebody pressing the button twice should not appear twice. */
        const already = list.some((row) => row.contact.toLowerCase() === entry.contact.toLowerCase());
        if (!already) {
          list.push(entry);
          await writeJsonAtomic(file, list, { backupDir: config.backupDir });
        }
        return { added: !already, total: list.length };
      });

      if (stored.added) {
        /* The name is not written to the audit log: it records that somebody
           signed up, not who. */
        await audit.write({ action: 'signup', target: entry.kind, ip: clientIp(req) });
      }
      res.status(201).json({ ok: true });
    } catch (err) {
      if (err.status) return res.status(err.status).json({ error: 'full', message: err.message });
      next(err);
    }
  });

  return router;
}

/** The admin side: read the list and take it away as a spreadsheet. */
function createSignupAdminRoutes({ config, audit }) {
  const router = express.Router();
  const file = path.join(config.contentDir, 'signups.json');

  router.get('/', async (req, res, next) => {
    try {
      const list = (await readJson(file, [])) || [];
      res.setHeader('Cache-Control', 'no-store');
      res.json({ signups: list.slice().reverse(), total: list.length });
    } catch (err) { next(err); }
  });

  router.get('/csv', async (req, res, next) => {
    try {
      const list = (await readJson(file, [])) || [];
      const escape = (value) => '"' + String(value == null ? '' : value).replace(/"/g, '""') + '"';
      const rows = [['Name', 'Contact', 'Kind', 'Source', 'Added'].map(escape).join(',')];
      list.forEach((row) => {
        rows.push([row.name, row.contact, row.kind, row.source, row.addedAt].map(escape).join(','));
      });
      const stamp = new Date().toISOString().slice(0, 10);
      res.setHeader('Content-Type', 'text/csv; charset=utf-8');
      res.setHeader('Content-Disposition', `attachment; filename="charity-sports-supporters-${stamp}.csv"`);
      res.setHeader('Cache-Control', 'no-store');
      await audit.write({ actor: req.user, action: 'export_signups', ip: clientIp(req) });
      res.send('﻿' + rows.join('\r\n') + '\r\n');
    } catch (err) { next(err); }
  });

  router.delete('/:id', async (req, res, next) => {
    try {
      const list = (await readJson(file, [])) || [];
      const index = list.findIndex((row) => row.id === req.params.id);
      if (index === -1) return res.status(404).json({ error: 'not_found', message: 'That person is not on the list.' });
      list.splice(index, 1);
      await writeJsonAtomic(file, list, { backupDir: config.backupDir });
      await audit.write({ actor: req.user, action: 'delete_signup', ip: clientIp(req) });
      res.json({ ok: true });
    } catch (err) { next(err); }
  });

  return router;
}

module.exports = { createSignupRoutes, createSignupAdminRoutes, MAX_ENTRIES };
