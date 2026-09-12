'use strict';
/* Upload handling.
 *
 * A file is held in memory and checked before anything touches the disk:
 * magic bytes must agree with the declared type, sharp must be able to decode
 * it, and the stored name is generated rather than taken from the client.
 *
 * SVGs are rasterised rather than sanitised. Rendering to PNG and throwing the
 * original away removes scripts, event handlers, foreignObject and external
 * fetches in one step, with no sanitiser to keep patched. Sponsor logos only
 * ever need a raster tile, so nothing of value is lost. */
const path = require('path');
const fs = require('fs');
const fsp = fs.promises;
const crypto = require('crypto');
const sharp = require('sharp');
const { writeTextAtomic } = require('./atomic');

const MAX_SVG_BYTES = 2 * 1024 * 1024;
const MAX_PIXELS = 50e6;

const PRESETS = {
  logo: {
    variants: [{ suffix: '', width: 600, height: 400, fit: 'inside', format: 'png' },
               { suffix: '', width: 600, height: 400, fit: 'inside', format: 'webp' }],
    keys: { png: 'logo', webp: 'logoWebp' }
  },
  gallery: {
    variants: [{ suffix: '', width: 1400, format: 'jpeg' },
               { suffix: '', width: 1400, format: 'webp' },
               { suffix: '-480', width: 480, format: 'jpeg' },
               { suffix: '-480', width: 480, format: 'webp' }],
    keys: { jpeg: 'src', webp: 'webp', 'jpeg-480': 'thumb', 'webp-480': 'thumbWebp' }
  },
  hero: {
    variants: [{ suffix: '', width: 1280, format: 'jpeg' },
               { suffix: '', width: 1280, format: 'webp' },
               { suffix: '-800', width: 800, format: 'jpeg' },
               { suffix: '-800', width: 800, format: 'webp' }],
    keys: { jpeg: 'src', webp: 'webp', 'jpeg-800': 'srcSmall', 'webp-800': 'webpSmall' }
  }
};
PRESETS.poster = PRESETS.gallery;
PRESETS.cause = PRESETS.hero;

/** What the bytes actually are, ignoring what the client claimed. */
function sniff(buffer) {
  if (!buffer || buffer.length < 12) return null;
  if (buffer[0] === 0x89 && buffer[1] === 0x50 && buffer[2] === 0x4e && buffer[3] === 0x47 &&
      buffer[4] === 0x0d && buffer[5] === 0x0a && buffer[6] === 0x1a && buffer[7] === 0x0a) return 'png';
  if (buffer[0] === 0xff && buffer[1] === 0xd8 && buffer[2] === 0xff) return 'jpeg';
  if (buffer.slice(0, 4).toString('latin1') === 'RIFF' && buffer.slice(8, 12).toString('latin1') === 'WEBP') return 'webp';

  /* SVG is text, so look at the head of the file rather than a signature.
     A DOCTYPE is recognised here on purpose, even though assertSafeSvg then
     refuses it: classifying it as SVG means the caller can say exactly why,
     instead of falling through to a vague "that is not an image". */
  const head = buffer.slice(0, 2048).toString('utf8').replace(/^\uFEFF/, '').trimStart();
  const prologue = /^((<\?xml[^>]*\?>|<!--[\s\S]*?-->|<!DOCTYPE\s+svg[^>[]*(\[[\s\S]*?\])?\s*>)\s*)*<svg[\s>]/i;
  if (prologue.test(head)) return 'svg';
  return null;
}

const DECLARED = {
  png: ['image/png'],
  jpeg: ['image/jpeg', 'image/jpg'],
  webp: ['image/webp'],
  svg: ['image/svg+xml', 'text/xml', 'application/xml']
};

class UploadError extends Error {
  constructor(status, message) { super(message); this.status = status; }
}

/** Reject an SVG that could blow up an XML parser before sharp sees it. */
function assertSafeSvg(buffer) {
  if (buffer.length > MAX_SVG_BYTES) {
    throw new UploadError(413, 'That SVG is too large. Keep it under 2 MB, or upload a PNG.');
  }
  const text = buffer.toString('utf8');
  if (/<!DOCTYPE/i.test(text) || /<!ENTITY/i.test(text)) {
    throw new UploadError(415, 'That SVG contains a document type declaration, which is not allowed. Export it as a PNG instead.');
  }
}

function safeBaseName(original) {
  const base = path.basename(String(original || 'image'));       // strips any directory
  const stem = base.replace(/\.[^.]+$/, '');
  const slug = stem
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 40);
  return slug || 'image';
}

