'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { startServer } = require('./helpers');

test('cross-site request forgery', async (t) => {
  const srv = await startServer();
  t.after(() => srv.stop());
  const { client } = await srv.signIn();

  const body = { value: { livesHelped: 999, goal: 1000000 } };

  await t.test('a request with no token is refused', async () => {
    const res = await client.put('/api/admin/content/impact', body, { omitCsrf: true });
    assert.strictEqual(res.status, 403);
    assert.strictEqual(res.body.error, 'csrf');
  });

  await t.test('a request with the wrong token is refused', async () => {
    const res = await client.put('/api/admin/content/impact', body, {
      headers: { 'X-CSRF-Token': 'not-the-real-token' }
    });
    assert.strictEqual(res.status, 403);
  });

  await t.test('a request from another origin is refused', async () => {
    const res = await client.put('/api/admin/content/impact', body, { origin: 'https://evil.example' });
    assert.strictEqual(res.status, 403);
    assert.strictEqual(res.body.error, 'origin');
  });

  await t.test('a mutation with no Origin header at all is refused', async () => {
    const res = await client.put('/api/admin/content/impact', body, { origin: null });
    assert.strictEqual(res.status, 403);
  });

  await t.test('none of those refusals changed anything', async () => {
    const res = await client.get('/api/admin/content');
    assert.strictEqual(res.body.data.impact.livesHelped, 20);
  });

  await t.test('the proper request succeeds', async () => {
    const res = await client.put('/api/admin/content/impact', {
      value: { ...((await client.get('/api/admin/content')).body.data.impact), livesHelped: 21 }
    });
    assert.strictEqual(res.status, 200);
    assert.strictEqual((await client.get('/api/admin/content')).body.data.impact.livesHelped, 21);
  });

  await t.test('reading needs no token', async () => {
    const res = await client.get('/api/admin/content', { omitCsrf: true });
    assert.strictEqual(res.status, 200);
  });
});
