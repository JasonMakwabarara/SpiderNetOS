'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { startServer, makeClient } = require('./helpers');

test('backup and restore', async (t) => {
  const srv = await startServer();
  t.after(() => srv.stop());
  const { client } = await srv.signIn();

  let saved = null;

  await t.test('a backup downloads as a named file', async () => {
    const res = await client.get('/api/admin/backup');
    assert.strictEqual(res.status, 200);
    assert.match(res.headers.get('content-disposition') || '', /attachment; filename="charity-sports-backup-\d{4}-\d{2}-\d{2}\.json"/);
    saved = typeof res.body === 'string' ? JSON.parse(res.body) : res.body;
    assert.strictEqual(saved.data.impact.livesHelped, 20);
    assert.strictEqual(saved.data.sponsors.length, 17, 'a backup keeps hidden items too');
  });

  await t.test('a backup carries no accounts or password hashes', async () => {
    const text = JSON.stringify(saved);
    assert.ok(!text.includes('passwordHash'));
    assert.ok(!text.includes('argon2'));
  });

  await t.test('restoring puts back exactly what was taken', async () => {
    await client.put('/api/admin/content/impact', {
      value: { ...saved.data.impact, livesHelped: 999 }
    });
    assert.strictEqual((await client.get('/api/admin/content')).body.data.impact.livesHelped, 999);

    const res = await client.post('/api/admin/backup/restore', { content: saved });
    assert.strictEqual(res.status, 200);
    assert.strictEqual((await client.get('/api/admin/content')).body.data.impact.livesHelped, 20);
  });

  await t.test('a restore is itself undoable, because it backs up first', async () => {
    const fs = require('node:fs');
    const path = require('node:path');
    const kept = fs.readdirSync(path.join(srv.config.contentDir, 'backups'));
    assert.ok(kept.length > 0, 'the content before the restore should be on disk');
  });

  await t.test('rubbish is refused rather than wiping the site', async () => {
    const before = (await client.get('/api/admin/content')).body.data.impact.livesHelped;

    for (const bad of [null, 'not an object', 42, { nothing: true }]) {
      const res = await client.post('/api/admin/backup/restore', { content: bad });
      assert.ok(res.status === 422, `expected 422 for ${JSON.stringify(bad)}, got ${res.status}`);
    }
    assert.strictEqual((await client.get('/api/admin/content')).body.data.impact.livesHelped, before,
      'a refused restore must change nothing');
  });

  await t.test('a backup from a newer version is refused with a clear reason', async () => {
    const res = await client.post('/api/admin/backup/restore', {
      content: { ...saved, schemaVersion: 99 }
    });
    assert.strictEqual(res.status, 422);
    assert.match(res.body.errors[0].message, /newer version/i);
  });

  await t.test('neither route is reachable without signing in', async () => {
    const anon = makeClient(srv.base);
    await anon.primeCsrf();
    assert.strictEqual((await anon.get('/api/admin/backup')).status, 401);
    assert.strictEqual((await anon.post('/api/admin/backup/restore', { content: saved })).status, 401);
  });
});

test('history filtering', async (t) => {
  const srv = await startServer();
  t.after(() => srv.stop());
  const { client } = await srv.signIn();

  const impact = (await client.get('/api/admin/content')).body.data.impact;
  await client.put('/api/admin/content/impact', { value: { ...impact, livesHelped: 21 } });
  await client.post('/api/admin/causes', { value: { title: 'A test cause', summary: 'x' } });

  await t.test('offers the people and actions actually recorded', async () => {
    const page = (await client.get('/api/admin/audit')).body;
    assert.ok(page.actors.includes('admin'));
    assert.ok(page.actions.includes('login'));
    assert.ok(page.actions.includes('update_section'));
    assert.ok(page.actions.includes('create_item'));
  });

  await t.test('narrowing by action searches the whole history', async () => {
    const all = (await client.get('/api/admin/audit')).body;
    const filtered = (await client.get('/api/admin/audit?action=create_item')).body;
    assert.ok(filtered.total < all.total);
    assert.ok(filtered.entries.every((e) => e.action === 'create_item'));
    assert.strictEqual(filtered.total, filtered.entries.length);
  });

  await t.test('narrowing by a person who did nothing returns nothing', async () => {
    const page = (await client.get('/api/admin/audit?actor=nobody')).body;
    assert.strictEqual(page.total, 0);
    assert.deepStrictEqual(page.entries, []);
  });

  await t.test('a nonsense filter is ignored rather than erroring', async () => {
    const page = (await client.get('/api/admin/audit?action=' + encodeURIComponent('../../etc/passwd'))).body;
    assert.ok(page.total > 0, 'an unusable filter falls back to showing everything');
  });
});