function encode(pipeline, format) {
  if (format === 'png') return pipeline.png({ compressionLevel: 9 });
  if (format === 'webp') return pipeline.webp({ quality: 80 });
  return pipeline.jpeg({ quality: 78, progressive: true, mozjpeg: true });
}

/**
 * Validate, convert and store an upload.
 * @returns {{ref: object, files: string[]}} an image ref ready to drop into content
 */
async function storeUpload({ buffer, originalName, declaredType, kind, config }) {
  const preset = PRESETS[kind];
  if (!preset) throw new UploadError(400, `Unknown upload kind "${kind}".`);

  const actual = sniff(buffer);
  if (!actual) {
    throw new UploadError(415, 'That file is not a PNG, JPEG, WebP or SVG image.');
  }
  if (declaredType && !DECLARED[actual].includes(String(declaredType).toLowerCase().split(';')[0].trim())) {
    throw new UploadError(415, `That file says it is ${declaredType} but its contents are ${actual}.`);
  }
  if (actual === 'svg') assertSafeSvg(buffer);
  if (actual === 'svg' && !config.allowSvgUpload) {
    /* Rasterise at a high density so the result is crisp, then forget the
       original bytes entirely. */
    buffer = await sharp(buffer, { density: 288, limitInputPixels: MAX_PIXELS }).png().toBuffer();
  }

  let meta;
  try {
    meta = await sharp(buffer, { limitInputPixels: MAX_PIXELS }).metadata();
  } catch (err) {
    throw new UploadError(415, 'That image could not be read. Try exporting it again as a PNG or JPEG.');
  }
  if (!meta.width || !meta.height) {
    throw new UploadError(415, 'That image has no readable size.');
  }

  await fsp.mkdir(config.uploadDir, { recursive: true });

  const stem = `${safeBaseName(originalName)}-${crypto.randomBytes(4).toString('hex')}`;
  const ref = {};
  const written = [];

  for (const variant of preset.variants) {
    let pipeline = sharp(buffer, { limitInputPixels: MAX_PIXELS })
      .resize({
        width: variant.width,
        height: variant.height || null,
        fit: variant.fit || 'inside',
        withoutEnlargement: true
      });

    const ext = variant.format === 'jpeg' ? 'jpg' : variant.format;
    const fileName = `${stem}${variant.suffix}.${ext}`;
    const dest = path.join(config.uploadDir, fileName);

    /* Belt and braces: the resolved path must still sit inside the upload
       directory after every bit of name handling above. */
    const resolved = path.resolve(dest);
    if (!resolved.startsWith(path.resolve(config.uploadDir) + path.sep)) {
      throw new UploadError(400, 'Refusing to write outside the uploads folder.');
    }

    const info = await encode(pipeline, variant.format).toFile(resolved);
    written.push(resolved);

    const key = preset.keys[`${variant.format}${variant.suffix}`] || preset.keys[variant.format];
    if (key) ref[key] = `${config.uploadUrlBase}/${fileName}`;
    if (!variant.suffix && !ref.width) { ref.width = info.width; ref.height = info.height; }
  }

  return { ref, files: written, sniffed: actual, originalName: safeBaseName(originalName) };
}

/** Remove an uploaded file, refusing to step outside the uploads folder. */
async function removeUpload(fileName, config) {
  const base = path.basename(String(fileName || ''));
  const dest = path.resolve(config.uploadDir, base);
  if (!dest.startsWith(path.resolve(config.uploadDir) + path.sep)) {
    throw new UploadError(400, 'That is not a file in the uploads folder.');
  }
  await fsp.unlink(dest);
  return base;
}

module.exports = { storeUpload, removeUpload, sniff, safeBaseName, UploadError, PRESETS, MAX_SVG_BYTES };
