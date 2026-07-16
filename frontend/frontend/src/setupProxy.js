const { createProxyMiddleware } = require('http-proxy-middleware');

/**
 * Local dev proxy — mirrors production nginx routing (frontend/nginx.conf).
 *
 * Order matters (most specific first):
 *   /api/v2/intelligence/* → Laravel :8000
 *   /api/v2/*              → semantic-gateway :8005 (path /v2/*)
 *   /api/enterprise|scim/* → cockpit-api :8001
 *   /api/*                 → Laravel :8000
 */
module.exports = function setupProxy(app) {
  const laravel = process.env.LARAVEL_PROXY_TARGET || 'http://localhost:8000';
  const enterprise = process.env.ENTERPRISE_PROXY_TARGET || 'http://localhost:8001';
  const v2 = process.env.V2_PROXY_TARGET || 'http://localhost:8005';

  app.use(
    '/api/v2/intelligence',
    createProxyMiddleware({
      target: laravel,
      changeOrigin: true,
      pathRewrite: (path) => `/api/v2/intelligence${path}`,
    }),
  );

  app.use(
    '/api/v2',
    createProxyMiddleware({
      target: v2,
      changeOrigin: true,
      pathRewrite: (path) => `/v2${path}`,
    }),
  );

  app.use(
    ['/api/enterprise', '/api/scim'],
    createProxyMiddleware({
      target: enterprise,
      changeOrigin: true,
      pathRewrite: (path) => `/api${path}`,
    }),
  );

  app.use(
    '/api',
    createProxyMiddleware({
      target: laravel,
      changeOrigin: true,
      pathRewrite: (path) => `/api${path}`,
    }),
  );
};
