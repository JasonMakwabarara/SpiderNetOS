#!/usr/bin/env node
/**
 * Print an image's metadata and optionally write a ruler-grid overlay so crop
 * rectangles can be measured instead of guessed.
 *
 *   node tools/measure-image.cjs <file> [--grid] [--step 100] [--out dir]
 */
const path = require('path');
const fs = require('fs');
const sharp = require('sharp');

function parseArgs(argv) {
  const args = { file: null, grid: false, step: 100, out: null };
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--grid') args.grid = true;
    else if (a === '--step') args.step = Number(argv[++i]);
    else if (a === '--out') args.out = argv[++i];
    else if (!args.file) args.file = a;
  }
  return args;
}

function gridSvg(width, height, step) {
  const parts = [`<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}">`];
  for (let x = step; x < width; x += step) {
    const major = x % (step * 5) === 0;
    parts.push(
      `<line x1="${x}" y1="0" x2="${x}" y2="${height}" stroke="${major ? '#ff0000' : '#00ffff'}" ` +
      `stroke-width="${major ? 2 : 1}" stroke-opacity="${major ? 0.9 : 0.45}"/>`,
      `<text x="${x + 4}" y="18" font-family="monospace" font-size="16" fill="#ff0000" ` +
      `stroke="#ffffff" stroke-width="0.6">${x}</text>`
    );
  }
  for (let y = step; y < height; y += step) {
    const major = y % (step * 5) === 0;
    parts.push(
      `<line x1="0" y1="${y}" x2="${width}" y2="${y}" stroke="${major ? '#ff0000' : '#00ffff'}" ` +
      `stroke-width="${major ? 2 : 1}" stroke-opacity="${major ? 0.9 : 0.45}"/>`,
      `<text x="4" y="${y - 4}" font-family="monospace" font-size="16" fill="#ff0000" ` +
      `stroke="#ffffff" stroke-width="0.6">${y}</text>`
    );
  }
  parts.push('</svg>');
  return Buffer.from(parts.join(''));
}

(async () => {
  const args = parseArgs(process.argv);
  if (!args.file) {
    console.error('usage: measure-image.cjs <file> [--grid] [--step N] [--out dir]');
    process.exit(1);
  }
  const meta = await sharp(args.file).metadata();
  console.log(JSON.stringify({
    file: args.file,
    format: meta.format,
    width: meta.width,
    height: meta.height,
    orientation: meta.orientation ?? null,
    hasAlpha: meta.hasAlpha,
    bytes: fs.statSync(args.file).size
  }, null, 2));

  if (args.grid) {
    const outDir = args.out || path.dirname(args.file);
    fs.mkdirSync(outDir, { recursive: true });
    const dest = path.join(outDir, path.basename(args.file, path.extname(args.file)) + '.grid.png');
    await sharp(args.file)
      .composite([{ input: gridSvg(meta.width, meta.height, args.step), top: 0, left: 0 }])
      .png()
      .toFile(dest);
    console.log('grid written:', dest);
  }
})().catch((err) => { console.error(err.message); process.exit(1); });
