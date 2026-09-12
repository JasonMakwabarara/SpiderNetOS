#!/usr/bin/env node
/**
 * Drives the public site in a real browser and asserts the things that are
 * easy to break: console errors, layout overflow, the counter, the event
 * past/upcoming split, lightbox keyboard behaviour and link targets.
 *
 *   NODE_PATH=<scratch>/node_modules node tools/verify-public.cjs \
 *     --url http://127.0.0.1:8080/ --shots <dir>
 */
const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');

/* Resolve the pre-installed Chromium. The exact folder carries a build
   number, so probe rather than hard-code, and fall back to Playwright's own
   lookup (PLAYWRIGHT_BROWSERS_PATH is already set in this environment). */
function findChromium() {
  const candidates = [
    process.env.CHROMIUM_PATH,
    '/opt/pw-browsers/chromium/chrome-linux/chrome'
  ].filter(Boolean);
  const root = process.env.PLAYWRIGHT_BROWSERS_PATH || '/opt/pw-browsers';
  try {
    for (const entry of fs.readdirSync(root)) {
      if (/^chromium-\d+$/.test(entry)) candidates.push(path.join(root, entry, 'chrome-linux', 'chrome'));
    }
    for (const entry of fs.readdirSync(root)) {
      if (/^chromium_headless_shell-\d+$/.test(entry)) {
        candidates.push(path.join(root, entry, 'chrome-linux', 'headless_shell'));
      }
    }
  } catch (err) { /* fall through to Playwright's own resolution */ }
  return candidates.find((c) => { try { return fs.existsSync(c); } catch (e) { return false; } }) || undefined;
}

function parseArgs(argv) {
  const args = { url: 'http://127.0.0.1:8080/', shots: null, file: null };
  for (let i = 2; i < argv.length; i++) {
    if (argv[i] === '--url') args.url = argv[++i];
    else if (argv[i] === '--shots') args.shots = argv[++i];
    else if (argv[i] === '--file') args.file = argv[++i];
  }
  return args;
}

const results = [];
function check(name, ok, detail) {
  results.push({ name, ok: !!ok, detail: detail == null ? '' : String(detail) });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
}

async function newPage(browser, width, height, extra = {}) {
  const context = await browser.newContext({ viewport: { width, height }, ...extra });
  const page = await context.newPage();
  const errors = [];
  /* Two things are expected and are not site defects:
     - Google Fonts is unreachable inside this sandbox.
     - /api/content 404s on a static host; live.js is built to swallow that. */
  const expected = (text) =>
    /fonts\.(googleapis|gstatic)\.com/.test(text) ||
    /\/api\/content/.test(text) ||
    /ERR_CONNECTION_RESET|ERR_NAME_NOT_RESOLVED|ERR_INTERNET_DISCONNECTED/.test(text) ||
    /the server responded with a status of 404/.test(text);

  page.on('console', (msg) => {
    if (msg.type() !== 'error') return;
    const text = msg.text();
    const url = (msg.location() && msg.location().url) || '';
    if (expected(text) || expected(url)) return;
    errors.push(text);
  });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  page.on('requestfailed', (req) => {
    if (expected(req.url())) return;
    errors.push('requestfailed: ' + req.url());
  });
  return { context, page, errors };
}

async function noOverflow(page) {
  return page.evaluate(() => {
    const de = document.documentElement;
    return { scroll: de.scrollWidth, client: de.clientWidth };
  });
}

