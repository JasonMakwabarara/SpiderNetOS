'use strict';
const test = require('node:test');
const assert = require('node:assert');
const auth = require('../lib/auth');

test('password hashing and policy', async (t) => {
  await t.test('hash verifies against the right password only', async () => {
    const hash = await auth.hashPassword('a proper long passphrase');
    assert.ok(await auth.verifyPassword(hash, 'a proper long passphrase'));
    assert.strictEqual(await auth.verifyPassword(hash, 'a proper long passphrasf'), false);
  });

  await t.test('a hash never contains the password', async () => {
    const hash = await auth.hashPassword('hunter2-hunter2-hunter2');
    assert.ok(!hash.includes('hunter2'));
  });

  await t.test('scrypt hashes still verify', async () => {
    /* Proves the fallback path works even where argon2 is available. */
    const crypto = require('node:crypto');
    const { promisify } = require('node:util');
    const scrypt = promisify(crypto.scrypt);
    const salt = crypto.randomBytes(16);
    const key = await scrypt('legacy password here', salt, 32,
      { N: 1 << 15, r: 8, p: 1, maxmem: 64 * 1024 * 1024 });
    const legacy = ['scrypt', 1 << 15, 8, 1, salt.toString('base64'), key.toString('base64')].join('$');
    assert.ok(await auth.verifyPassword(legacy, 'legacy password here'));
    assert.strictEqual(await auth.verifyPassword(legacy, 'wrong'), false);
    if (auth.hasArgon()) assert.ok(auth.needsRehash(legacy), 'scrypt hashes should be upgraded');
  });

  await t.test('policy rejects short, common and self-referential passwords', () => {
    assert.ok(auth.checkPasswordPolicy('short').length);
    assert.ok(auth.checkPasswordPolicy('charitysports').length);
    assert.ok(auth.checkPasswordPolicy('adminadminadmin', { username: 'admin' }).length);
    assert.ok(auth.checkPasswordPolicy('aaaaaaaaaaaaaaaa').length);
    assert.deepStrictEqual(auth.checkPasswordPolicy('a quiet Thursday in Borrowdale'), []);
  });

  await t.test('generated passwords are long and varied', () => {
    const a = auth.generatePassword(24);
    const b = auth.generatePassword(24);
    assert.strictEqual(a.length, 24);
    assert.notStrictEqual(a, b);
    assert.ok(new Set(a).size > 8, 'should not be a handful of repeated characters');
  });

  await t.test('safeEqual compares without leaking length mismatches as errors', () => {
    assert.ok(auth.safeEqual('abc', 'abc'));
    assert.strictEqual(auth.safeEqual('abc', 'abd'), false);
    assert.strictEqual(auth.safeEqual('abc', 'abcd'), false);
  });
});
