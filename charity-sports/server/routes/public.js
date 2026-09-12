'use strict';
/* The only endpoint the public site talks to, and the health check.
   Both are read-only and neither needs a session. */
const express = require('express');

function createPublicRoutes({ config, store, schema }) {
  const router = express.Router();

  router.get('/health', (req, res) => {
    res.json({
      ok: true,
      schemaVersion: schema.SCHEMA_VERSION,
      uptimeSeconds: Math.round(process.uptime())
    });
  });

  router.get('/content', (req, res) => {
    const { body, etag } = store.publicJson();

    /* Only the sites we were told about may read this cross-origin. */
    const origin = req.get('origin');
    if (origin && config.corsOrigins.includes(origin)) {
      res.setHeader('Access-Control-Allow-Origin', origin);
      res.setHeader('Vary', 'Origin');
    }

    res.setHeader('ETag', etag);
    res.setHeader('Cache-Control', 'no-cache');
    if (req.get('if-none-match') === etag) return res.status(304).end();
    res.json(body);
  });

  return router;
}

module.exports = { createPublicRoutes };
