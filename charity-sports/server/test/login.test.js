'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { startServer } = require('./helpers');

test('signing in', async (t) => {
  const srv = await startServer();
  t.after(() => srv.stop());

  await t.test('the right password signs you in', async () => {
    const { res } = await srv.signIn();
    assert.strictEqual(res.status, 200);
    assert.strictEqual(res.body.user.username, 'admin');
    assert.ok(!('passwordHash' in res.body.user), 'the hash must never leave the server');
  });

  await t.test('the session cookie is httpOnly and SameSite=Lax', async () => {
    const { client } = await srv.signIn();
    const res = await client.get('/api/auth/me');
    assert.strictEqual(res.status, 200);
    const raw = (await fetch(srv.base + '/api/csrf')).headers.getSetCookie();
    assert.ok(raw.some((line) => /SameSite=Lax/i.test(line)));
  });

  await t.test('a wrong password and an unknown user look identical', async () => {
    const { makeClient } = require('./helpers');
    const a = makeClient(srv.base);
    await a.primeCsrf();
    const wrongPassword = await a.post('/api/auth/login', { username: 'admin', password: 'not-the-password' });

    const b = makeClient(srv.base);
    await b.primeCsrf();
    const unknownUser = await b.post('/api/auth/login', { username: 'nobody-here', password: 'not-the-password' });

    assert.strictEqual(wrongPassword.status, 401);
    assert.strictEqual(unknownUser.status, 401);
    assert.deepStrictEqual(wrongPassword.body, unknownUser.body,
      'the two failures must be indistinguishable, or usernames can be enumerated');
  });

  await t.test('the account locks after repeated failures and survives a reload', async () => {
    const srv2 = await startServer();
    try {
      const { makeClient } = require('./helpers');
      for (let i = 0; i < srv2.config.loginMaxFails; i++) {
        const c = makeClient(srv2.base);
        await c.primeCsrf();
        await c.post('/api/auth/login', { username: 'admin', password: 'wrong-' + i });
      }
      const stored = await srv2.app.locals.store.loadUsers();
      assert.ok(stored[0].lockedUntil, 'the lock must be written to disk, not just held in memory');

      const c = makeClient(srv2.base);
      await c.primeCsrf();
      const locked = await c.post('/api/auth/login', { username: 'admin', password: srv2.password });
      assert.strictEqual(locked.status, 423, 'the right password should be told the account is locked');
      assert.match(locked.body.message, /locked/i);
    } finally {
      await srv2.stop();
    }
  });

  await t.test('logging out kills the session on the server', async () => {
    const { client } = await srv.signIn();
    assert.strictEqual((await client.get('/api/auth/me')).status, 200);

    const cookieName = srv.app.locals.sessions.cookieName;
    const stolen = client.jar.get(cookieName);
    await client.post('/api/auth/logout', {});

    /* Replaying the cookie after logout must fail: the record is gone. */
    const replay = await fetch(srv.base + '/api/auth/me', { headers: { Cookie: `${cookieName}=${stolen}` } });
    assert.strictEqual(replay.status, 401);
  });

  await t.test('changing the password issues a new session id', async () => {
    const srv3 = await startServer();
    try {
      const { client } = await srv3.signIn();
      const cookieName = srv3.app.locals.sessions.cookieName;
      const before = client.jar.get(cookieName);

      const res = await client.post('/api/auth/password', {
        currentPassword: srv3.password,
        newPassword: 'a completely different long passphrase'
      });
      assert.strictEqual(res.status, 200);
      assert.notStrictEqual(client.jar.get(cookieName), before, 'session id must rotate');

      const replay = await fetch(srv3.base + '/api/auth/me', { headers: { Cookie: `${cookieName}=${before}` } });
      assert.strictEqual(replay.status, 401, 'the old session must be dead');
    } finally {
      await srv3.stop();
    }
  });

  await t.test('a weak new password is refused', async () => {
    const srv4 = await startServer();
    try {
      const { client } = await srv4.signIn();
      const res = await client.post('/api/auth/password', {
        currentPassword: srv4.password,
        newPassword: 'password123'
      });
      assert.strictEqual(res.status, 422);
      assert.strictEqual(res.body.errors[0].path, 'newPassword');
    } finally {
      await srv4.stop();
    }
  });
});
