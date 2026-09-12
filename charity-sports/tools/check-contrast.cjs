#!/usr/bin/env node
/**
 * Reads the colour tokens straight out of the stylesheets and checks every
 * pair that actually appears on the site against WCAG AA.
 *
 *   node tools/check-contrast.cjs
 *
 * This exists because a contrast failure is invisible to the person making
 * the change. Adjusting one token by a shade is easy; noticing that it dropped
 * the small print below 4.5:1 is not.
 */
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');

function readTokens(file) {
  const css = fs.readFileSync(path.join(ROOT, file), 'utf8');
  const tokens = {};
  const re = /(--c-[a-z0-9-]+)\s*:\s*(#[0-9a-fA-F]{3,8})\s*;/g;
  let match;
  while ((match = re.exec(css))) tokens[match[1]] = match[2];
  return tokens;
}

function luminance(hex) {
  let h = hex.replace('#', '');
  if (h.length === 3) h = h.split('').map((c) => c + c).join('');
  const channels = h.slice(0, 6).match(/../g).map((pair) => {
    const v = parseInt(pair, 16) / 255;
    return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
  });
  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}

function ratio(a, b) {
  const x = luminance(a), y = luminance(b);
  return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}

/* Each entry is a pair that genuinely appears on screen. "large" means the
   text is at least 24px, or 18.7px bold, where WCAG allows 3:1. */
const PUBLIC_PAIRS = [
  ['body text on cream', '--c-ink', '--c-bg'],
  ['muted text on cream', '--c-ink-soft', '--c-bg'],
  ['small print on cream', '--c-ink-faint', '--c-bg'],
  ['small print on alt cream', '--c-ink-faint', '--c-bg-alt'],
  ['section eyebrow', '--c-green-ink', '--c-bg'],
  ['eyebrow on alt cream', '--c-green-ink', '--c-bg-alt'],
  ['the counter number', '--c-orange-ink', '--c-bg-alt', 'large'],
  ['text on the dark tiles', '--c-on-dark', '--c-charcoal'],
  ['sponsor tagline on tiles', '--c-on-dark-soft', '--c-charcoal-2'],
  ['gold on charcoal', '--c-gold', '--c-charcoal'],
  ['link colour on cream', '--c-orange-ink', '--c-bg'],
  ['link colour on card', '--c-orange-ink', '--c-card']
];

const ADMIN_PAIRS = [
  ['admin body text', '--c-ink', '--c-bg'],
  ['admin muted text', '--c-ink-soft', '--c-bg'],
  ['admin small print', '--c-ink-faint', '--c-bg'],
  ['admin small print on a card', '--c-ink-faint', '--c-panel'],
  ['sidebar link', '--c-on-dark-soft', '--c-sidebar'],
  ['sidebar heading', '--c-on-dark', '--c-sidebar']
];

function run() {
  const pub = readTokens('assets/css/styles.css');
  const admin = readTokens('admin/admin.css');
  const failures = [];
  let checked = 0;

  function check(label, fg, bg, size) {
    if (!fg || !bg) { failures.push(`${label}: a colour could not be read from the stylesheet`); return; }
    const need = size === 'large' ? 3 : 4.5;
    const value = ratio(fg, bg);
    checked++;
    const ok = value >= need;
    if (!ok) failures.push(`${label}: ${value.toFixed(2)}:1, needs ${need}:1  (${fg} on ${bg})`);
    console.log(`  ${ok ? 'ok  ' : 'FAIL'}  ${value.toFixed(2).padStart(5)}:1  ${label}`);
  }

  console.log('Public site');
  PUBLIC_PAIRS.forEach(([label, fg, bg, size]) => check(label, pub[fg], pub[bg], size));

  console.log('\nAdmin panel');
  ADMIN_PAIRS.forEach(([label, fg, bg, size]) => check(label, admin[fg], admin[bg], size));

  console.log('\nFixed colours');
  check('white on the donate button', '#FFFFFF', pub['--c-orange-ink']);
  check('white on the green button', '#FFFFFF', pub['--c-green-ink']);
  check('admin unpublished badge', admin['--c-gold-deep'], '#FDF0CD');

  console.log(`\n${checked - failures.length}/${checked} pairs meet WCAG AA`);
  if (failures.length) {
    console.log('\nFailures:\n' + failures.map((f) => '  - ' + f).join('\n'));
    process.exit(1);
  }
}

run();
