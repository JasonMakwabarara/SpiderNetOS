'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { startServer, makeClient } = require('./helpers');

const GUARDED = [
  ['GET', '/api/admin/content'],
  ['PUT', '/api/admin/content/impact'],
  ['POST', '/api/admin/causes'],
  ['PUT', '/api/admin/causes/mhangura-surgeries'],
  ['DELETE', '/api/admin/causes/mhangura-surgeries'],
  ['POST', '/api/admin/sponsors/reorder'],
  ['GET', '/api/admin/users'],
  ['POST', '/api/admin/users'],
  ['POST', '/api/admin/uploads'],
  ['POST', '/api/admin/publish'],
  ['GET', '/api/admin/audit']
];

test('authorisation', async (t) => {
  const srv = await startServer();
  t.after(() => srv.stop());

  await t.test('every admin route refuses an anonymous caller', async () => {
    const anon = makeClient(srv.base);
    await anon.primeCsrf();
    for (const [method, url] of GUARDED) {
      const res = method === 'GET' ? await anon.get(url)
        : method === 'DELETE' ? await anon.del(url)
          : method === 'PUT' ? await anon.put(url, { value: {} })
            : await anon.post(url, { value: {} });
      assert.strictEqual(res.status, 401, `${method} ${url} should be 401, got ${res.status}`);
    }
  });

  await t.test('and nothing was changed by any of them', async () => {
    const { client } = await srv.signIn();
    const res = await client.get('/api/admin/content');
    assert.strictEqual(res.body.data.impact.livesHelped, 20);
    assert.strictEqual(res.body.data.causes.length, 2);
  });

  await t.test('a disabled account cannot use an existing session', async () => {
    const { client } = await srv.signIn();
    assert.strictEqual((await client.get('/api/auth/me')).status, 200);

    await srv.app.locals.store.updateUsers((users) => { users[0].disabled = true; });
    assert.strictEqual((await client.get('/api/admin/content')).status, 401);

    await srv.app.locals.store.updateUsers((users) => { users[0].disabled = false; });
  });

  await t.test('an owed password change blocks everything except changing it', async () => {
    const srv2 = await startServer();
    try {
      await srv2.app.locals.store.updateUsers((users) => { users[0].mustChangePassword = true; });
      const { client, res } = await srv2.signIn();
      assert.strictEqual(res.body.mustChangePassword, true);

      const blocked = await client.get('/api/admin/content');
      assert.strictEqual(blocked.status, 403);
      assert.strictEqual(blocked.body.error, 'password_change_required');

      assert.strictEqual((await client.get('/api/auth/me')).status, 200);
      const changed = await client.post('/api/auth/password', {
        currentPassword: srv2.password,
        newPassword: 'the rain in Harare falls mainly'
      });
      assert.strictEqual(changed.status, 200);
      assert.strictEqual((await client.get('/api/admin/content')).status, 200);
    } finally {
      await srv2.stop();
    }
  });

  await t.test('the last enabled account cannot be deleted or disabled', async () => {
    const { client } = await srv.signIn();
    const users = (await client.get('/api/admin/users')).body.users;
    const me = users[0];
    const removed = await client.del(`/api/admin/users/${me.id}`);
    assert.strictEqual(removed.status, 422);
    assert.match(removed.body.errors[0].message, /your own account/i);
  });
});
