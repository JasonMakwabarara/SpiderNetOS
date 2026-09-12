'use strict';
const csrf = require('../lib/csrf');

function createRequireCsrf(config) {
  return function requireCsrf(req, res, next) {
    const result = csrf.verify(req, config.corsOrigins);
    if (result.ok) return next();
    return res.status(403).json({ error: result.reason, message: result.message });
  };
}

module.exports = { createRequireCsrf };
