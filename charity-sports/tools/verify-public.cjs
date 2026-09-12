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

    /* ------------------------------------------------- accountability */
    const trustVisible = await page.evaluate(() => !document.getElementById('accountability').hidden);
    check('the accountability section appears', trustVisible === true);

    const trustFacts = await page.locator('#trustFacts li').count();
    const trustStatements = await page.locator('#trustStatements li').count();
    check('it shows the statements that are true', trustStatements >= 3, `${trustStatements}`);
    check('and claims nothing the charity has not supplied', trustFacts === 0,
      `${trustFacts} unverified facts are showing`);

    const emptyTrust = await page.evaluate(() => {
      const before = document.getElementById('accountability').hidden;
      /* Blank it out and re-render: the section must disappear rather than
         show an empty panel. */
      const data = JSON.parse(JSON.stringify(window.CHARITY_DATA));
      data.accountability = { heading: 'x', intro: 'y', statements: [] };
      window.CS.renderAll(data, ['accountability']);
      const after = document.getElementById('accountability').hidden;
      window.CS.renderAll(window.CHARITY_DATA, ['accountability']);
      return { before, after };
    });
    check('with nothing to say, it hides itself entirely',
      emptyTrust.before === false && emptyTrust.after === true, JSON.stringify(emptyTrust));

    /* ------------------------------------------------------ money raised */
    const raisedHidden = await page.evaluate(() => document.getElementById('counterRaised').hidden);
    check('no fundraising total is invented when none was supplied', raisedHidden === true);

    const raisedShown = await page.evaluate(() => {
      const data = JSON.parse(JSON.stringify(window.CHARITY_DATA));
      data.impact.raisedTotalUsd = 1450;
      window.CS.renderAll(data, ['impact']);
      const node = document.getElementById('counterRaised');
      const text = document.getElementById('counterRaisedValue').textContent;
      const hidden = node.hidden;
      window.CS.renderAll(window.CHARITY_DATA, ['impact']);
      return { hidden, text };
    });
    check('a real total appears beside the counter when set',
      raisedShown.hidden === false && /US\$1,450/.test(raisedShown.text), JSON.stringify(raisedShown));

    /* ----------------------------------------------------------- sharing */
    const shareButtons = await page.locator('[data-share]:not([hidden])').count();
    check('share buttons are offered', shareButtons === 2, `${shareButtons}`);

    /* --------------------------------------------------------- hero preload */
    const preload = await page.evaluate(() => {
      const link = document.querySelector('link[rel="preload"][as="image"]');
      if (!link) return null;
      const img = document.querySelector('.hero-figure img');
      const source = document.querySelector('.hero-figure source');
      return {
        href: link.getAttribute('href'),
        srcset: link.getAttribute('imagesrcset') || '',
        priority: link.getAttribute('fetchpriority'),
        rendered: (source && source.getAttribute('srcset')) || (img && img.getAttribute('src')) || ''
      };
    });
    check('the hero is preloaded', !!preload);
    if (preload) {
      check('the preload is high priority', preload.priority === 'high', preload.priority);
      check('and names the image the page actually shows',
        preload.rendered.includes(preload.href.split('/').pop()),
        `${preload.href} vs ${preload.rendered.slice(0, 60)}`);
    }

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

  /* ------------------------------------------------------- sign-up fallback */
  {
    const { context, page } = await newPage(browser, 1280, 900);
    await page.goto(args.url, { waitUntil: 'networkidle' });
    await page.waitForSelector('html[data-ready="1"]', { timeout: 8000 });
    await page.waitForTimeout(1500);

    const signup = await page.evaluate(() => {
      const section = document.getElementById('signup');
      const form = document.getElementById('signupForm');
      const fallback = document.getElementById('signupFallback');
      const link = fallback && fallback.querySelector('a');
      return {
        sectionHidden: section.hidden,
        formHidden: form.hidden,
        fallbackHidden: fallback ? fallback.hidden : true,
        href: link ? link.getAttribute('href') : null
      };
    });
    check('with no server behind it, the sign-up form is not shown',
      signup.formHidden === true, JSON.stringify(signup));
    check('and a WhatsApp link takes its place instead',
      signup.fallbackHidden === false && /wa\.me\//.test(signup.href || ''), signup.href);
    check('so the section still offers a way through', signup.sectionHidden === false);
    await context.close();
  }

  /* -------------------------------------------------------- service worker */
  if (/^https?:/.test(args.url)) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    await page.goto(args.url, { waitUntil: 'networkidle' });
    await page.waitForSelector('html[data-ready="1"]', { timeout: 8000 });
    await page.waitForTimeout(2500);

    const registered = await page.evaluate(async () => {
      if (!('serviceWorker' in navigator)) return 'unsupported';
      const reg = await navigator.serviceWorker.getRegistration();
      return reg ? 'registered' : 'none';
    });
    check('a service worker takes charge of the site', registered === 'registered', registered);

    const privateCached = await page.evaluate(async () => {
      const names = await caches.keys();
      for (const name of names) {
        const keys = await (await caches.open(name)).keys();
        if (keys.some((r) => r.url.includes('/admin') || r.url.includes('/api/'))) return true;
      }
      return false;
    });
    check('it never caches the admin panel or the API', privateCached === false);

    await context.setOffline(true);
    await page.reload({ waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(1500);
    const offline = await page.evaluate(() => ({
      ready: document.documentElement.dataset.ready || null,
      heading: (document.querySelector('h1') || {}).textContent || ''
    }));
    check('and the page still opens with the connection gone',
      offline.ready === '1' && offline.heading.trim().length > 0, JSON.stringify(offline));
    await context.setOffline(false);
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
