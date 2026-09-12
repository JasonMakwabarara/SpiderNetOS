'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fsp = require('node:fs').promises;
const path = require('node:path');
const { startServer, makeClient } = require('./helpers');

test('supporter sign-ups', async (t) => {
  /* Every request in a test comes from 127.0.0.1, so the per-address limit
     would otherwise cut the suite short. It gets its own server below. */
  const srv = await startServer({ signupRatePerMin: 1000 });
  t.after(() => srv.stop());
  const file = path.join(srv.config.contentDir, 'signups.json');

  await t.test('a real sign-up is accepted without signing in', async () => {
    const anon = makeClient(srv.base);
    const res = await anon.post('/api/signup', {
      name: 'Tendai Moyo', contact: '+263 771 234 567', website: ''
    });
    assert.strictEqual(res.status, 201);
    const list = JSON.parse(await fsp.readFile(file, 'utf8'));
    assert.strictEqual(list.length, 1);
    assert.strictEqual(list[0].name, 'Tendai Moyo');
    assert.strictEqual(list[0].kind, 'phone');
  });

  await t.test('an email address is recognised as one', async () => {
    const anon = makeClient(srv.base);
    const res = await anon.post('/api/signup', { name: 'Rudo', contact: 'rudo@example.com' });
    assert.strictEqual(res.status, 201);
    const list = JSON.parse(await fsp.readFile(file, 'utf8'));
    assert.strictEqual(list[list.length - 1].kind, 'email');
  });

  await t.test('a missing or nonsense contact is refused against its field', async () => {
    const anon = makeClient(srv.base);
    const res = await anon.post('/api/signup', { name: 'Someone', contact: 'hello' });
    assert.strictEqual(res.status, 422);
    assert.strictEqual(res.body.errors[0].path, 'contact');
  });

  await t.test('a one-letter name is refused', async () => {
    const anon = makeClient(srv.base);
    const res = await anon.post('/api/signup', { name: 'x', contact: 'a@b.com' });
    assert.strictEqual(res.status, 422);
    assert.strictEqual(res.body.errors[0].path, 'name');
  });

  await t.test('the honeypot silently swallows a bot', async () => {
    const before = JSON.parse(await fsp.readFile(file, 'utf8')).length;
    const anon = makeClient(srv.base);
    const res = await anon.post('/api/signup', {
      name: 'Bot', contact: 'bot@example.com', website: 'http://spam.example'
    });
    assert.strictEqual(res.status, 200, 'it should look like success');
    const after = JSON.parse(await fsp.readFile(file, 'utf8')).length;
    assert.strictEqual(after, before, 'but nothing may be stored');
  });

  await t.test('signing up twice does not appear twice', async () => {
    const anon = makeClient(srv.base);
    await anon.post('/api/signup', { name: 'Rudo Again', contact: 'rudo@example.com' });
    const list = JSON.parse(await fsp.readFile(file, 'utf8'));
    const matches = list.filter((row) => row.contact === 'rudo@example.com');
    assert.strictEqual(matches.length, 1);
  });

  await t.test('a flood from one address is cut off', async () => {
    const tight = await startServer({ signupRatePerMin: 3 });
    try {
      const anon = makeClient(tight.base);
      const codes = [];
      for (let i = 0; i < 6; i++) {
        const res = await anon.post('/api/signup', { name: 'Flood ' + i, contact: `flood${i}@example.com` });
        codes.push(res.status);
      }
      assert.ok(codes.includes(429), `expected a 429 among ${codes.join(',')}`);
      assert.ok(codes.slice(0, 3).every((c) => c === 201), 'the first few must still get through');
    } finally {
      await tight.stop();
    }
  });

  await t.test('the list is never exposed without signing in', async () => {
    const anon = makeClient(srv.base);
    assert.strictEqual((await anon.get('/api/admin/signups')).status, 401);
    assert.strictEqual((await anon.get('/api/admin/signups/csv')).status, 401);

    /* And nothing about supporters leaks through the public content. */
    const pub = await anon.get('/api/content');
    assert.ok(!JSON.stringify(pub.body).includes('rudo@example.com'));
  });

  await t.test('an admin can read the list and take it as a spreadsheet', async () => {
    const { client } = await srv.signIn();
    const listed = await client.get('/api/admin/signups');
    assert.strictEqual(listed.status, 200);
    assert.ok(listed.body.total >= 2);

    const csv = await client.get('/api/admin/signups/csv');
    assert.strictEqual(csv.status, 200);
    assert.match(csv.headers.get('content-disposition') || '', /attachment; filename=".*\.csv"/);
    assert.match(csv.body, /Tendai Moyo/);
    assert.match(csv.body, /^﻿?"Name","Contact"/);
  });

  await t.test('a spreadsheet formula in a name cannot execute when opened', async () => {
    const anon = makeClient(srv.base);
    await anon.post('/api/signup', { name: '=cmd|calc', contact: 'formula@example.com' });
    const { client } = await srv.signIn();
    const csv = (await client.get('/api/admin/signups/csv')).body;
    /* Quoted, so a spreadsheet treats it as text rather than a formula. */
    assert.match(csv, /"=cmd\|calc"/);
  });

  await t.test('an admin can remove somebody who asks', async () => {
    const { client } = await srv.signIn();
    const list = (await client.get('/api/admin/signups')).body.signups;
    const target = list.find((row) => row.contact === 'rudo@example.com');
    const res = await client.del('/api/admin/signups/' + target.id);
    assert.strictEqual(res.status, 200);
    const after = (await client.get('/api/admin/signups')).body.signups;
    assert.ok(!after.some((row) => row.contact === 'rudo@example.com'));
  });

  await t.test('the history records that somebody signed up, not who', async () => {
    const log = await fsp.readFile(path.join(srv.config.contentDir, 'audit.log'), 'utf8');
    assert.match(log, /"action":"signup"/);
    assert.ok(!log.includes('Tendai Moyo'), 'names must not be written to the history');
    assert.ok(!log.includes('rudo@example.com'));
  });
});
