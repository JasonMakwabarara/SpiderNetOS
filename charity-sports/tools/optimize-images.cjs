#!/usr/bin/env node
/**
 * One-time source-image pipeline for the Charity Sports site.
 *
 *   NODE_PATH=<scratch>/node_modules node tools/optimize-images.cjs --src <dir> --out assets
 *
 * Reads the original photographs and posters, writes optimised JPEG/WebP/PNG
 * variants into assets/, and prints the real dimensions of every output so they
 * can be pasted into data/site-data.js.
 *
 * Sharp strips metadata by default, which also removes the GPS tags carried by
 * the phone photographs. Do not add .withMetadata().
 */
const fs = require('fs');
const path = require('path');
const sharp = require('sharp');

const JPEG = { quality: 78, progressive: true, mozjpeg: true };
const JPEG_THUMB = { quality: 72, progressive: true, mozjpeg: true };
const WEBP = { quality: 78 };
const WEBP_THUMB = { quality: 70 };

function parseArgs(argv) {
  const args = { src: null, out: null };
  for (let i = 2; i < argv.length; i++) {
    if (argv[i] === '--src') args.src = argv[++i];
    else if (argv[i] === '--out') args.out = argv[++i];
  }
  if (!args.src || !args.out) {
    console.error('usage: optimize-images.cjs --src <dir> --out <assets dir>');
    process.exit(1);
  }
  return args;
}

const results = [];
async function emit(pipeline, outPath, encode) {
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  const info = await encode(pipeline.clone()).toFile(outPath);
  results.push({
    file: path.relative(process.cwd(), outPath),
    width: info.width,
    height: info.height,
    kb: Math.round(fs.statSync(outPath).size / 1024)
  });
  return info;
}

/** Write a .jpg + .webp pair, resized to fit inside `w` without enlarging. */
async function pair(src, outBase, w, { thumb = false, extract = null, rotate = 0, height = null } = {}) {
  let p = sharp(src);
  if (rotate) p = p.rotate(rotate);
  if (extract) p = p.extract(extract);
  /* A height cap matters for the tall posters: they are narrow, so a width
     cap never bites and the bytes all sit in the vertical dimension. */
  p = p.resize({ width: w, height: height, fit: 'inside', withoutEnlargement: true });
  const info = await emit(p, `${outBase}.jpg`, (x) => x.jpeg(thumb ? JPEG_THUMB : JPEG));
  await emit(p, `${outBase}.webp`, (x) => x.webp(thumb ? WEBP_THUMB : WEBP));
  return info;
}

/** Circular alpha mask, so the round logo mark sits on any background. */
function circleMask(size) {
  const r = size / 2;
  return Buffer.from(
    `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}">` +
    `<circle cx="${r}" cy="${r}" r="${r}" fill="#fff"/></svg>`
  );
}

