'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const fsp = fs.promises;
const os = require('node:os');
const path = require('node:path');
const { writeJsonAtomic, readJson, createMutex } = require('../lib/atomic');

test('atomic writes', async (t) => {
  const dir = await fsp.mkdtemp(path.join(os.tmpdir(), 'cs-atomic-'));
  const file = path.join(dir, 'content.json');
  const backups = path.join(dir, 'backups');
  t.after(() => fsp.rm(dir, { recursive: true, force: true }));

  await t.test('writes a file that reads back', async () => {
    await writeJsonAtomic(file, { a: 1 }, { backupDir: backups });
    assert.deepStrictEqual(await readJson(file), { a: 1 });
  });

  await t.test('keeps a backup of the previous version', async () => {
    await writeJsonAtomic(file, { a: 2 }, { backupDir: backups });
    const kept = await fsp.readdir(backups);
    assert.strictEqual(kept.length, 1);
    assert.deepStrictEqual(JSON.parse(await fsp.readFile(path.join(backups, kept[0]), 'utf8')), { a: 1 });
  });

  await t.test('leaves the original intact when the write fails', async () => {
    const before = await fsp.readFile(file, 'utf8');
    const circular = {};
    circular.self = circular;
    await assert.rejects(() => writeJsonAtomic(file, circular, { backupDir: backups }));
    assert.strictEqual(await fsp.readFile(file, 'utf8'), before);
    const strays = (await fsp.readdir(dir)).filter((n) => n.includes('.tmp-'));
    assert.deepStrictEqual(strays, [], 'temp files should be cleaned up');
  });

  await t.test('50 concurrent updates all apply', async () => {
    const mutate = createMutex();
    const counter = path.join(dir, 'counter.json');
    await writeJsonAtomic(counter, { n: 0 });
    await Promise.all(Array.from({ length: 50 }, () => mutate(async () => {
      const current = await readJson(counter);
      current.n += 1;
      await writeJsonAtomic(counter, current);
    })));
    assert.strictEqual((await readJson(counter)).n, 50);
  });
});
