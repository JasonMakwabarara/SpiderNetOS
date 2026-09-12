'use strict';
const test = require('node:test');
const assert = require('node:assert');
const schema = require('../lib/schema');

test('schema validation', async (t) => {
  await t.test('rejects a non-https donate link', () => {
    const bad = schema.validate('donate', { url: 'javascript:alert(1)' });
    assert.strictEqual(bad.ok, false);
    assert.match(bad.errors[0].message, /https/);
  });

  await t.test('rejects a path that escapes assets/', () => {
    const bad = schema.validate('sponsors', { name: 'X', tier: 'supporter', logo: '../../etc/passwd' });
    assert.strictEqual(bad.ok, false);
    assert.strictEqual(bad.errors[0].path, 'logo');
  });

  await t.test('rejects an impossible date', () => {
    const bad = schema.validate('events', { title: 'X', startDate: '2026-02-31' });
    assert.strictEqual(bad.ok, false);
    assert.match(bad.errors[0].message, /does not exist/);
  });

  await t.test('rejects an end date before the start date', () => {
    const bad = schema.validate('events', { title: 'X', startDate: '2026-09-10', endDate: '2026-09-01' });
    assert.strictEqual(bad.ok, false);
    assert.strictEqual(bad.errors[0].path, 'events.endDate');
  });

  await t.test('accepts an event with no date at all', () => {
    const ok = schema.validate('events', { title: 'Charity Golf Day', sport: 'Golf', dateNote: 'Date to be announced' });
    assert.strictEqual(ok.ok, true);
    assert.strictEqual(ok.value.startDate, null);
  });

  await t.test('requires alt text on a photo', () => {
    const bad = schema.validate('gallery', { type: 'photo', src: 'assets/img/x.jpg' });
    assert.strictEqual(bad.ok, false);
    assert.match(bad.errors[0].message, /screen reader/);
  });

  await t.test('extracts a YouTube id from a full link', () => {
    const ok = schema.validate('gallery', {
      type: 'video', provider: 'youtube', youtubeId: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'
    });
    assert.strictEqual(ok.ok, true);
    assert.strictEqual(ok.value.youtubeId, 'dQw4w9WgXcQ');
  });

  await t.test('rejects milestones that do not increase', () => {
    const bad = schema.validate('impact', { goal: 1000000, milestones: [1000, 500] });
    assert.strictEqual(bad.ok, false);
    assert.match(bad.errors[0].message, /increase/);
  });

  await t.test('normalise fills defaults and renumbers', () => {
    const out = schema.normalise({ data: { org: { name: 'X' }, causes: [
      { title: 'B', order: 9 }, { title: 'A', order: 3 }
    ] } });
    assert.strictEqual(out.schemaVersion, schema.SCHEMA_VERSION);
    assert.deepStrictEqual(out.data.causes.map((c) => [c.title, c.order]), [['A', 0], ['B', 1]]);
    assert.strictEqual(out.data.causes[0].id, 'a');
  });

  await t.test('normalise keeps only one featured event', () => {
    const out = schema.normalise({ data: { org: { name: 'X' }, events: [
      { title: 'One', featured: true }, { title: 'Two', featured: true }
    ] } });
    assert.deepStrictEqual(out.data.events.map((e) => e.featured), [true, false]);
  });

  await t.test('unknown keys survive but stay out of the public projection', () => {
    const out = schema.normalise({ data: { org: { name: 'X' }, futureThing: { a: 1 } } });
    assert.deepStrictEqual(out.data.futureThing, { a: 1 });
    assert.strictEqual(schema.publicProjection(out).futureThing, undefined);
  });

  await t.test('public projection drops inactive items', () => {
    const out = schema.normalise({ data: { org: { name: 'X' }, sponsors: [
      { name: 'Shown', tier: 'supporter' }, { name: 'Hidden', tier: 'supporter', active: false }
    ] } });
    const pub = schema.publicProjection(out);
    assert.deepStrictEqual(pub.sponsors.map((s) => s.name), ['Shown']);
  });

  await t.test('ids stay unique when titles collide', () => {
    const out = schema.normalise({ data: { org: { name: 'X' }, causes: [
      { title: 'Same' }, { title: 'Same' }
    ] } });
    assert.deepStrictEqual(out.data.causes.map((c) => c.id), ['same', 'same-2']);
  });
});
