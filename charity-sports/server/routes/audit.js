'use strict';
const express = require('express');

function createAuditRoutes({ audit }) {
  const router = express.Router();
  router.get('/', async (req, res, next) => {
    try {
      const limit = Math.min(Math.max(Number(req.query.limit) || 50, 1), 200);
      const before = Math.max(Number(req.query.before) || 0, 0);
      res.setHeader('Cache-Control', 'no-store');
      res.json(await audit.read({ limit, before }));
    } catch (err) { next(err); }
  });
  return router;
}

module.exports = { createAuditRoutes };
