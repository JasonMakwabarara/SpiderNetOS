'use strict';
/* Publish: copy what has been saved into the static files the public site
   reads, and optionally commit them. */
const express = require('express');
const publisher = require('../lib/publish');
const { clientIp } = require('../lib/ratelimit');

function createPublishRoutes({ config, store, audit }) {
  const router = express.Router();

  router.get('/status', async (req, res, next) => {
    try {
      res.setHeader('Cache-Control', 'no-store');
      res.json(await publisher.status(store.get(), config));
    } catch (err) { next(err); }
  });

  router.post('/', async (req, res, next) => {
    try {
      const result = await publisher.publish(store.get(), config, { actor: req.user });
      await audit.write({
        actor: req.user,
        action: 'publish',
        target: config.publishTarget,
        summary: { snapshot: result.wroteSnapshot, jsonLd: result.wroteJsonLd, committed: result.committed },
        result: result.error ? 'partial' : 'ok',
        ip: clientIp(req)
      });
      res.json({ ok: !result.error, ...result });
    } catch (err) { next(err); }
  });

  return router;
}

module.exports = { createPublishRoutes };
