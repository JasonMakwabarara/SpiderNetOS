'use strict';
/* Download a copy of everything, and put one back.
 *
 * The store already keeps timestamped backups in content/backups/, but those
 * need shell access to reach. The people running this site do not have shell
 * access, so without this they have no way to take a copy before a risky edit
 * and no way to undo one afterwards. */
const express = require('express');
const schema = require('../lib/schema');
const { clientIp } = require('../lib/ratelimit');

function createBackupRoutes({ store, audit }) {
  const router = express.Router();

  router.get('/', async (req, res, next) => {
    try {
      const content = store.get();
      const stamp = new Date().toISOString().slice(0, 10);
      res.setHeader('Content-Type', 'application/json; charset=utf-8');
      res.setHeader('Content-Disposition', `attachment; filename="charity-sports-backup-${stamp}.json"`);
      res.setHeader('Cache-Control', 'no-store');
      await audit.write({ actor: req.user, action: 'backup', ip: clientIp(req) });
      res.send(JSON.stringify(content, null, 2));
    } catch (err) { next(err); }
  });

  router.post('/restore', async (req, res, next) => {
    try {
      const incoming = req.body && req.body.content;
      if (!incoming || typeof incoming !== 'object') {
        return res.status(422).json({
          errors: [{ path: 'content', message: 'That file did not contain a backup.' }]
        });
      }

      const version = Number(incoming.schemaVersion || 0);
      if (version > schema.SCHEMA_VERSION) {
        return res.status(422).json({
          errors: [{
            path: 'content',
            message: `That backup is from a newer version of the site (v${version}). Update the server first.`
          }]
        });
      }

      const normalised = schema.normalise(incoming);
      if (normalised.warnings && normalised.warnings.length) {
        return res.status(422).json({
          errors: normalised.warnings.slice(0, 20),
          message: 'That backup has problems and was not restored.'
        });
      }

      /* Going through store.update means the current content is backed up to
         content/backups/ first, so a restore is itself undoable. */
      const result = await store.update((draft) => {
        Object.keys(draft).forEach((key) => { delete draft[key]; });
        Object.assign(draft, normalised.data);
      }, {
        actor: req.user,
        action: 'restore',
        target: incoming.updatedAt || 'backup',
        summary: { restoredFrom: incoming.updatedAt || 'unknown date' },
        ip: clientIp(req),
        ua: req.get('user-agent')
      });

      if (result.errors) return res.status(422).json({ errors: result.errors });
      res.json({ ok: true, updatedAt: result.updatedAt });
    } catch (err) { next(err); }
  });

  return router;
}

module.exports = { createBackupRoutes };