(async () => {
  const args = parseArgs(process.argv);
  if (args.shots) fs.mkdirSync(args.shots, { recursive: true });

  const browser = await chromium.launch({
    executablePath: findChromium(),
    args: ['--no-sandbox', '--disable-dev-shm-usage']
  });

  /* ---------------------------------------------------------- desktop pass */
  {
    const { context, page, errors } = await newPage(browser, 1280, 900);
    await page.goto(args.url, { waitUntil: 'networkidle' });
    await page.waitForSelector('html[data-ready="1"]', { timeout: 8000 });

    check('desktop renders without console errors', errors.length === 0, errors.slice(0, 3).join(' | '));

    const heading = await page.textContent('h1');
    check('h1 present', !!heading && heading.trim().length > 0, heading);

    const h1Count = await page.locator('h1').count();
    check('exactly one h1', h1Count === 1, `found ${h1Count}`);

    // Counter: scroll it into view and let the animation settle.
    await page.locator('#impact').scrollIntoViewIfNeeded();
    await page.waitForTimeout(2200);
    const counterText = (await page.textContent('#counterValue')).trim();
    check('counter settles on 20', counterText === '20', counterText);

    const ariaNow = await page.getAttribute('#counterBar', 'aria-valuenow');
    check('progressbar reports 20', ariaNow === '20', ariaNow);

    const fillWidth = await page.evaluate(() => document.getElementById('counterFill').style.width);
    check('progress bar keeps a visible sliver', parseFloat(fillWidth) >= 1.5, fillWidth);

    const nextMilestone = await page.textContent('.milestones li.is-next');
    check('next milestone is 1,000', /1,000/.test(nextMilestone || ''), nextMilestone);

    // Events: 2 past sessions, 3 upcoming, countdown targets 17 September.
    const sessionStatuses = await page.$$eval('.schedule li', (els) => els.map((e) => e.dataset.status));
    const past = sessionStatuses.filter((s) => s === 'past').length;
    const ahead = sessionStatuses.filter((s) => s !== 'past').length;
    check('two padel sessions played', past === 2, `past=${past} ahead=${ahead}`);
    check('three padel sessions to come', ahead === 3, `ahead=${ahead}`);

    const countdownTarget = await page.getAttribute('.countdown', 'data-target');
    const targetDate = new Date(Number(countdownTarget)).toISOString();
    check('countdown targets 17 Sept 19:00 Harare', targetDate === '2026-09-17T17:00:00.000Z', targetDate);

    // The golf day is announced without a date.
    const tbc = await page.locator('[data-status="tbc"]').count();
    check('golf day shows as date-to-be-announced', tbc === 1, `found ${tbc}`);
    const golfText = await page.textContent('[data-status="tbc"]');
    check('golf day offers register-interest', /Register interest/i.test(golfText || ''));
    const golfCountdown = await page.locator('[data-status="tbc"] .countdown').count();
    check('undated event has no countdown', golfCountdown === 0);

    // Causes
    const causeCards = await page.locator('.cause-card').count();
    check('both current causes render', causeCards === 2, `found ${causeCards}`);
    const openAppeal = await page.locator('.cause-open').count();
    check('Kariba reads as an open appeal', openAppeal === 1, `found ${openAppeal}`);

    // Sponsors
    const tiles = await page.locator('.sponsor-tile').count();
    const ctaTiles = await page.locator('.sponsor-tile.is-cta').count();
    check('16 sponsors plus the open-slot tile', tiles === 17 && ctaTiles === 1, `tiles=${tiles} cta=${ctaTiles}`);
    const hannahLogo = await page.locator('.sponsor-tile[data-sponsor-id="hannah-ai"] img').count();
    check('Hannah AI renders its logo', hannahLogo === 1);

    // Gallery + lightbox keyboard behaviour
    const galleryItems = await page.locator('.gallery-item').count();
    check('gallery shows five items', galleryItems === 5, `found ${galleryItems}`);

    await page.locator('.gallery-btn').first().click();
    await page.waitForTimeout(350);
    const dialogOpen = await page.evaluate(() => document.getElementById('lightbox').open);
    check('lightbox opens', dialogOpen === true);

    const focusInside = await page.evaluate(() =>
      document.getElementById('lightbox').contains(document.activeElement));
    check('focus moves into the lightbox', focusInside === true);

    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(200);
    const count = await page.textContent('#lightboxCount');
    check('arrow key advances the lightbox', /^2 \/ 5$/.test((count || '').trim()), count);

    await page.keyboard.press('Escape');
    await page.waitForTimeout(300);
    const dialogClosed = await page.evaluate(() => !document.getElementById('lightbox').open);
    check('Escape closes the lightbox', dialogClosed === true);
    const focusRestored = await page.evaluate(() =>
      document.activeElement && document.activeElement.classList.contains('gallery-btn'));
    check('focus returns to the trigger', focusRestored === true);

    // Filters
    const chipCount = await page.locator('#galleryFilters .chip').count();
    check('gallery filter chips render', chipCount === 3, `found ${chipCount}`);
    await page.locator('.chip[data-filter="poster"]').click();
    await page.waitForTimeout(200);
    const posters = await page.locator('.gallery-item').count();
    check('poster filter narrows the grid', posters === 3, `found ${posters}`);
    await page.locator('.chip[data-filter="all"]').click();

    // Links
    const links = await page.$$eval('a[href]', (els) => els.map((e) => ({
      href: e.getAttribute('href'),
      rel: e.getAttribute('rel') || '',
      target: e.getAttribute('target') || ''
    })));
    check('Contipay donate link present', links.some((l) => /donate\.contipay\.co\.zw/.test(l.href)));
    check('WhatsApp link present', links.some((l) => /wa\.me\/263776437764/.test(l.href)));
    check('mailto link present', links.some((l) => /^mailto:charitysport@yahoo\.com/.test(l.href)));
    check('Google Maps link present', links.some((l) => /google\.com\/maps/.test(l.href)));

    const rootRelative = links.filter((l) => /^\//.test(l.href));
    check('no root-relative links', rootRelative.length === 0, rootRelative.map((l) => l.href).join(', '));

    const unsafeExternal = links.filter((l) => l.target === '_blank' && !/noopener/.test(l.rel));
    check('every new-tab link carries noopener', unsafeExternal.length === 0,
      unsafeExternal.map((l) => l.href).join(', '));

    const srcs = await page.$$eval('img[src], source[srcset]', (els) => els.map((e) =>
      e.getAttribute('src') || e.getAttribute('srcset')));
    check('no root-relative images', !srcs.some((s) => /^\//.test(s)));

    const noAlt = await page.$$eval('img', (els) => els.filter((e) => !e.hasAttribute('alt')).length);
    check('every image has an alt attribute', noAlt === 0, `missing ${noAlt}`);

    const dupeIds = await page.evaluate(() => {
      const seen = new Set(), dupes = [];
      document.querySelectorAll('[id]').forEach((e) => {
        if (seen.has(e.id)) dupes.push(e.id); else seen.add(e.id);
      });
      return dupes;
    });
    check('no duplicate element ids', dupeIds.length === 0, dupeIds.join(', '));

    const dims = await noOverflow(page);
    check('no horizontal overflow at 1280px', dims.scroll <= dims.client, `${dims.scroll} > ${dims.client}`);

    if (args.shots) {
      await page.screenshot({ path: path.join(args.shots, 'desktop-1280.png'), fullPage: true });
      await page.locator('.gallery-btn').first().click();
      await page.waitForTimeout(400);
      await page.screenshot({ path: path.join(args.shots, 'lightbox.png') });
      await page.keyboard.press('Escape');
    }
    await context.close();
  }

  /* ----------------------------------------------------------- mobile pass */
  {
    const { context, page, errors } = await newPage(browser, 400, 860, { isMobile: false });
    await page.goto(args.url, { waitUntil: 'networkidle' });
    await page.waitForSelector('html[data-ready="1"]', { timeout: 8000 });
    check('mobile renders without console errors', errors.length === 0, errors.slice(0, 3).join(' | '));

    let dims = await noOverflow(page);
    check('no horizontal overflow at 400px', dims.scroll <= dims.client, `${dims.scroll} > ${dims.client}`);

    await page.locator('#navToggle').click();
    await page.waitForTimeout(250);
    const menuOpen = await page.getAttribute('#navToggle', 'aria-expanded');
    check('mobile menu opens', menuOpen === 'true');
    dims = await noOverflow(page);
    check('no overflow with the menu open', dims.scroll <= dims.client, `${dims.scroll} > ${dims.client}`);

    if (args.shots) await page.screenshot({ path: path.join(args.shots, 'mobile-menu.png') });
    await page.keyboard.press('Escape');
    await page.waitForTimeout(200);

    if (args.shots) await page.screenshot({ path: path.join(args.shots, 'mobile-400.png'), fullPage: true });
    await context.close();
  }

  /* ------------------------------------------------- reduced motion + clock */
  {
    const { context, page } = await newPage(browser, 1280, 900, { reducedMotion: 'reduce' });
    await page.goto(args.url, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('html[data-ready="1"]', { timeout: 8000 });
    await page.locator('#impact').scrollIntoViewIfNeeded();
    await page.waitForTimeout(120);
    const value = (await page.textContent('#counterValue')).trim();
    check('reduced motion shows the value immediately', value === '20', value);
    await context.close();
  }
  {
    // A clock past the final: everything collapses into the past list.
    const { context, page } = await newPage(browser, 1280, 900);
    await page.goto(args.url + (args.url.includes('?') ? '&' : '?') + 'now=2026-10-05', { waitUntil: 'networkidle' });
    await page.waitForSelector('html[data-ready="1"]', { timeout: 8000 });
    const pastOpen = await page.evaluate(() => {
      const d = document.getElementById('pastEvents');
      return { hidden: d.hidden, open: d.open };
    });
    check('past events open when nothing is dated', pastOpen.hidden === false && pastOpen.open === true,
      JSON.stringify(pastOpen));
    const stillTbc = await page.locator('[data-status="tbc"]').count();
    check('undated golf day still shows after the padel final', stillTbc === 1, `found ${stillTbc}`);
    await context.close();
  }

  /* ----------------------------------------------------------- file:// pass */
  if (args.file) {
    const { context, page, errors } = await newPage(browser, 1280, 900);
    await page.goto('file://' + args.file, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('html[data-ready="1"]', { timeout: 8000 });
    check('opens from disk with no errors', errors.length === 0, errors.slice(0, 3).join(' | '));
    const tiles = await page.locator('.sponsor-tile').count();
    check('file:// still renders sponsors', tiles === 17, `found ${tiles}`);
    await context.close();
  }

  await browser.close();

  const failed = results.filter((r) => !r.ok);
  console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
  if (failed.length) {
    console.log('Failures:\n' + failed.map((f) => `  - ${f.name}${f.detail ? ': ' + f.detail : ''}`).join('\n'));
    process.exit(1);
  }
})().catch((err) => { console.error(err); process.exit(1); });
