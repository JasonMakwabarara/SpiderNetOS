'use strict';
/* One place where unexpected failures turn into a response. Stack traces
   never reach the client in production. */

function notFound(req, res) {
  if (req.path.startsWith('/api')) {
    return res.status(404).json({ error: 'not_found', message: 'No such endpoint.' });
  }
  return res.status(404).send('Not found');
}

function createErrorHandler(config) {
  // eslint-disable-next-line no-unused-vars
  return function errorHandler(err, req, res, next) {
    const status = err.status || err.statusCode || 500;

    if (err.code === 'LIMIT_FILE_SIZE') {
      return res.status(413).json({
        error: 'too_large',
        message: `That file is larger than ${Math.round(config.uploadMaxBytes / 1024 / 1024)} MB.`
      });
    }
    if (err.type === 'entity.too.large') {
      return res.status(413).json({ error: 'too_large', message: 'That request was too large.' });
    }
    if (err.type === 'entity.parse.failed') {
      return res.status(400).json({ error: 'bad_json', message: 'That request was not valid JSON.' });
    }

    if (status >= 500) {
      console.error('[error]', err.stack || err.message);
    }

    const body = {
      error: err.code || (status >= 500 ? 'server_error' : 'request_error'),
      message: status >= 500 && config.isProd
        ? 'Something went wrong on the server. Try again.'
        : err.message
    };
    if (!config.isProd && status >= 500) body.stack = err.stack;
    res.status(status).json(body);
  };
}

module.exports = { notFound, createErrorHandler };