(async () => {
  const { src, out } = parseArgs(process.argv);
  const S = (name) => path.join(src, name);
  const O = (rel) => path.join(out, rel);

  const DOCTORS = S('ba04b31d-image.jpg');        // 1280x853  medical outreach team
  const FLYER   = S('cc0a12f9-image.jpg');        // 3072x4080 prize sponsors flyer, upright
  const FLYER_R = S('828a44d2-image.jpg');        // 4080x3072 same flyer, rotated, with tickets
  const ESCAPE  = S('13a87dbb-image.jpg');        // 853x1280  "The Great Padel Escape" poster
  const BARGAIN = S('c7326f25-image.jpg');        // 793x1280  "The Padel Bargain" poster
  const HAI_DARK  = S('e80bceab-image.png');      // 1080x2424 Hannah AI on dark
  const HAI_LIGHT = S('59e9f59d-image.png');      // 962x921   Hannah AI on light

  // --- Hero -----------------------------------------------------------------
  await pair(DOCTORS, O('img/hero-doctors-1280'), 1280);
  await pair(DOCTORS, O('img/hero-doctors-800'), 800);

  // Open Graph / Twitter card: a fixed 1200x630 crop.
  await emit(
    sharp(DOCTORS).resize(1200, 630, { fit: 'cover', position: 'centre' }),
    O('img/og-image.jpg'),
    (x) => x.jpeg({ quality: 82, progressive: true, mozjpeg: true })
  );

  // --- Gallery --------------------------------------------------------------
  await pair(DOCTORS, O('img/gallery/doctors-outreach'), 1400);
  await pair(DOCTORS, O('img/gallery/doctors-outreach-480'), 480, { thumb: true });

  // The flyer photograph: crop away the paving and most of the hand.
  const flyerBox = { left: 620, top: 515, width: 2000, height: 2850 };
  await pair(FLYER, O('img/gallery/prize-sponsors-flyer'), 1400, { extract: flyerBox });
  await pair(FLYER, O('img/gallery/prize-sponsors-flyer-480'), 480, { extract: flyerBox, thumb: true });

  // The landscape shot of the same flyer with the ticket stubs. EXIF orientation
  // is 1, so auto-orient does nothing: the 90 degree rotation must be explicit.
  await pair(FLYER_R, O('img/gallery/flyer-and-tickets'), 1400, { rotate: 90 });
  await pair(FLYER_R, O('img/gallery/flyer-and-tickets-480'), 480, { rotate: 90, thumb: true });

  /* Posters are only ever seen full size inside the lightbox, so 1200px at a
     slightly lower quality is plenty. They were the two heaviest files on the
     site, which matters on Zimbabwean mobile data. */
  await pair(ESCAPE, O('img/gallery/poster-padel-escape'), 1200, { thumb: true, height: 1100 });
  await pair(ESCAPE, O('img/gallery/poster-padel-escape-480'), 480, { thumb: true });

  await pair(BARGAIN, O('img/gallery/poster-padel-bargain'), 1200, { thumb: true, height: 1100 });
  await pair(BARGAIN, O('img/gallery/poster-padel-bargain-480'), 480, { thumb: true });

  // --- Hannah AI ------------------------------------------------------------
  // Sponsor tiles are dark, so the tile comes from the dark-background source,
  // which already carries a white wordmark. Box measured from a ruler overlay.
  const tileBox = { left: 60, top: 1140, width: 940, height: 300 };
  const tile = sharp(HAI_DARK).extract(tileBox).resize({ width: 600, withoutEnlargement: true });
  await emit(tile, O('logos/hannah-ai-tile.png'), (x) => x.png({ compressionLevel: 9 }));
  await emit(tile, O('logos/hannah-ai-tile.webp'), (x) => x.webp({ quality: 88 }));

  // The light source is higher resolution for the disc itself. Bounds found by
  // scanning for dark pixels: centre (166.5, 464), diameter 232.
  const markBox = { left: 48, top: 346, width: 236, height: 236 };
  for (const size of [256, 96]) {
    const mark = sharp(HAI_LIGHT)
      .extract(markBox)
      .resize(size, size, { fit: 'cover' })
      .composite([{ input: circleMask(size), blend: 'dest-in' }]);
    await emit(mark, O(`logos/hannah-ai-mark-${size}.png`), (x) => x.png({ compressionLevel: 9 }));
  }

  // Light-background lockup, kept as the fallback if the dark tile ever reads badly.
  const lightBox = { left: 30, top: 330, width: 900, height: 270 };
  await emit(
    sharp(HAI_LIGHT).extract(lightBox).resize({ width: 600, withoutEnlargement: true }),
    O('logos/hannah-ai-tile-light.png'),
    (x) => x.png({ compressionLevel: 9 })
  );

  console.table(results);
  const total = results.reduce((n, r) => n + r.kb, 0);
  console.log(`${results.length} files, ${total} KB total`);
  const oversized = results.filter((r) => r.kb > 300);
  if (oversized.length) {
    console.warn('Over 300 KB:', oversized.map((r) => `${r.file} (${r.kb} KB)`).join(', '));
  }
})().catch((err) => { console.error(err); process.exit(1); });
