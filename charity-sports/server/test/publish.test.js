'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fsp = require('node:fs').promises;
const vm = require('node:vm');
const path = require('node:path');
const { startServer } = require('./helpers');
const schema = require('../lib/schema');
const serialize = require('../lib/serialize');

/** Run the generated snapshot the way a browser would, in a sandbox.
 *  The result is round-tripped through JSON because objects created inside a
 *  vm context carry that context's prototypes, which deepStrictEqual rejects
 *  even when every value matches. */
function evaluateSnapshot(source) {
  const sandbox = { window: {} };
  vm.createContext(sandbox);
  new vm.Script(source).runInContext(sandbox, { timeout: 2000 });
  const data = sandbox.window.CHARITY_DATA;
  return data === undefined ? undefined : JSON.parse(JSON.stringify(data));
}

test('publishing', async (t) => {
  const srv = await startServer();
  t.after(() => srv.stop());
  const { client } = await srv.signIn();

  await t.test('status reports the snapshot as stale after an edit', async () => {
    const impact = (await client.get('/api/admin/content')).body.data.impact;
    await client.put('/api/admin/content/impact', { value: { ...impact, livesHelped: 41 } });
    const status = (await client.get('/api/admin/publish/status')).body;
    assert.strictEqual(status.dirty, true);
  });

  await t.test('publishing writes a snapshot the browser can actually read', async () => {
    const res = await client.post('/api/admin/publish', {});
    assert.strictEqual(res.status, 200);
    assert.strictEqual(res.body.wroteSnapshot, true);

    const source = await fsp.readFile(srv.config.snapshotPath, 'utf8');
    const data = evaluateSnapshot(source);
    assert.strictEqual(data.livesHelped, undefined, 'impact lives under its own key');
    assert.strictEqual(data.impact.livesHelped, 41);
    assert.strictEqual(data.schemaVersion, schema.SCHEMA_VERSION);
  });

  await t.test('the snapshot matches the public projection exactly', async () => {
    const source = await fsp.readFile(srv.config.snapshotPath, 'utf8');
    const data = evaluateSnapshot(source);
    const content = srv.app.locals.store.get();
    const expected = {
      schemaVersion: content.schemaVersion,
      updatedAt: content.updatedAt,
      ...schema.publicProjection(content)
    };
    assert.deepStrictEqual(data, expected);
  });

  await t.test('nothing in the snapshot can close a script element', async () => {
    const content = srv.app.locals.store.get();
    const poisoned = structuredClone(content);
    poisoned.data.org.mission = 'before </script><script>alert(1)</script> after';
    const text = serialize.snapshot(poisoned);
    assert.ok(!/<\/script/i.test(text), 'the closing tag must be escaped');
    const data = evaluateSnapshot(text);
    assert.match(data.org.mission, /alert\(1\)/, 'but the text itself is preserved');
  });

  await t.test('inactive items never reach the snapshot', async () => {
    const source = await fsp.readFile(srv.config.snapshotPath, 'utf8');
    const data = evaluateSnapshot(source);
    assert.strictEqual(data.sponsors.length, 16, 'the one inactive sponsor is left out');
    assert.ok(!data.sponsors.some((s) => s.active === false));
  });

  await t.test('publishing twice with no change is byte-identical', async () => {
    const first = await fsp.readFile(srv.config.snapshotPath, 'utf8');
    const again = await client.post('/api/admin/publish', {});
    assert.strictEqual(again.body.wroteSnapshot, false, 'nothing to do the second time');
    assert.strictEqual(await fsp.readFile(srv.config.snapshotPath, 'utf8'), first);
  });

  await t.test('the JSON-LD block in the page is rewritten and still parses', async () => {
    const html = await fsp.readFile(srv.config.indexPath, 'utf8');
    const m = /<!-- JSONLD:START -->[\s\S]*?<script type="application\/ld\+json">([\s\S]*?)<\/script>/.exec(html);
    assert.ok(m, 'the JSON-LD block should still be present');
    const parsed = JSON.parse(m[1]);
    assert.strictEqual(parsed['@context'], 'https://schema.org');

    const events = parsed['@graph'].filter((n) => n['@type'] === 'SportsEvent');
    assert.strictEqual(events.length, 5, 'five padel sessions, and none for the undated golf day');
    assert.ok(events.every((e) => e.startDate.endsWith('+02:00')), 'Harare offset must be explicit');
  });

  await t.test('the golf day contributes no dated event to the structured data', async () => {
    const html = await fsp.readFile(srv.config.indexPath, 'utf8');
    assert.ok(!/charity-golf-day/.test(html), 'an undated event has nothing to schedule');
  });
});
