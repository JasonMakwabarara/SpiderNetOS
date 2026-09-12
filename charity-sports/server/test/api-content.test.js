'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { startServer, makeClient } = require('./helpers');

test('the public content API', async (t) => {
  const srv = await startServer({ corsOrigins: ['https://jasonmakwabarara.github.io'] });
  t.after(() => srv.stop());
  const anon = makeClient(srv.base);

  await t.test('serves the public projection without a session', async () => {
    const res = await anon.get('/api/content');
    assert.strictEqual(res.status, 200);
    assert.strictEqual(res.body.data.sponsors.length, 16);
    assert.ok(res.body.updatedAt);
  });

  await t.test('never exposes accounts or hidden items', async () => {
    const res = await anon.get('/api/content');
    const text = JSON.stringify(res.body);
    assert.ok(!text.includes('passwordHash'));
    assert.ok(!text.includes('argon2'));
    assert.ok(!res.body.data.sponsors.some((s) => s.active === false));
  });

  await t.test('answers 304 when the caller already has it', async () => {
    const first = await anon.get('/api/content');
    const etag = first.headers.get('etag');
    assert.ok(etag);
    const second = await anon.get('/api/content', { headers: { 'If-None-Match': etag } });
    assert.strictEqual(second.status, 304);
  });

  await t.test('the tag changes when the content changes', async () => {
    const before = (await anon.get('/api/content')).headers.get('etag');
    const { client } = await srv.signIn();
    const impact = (await client.get('/api/admin/content')).body.data.impact;
    await client.put('/api/admin/content/impact', { value: { ...impact, livesHelped: 77 } });
    const after = (await anon.get('/api/content')).headers.get('etag');
    assert.notStrictEqual(before, after);
  });

  await t.test('only an allowed origin gets a CORS header', async () => {
    const allowed = await anon.get('/api/content', { headers: { Origin: 'https://jasonmakwabarara.github.io' } });
    assert.strictEqual(allowed.headers.get('access-control-allow-origin'), 'https://jasonmakwabarara.github.io');
    const refused = await anon.get('/api/content', { headers: { Origin: 'https://evil.example' } });
    assert.strictEqual(refused.headers.get('access-control-allow-origin'), null);
  });

  await t.test('a stale If-Match is refused with the current value', async () => {
    const { client } = await srv.signIn();
    const impact = (await client.get('/api/admin/content')).body.data.impact;
    const res = await client.put('/api/admin/content/impact',
      { value: { ...impact, livesHelped: 5 } },
      { headers: { 'If-Match': '2020-01-01T00:00:00.000Z' } });
    assert.strictEqual(res.status, 409);
    assert.strictEqual(res.body.error, 'conflict');
    assert.ok(res.body.current, 'should hand back the version to rebase on');
  });

  await t.test('a fresh If-Match goes through', async () => {
    const { client } = await srv.signIn();
    const current = await client.get('/api/admin/content');
    const res = await client.put('/api/admin/content/impact',
      { value: { ...current.body.data.impact, livesHelped: 6 } },
      { headers: { 'If-Match': current.body.updatedAt } });
    assert.strictEqual(res.status, 200);
  });

  await t.test('adding a cause and an event works end to end', async () => {
    const { client } = await srv.signIn();

    const cause = await client.post('/api/admin/causes', {
      value: { title: 'School shoes for Mutare', summary: 'Shoes for a term.', targetUsd: 800, status: 'current' }
    });
    assert.strictEqual(cause.status, 201);
    assert.strictEqual(cause.body.value.id, 'school-shoes-for-mutare');

    const event = await client.post('/api/admin/events', {
      value: { title: 'Charity Cricket Day', sport: 'Cricket', dateNote: 'Date to be announced' }
    });
    assert.strictEqual(event.status, 201);
    assert.strictEqual(event.body.value.startDate, null, 'an event may be announced before it has a date');

    const pub = (await makeClient(srv.base).get('/api/content')).body.data;
    assert.ok(pub.causes.some((c) => c.id === 'school-shoes-for-mutare'));
    assert.ok(pub.events.some((e) => e.id === 'charity-cricket-day'));
  });

  await t.test('reordering rejects a list that does not match', async () => {
    const { client } = await srv.signIn();
    const res = await client.post('/api/admin/sponsors/reorder', { ids: ['crystal', 'bhola'] });
    assert.strictEqual(res.status, 422);
  });

  await t.test('reordering with the right ids renumbers them', async () => {
    const { client } = await srv.signIn();
    const sponsors = (await client.get('/api/admin/content')).body.data.sponsors;
    const ids = sponsors.map((s) => s.id);
    const flipped = [ids[1], ids[0], ...ids.slice(2)];
    const res = await client.post('/api/admin/sponsors/reorder', { ids: flipped });
    assert.strictEqual(res.status, 200);
    const after = (await client.get('/api/admin/content')).body.data.sponsors;
    assert.deepStrictEqual(after.map((s) => s.id), flipped);
    assert.deepStrictEqual(after.map((s) => s.order), after.map((_, i) => i));
  });

  await t.test('a bad field is reported against its own path', async () => {
    const { client } = await srv.signIn();
    const res = await client.put('/api/admin/content/donate', {
      value: { url: 'not a url at all', provider: 'Contipay' }
    });
    assert.strictEqual(res.status, 422);
    assert.strictEqual(res.body.errors[0].path, 'url');
  });
});
