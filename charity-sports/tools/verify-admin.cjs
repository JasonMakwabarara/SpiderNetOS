#!/usr/bin/env node
/**
 * Drives the admin panel in a real browser, end to end:
 * sign in, forced password change, edit, add, upload, publish, and then
 * confirm the public site shows it and still works with the server stopped.
 *
 *   NODE_PATH=<scratch>/node_modules node tools/verify-admin.cjs [--shots <dir>]
 */
const fs = require('fs');
const fsp = fs.promises;
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { execFile } = require('child_process');
const { promisify } = require('util');
const execFileAsync = promisify(execFile);
const { chromium } = require('playwright');

const ROOT = path.resolve(__dirname, '..');

function findChromium() {
  const base = process.env.PLAYWRIGHT_BROWSERS_PATH || '/opt/pw-browsers';
  try {
    for (const entry of fs.readdirSync(base)) {
      if (/^chromium-\d+$/.test(entry)) {
        const bin = path.join(base, entry, 'chrome-linux', 'chrome');
        if (fs.existsSync(bin)) return bin;
      }
    }
  } catch (err) { /* fall back to Playwright's own lookup */ }
  return undefined;
}

const results = [];
function check(name, ok, detail) {
  results.push({ name, ok: !!ok, detail: detail == null ? '' : String(detail) });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
}

async function main() {
  const shots = process.argv.includes('--shots')
    ? process.argv[process.argv.indexOf('--shots') + 1] : null;
  if (shots) fs.mkdirSync(shots, { recursive: true });

  /* A throwaway home for content and uploads: never touch the repository. */
  const home = await fsp.mkdtemp(path.join(os.tmpdir(), 'cs-admin-'));
  await fsp.mkdir(path.join(home, 'content', 'seed'), { recursive: true });
  await fsp.mkdir(path.join(home, 'uploads'), { recursive: true });
  await fsp.mkdir(path.join(home, 'data'), { recursive: true });
  await fsp.copyFile(path.join(ROOT, 'content', 'seed', 'content.seed.json'),
    path.join(home, 'content', 'seed', 'content.seed.json'));
  await fsp.copyFile(path.join(ROOT, 'data', 'site-data.js'), path.join(home, 'data', 'site-data.js'));
  await fsp.copyFile(path.join(ROOT, 'index.html'), path.join(home, 'index.html'));
  /* The page loads its stylesheet, scripts and images by relative path, so the
     throwaway copy needs them next to it for the file:// check at the end. */
  await fsp.symlink(path.join(ROOT, 'assets'), path.join(home, 'assets'), 'dir');

  const port = 4187 + (process.pid % 300);
  const env = {
    ...process.env,
    NODE_ENV: 'development',
    PORT: String(port),
    HOST: '127.0.0.1',
    SESSION_SECRET: crypto.randomBytes(32).toString('base64url'),
    CONTENT_DIR: path.join(home, 'content'),
    UPLOAD_DIR: path.join(home, 'uploads'),
    SNAPSHOT_PATH: path.join(home, 'data', 'site-data.js'),
    INDEX_PATH: path.join(home, 'index.html'),
    PUBLIC_DIR: ROOT,
    COOKIE_SECURE: 'false',
    LOG_LEVEL: 'silent'
  };

  /* One admin account, password captured from the setup script's output. */
  const setup = await execFileAsync('node', [path.join(ROOT, 'server', 'scripts', 'setup-admin.js'), 'admin'], { env, cwd: ROOT });
  const passwordMatch = /Password:\s+(\S+)/.exec(setup.stdout);
  check('setup script creates an account and prints a password once', !!passwordMatch);
  const firstPassword = passwordMatch[1];
  check('the generated password is long', firstPassword.length >= 20, `${firstPassword.length} characters`);
  check('the password is not stored in the clear', !(
    await fsp.readFile(path.join(home, 'content', 'users.json'), 'utf8')
  ).includes(firstPassword));

  const { spawn } = require('child_process');
  const server = spawn('node', [path.join(ROOT, 'server', 'index.js')], { env, cwd: ROOT, stdio: 'ignore' });
  const base = `http://127.0.0.1:${port}`;

  const ready = async () => {
    for (let i = 0; i < 60; i++) {
      try {
        const res = await fetch(base + '/api/health');
        if (res.ok) return true;
      } catch (err) { /* not up yet */ }
      await new Promise((r) => setTimeout(r, 250));
    }
    return false;
  };
  check('server starts', await ready());

  const browser = await chromium.launch({
    executablePath: findChromium(),
    args: ['--no-sandbox', '--disable-dev-shm-usage']
  });
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (err) => errors.push(err.message));
  page.on('console', (msg) => {
    if (msg.type() !== 'error') return;
    const text = msg.text();
    if (/fonts\.(googleapis|gstatic)/.test(text)) return;
    if (/401|403|the server responded with a status of/.test(text)) return;   // expected during sign-in
    errors.push(text);
  });

  const newPassword = 'three padel nights in Borrowdale';

  try {
    /* ------------------------------------------------------------- sign in */
    await page.goto(base + '/admin', { waitUntil: 'networkidle' });
    await page.waitForURL(/\/admin\/login/, { timeout: 8000 });
    check('an unauthenticated visit lands on the sign-in page', /\/admin\/login/.test(page.url()));

    await page.fill('#username', 'admin');
    await page.fill('#password', 'definitely-the-wrong-one');
    await page.click('#loginSubmit');
    await page.waitForSelector('#loginError:not([hidden])', { timeout: 8000 });
    const message = (await page.textContent('#loginError')).trim();
    check('a wrong password is refused with a generic message',
      /incorrect username or password/i.test(message), message);

    await page.fill('#username', 'admin');
    await page.fill('#password', firstPassword);
    await page.click('#loginSubmit');
    await page.waitForURL(/#\/password/, { timeout: 10000 });
    check('the first sign-in forces a password change', /#\/password/.test(page.url()));
    if (shots) await page.screenshot({ path: path.join(shots, 'admin-password.png'), fullPage: true });

    /* Confirm the rest of the panel really is closed until that is done. */
    await page.goto(base + '/admin/#/causes', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    check('other screens stay closed until the password is changed', /#\/password/.test(page.url()), page.url());

    await page.fill('#currentPassword', firstPassword);
    await page.fill('#newPassword', newPassword);
    await page.fill('#confirmPassword', 'something else entirely');
    await page.click('#screen form button[type="submit"]');
    await page.waitForTimeout(500);
    check('mismatched confirmation is caught before sending',
      await page.locator('[data-field="confirmPassword"].has-error').count() === 1);

    await page.fill('#confirmPassword', newPassword);
    await page.click('#screen form button[type="submit"]');
    await page.waitForFunction(() => location.hash === '#/' || location.hash === '', null, { timeout: 10000 });
    check('changing the password lets you in', true);

    /* ----------------------------------------------------------- dashboard */
    await page.waitForSelector('#livesInput', { timeout: 8000 });
    const lives = await page.inputValue('#livesInput');
    check('the dashboard shows the current lives number', lives === '20', lives);
    if (shots) await page.screenshot({ path: path.join(shots, 'admin-dashboard.png'), fullPage: true });

    await page.fill('#livesInput', '37');
    await page.click('#screen .lives-editor button[type="submit"]');
    await page.waitForSelector('#screen .save-state.ok', { timeout: 10000 });
    check('the lives number saves', true);

    /* --------------------------------------------------------- add a cause */
    await page.goto(base + '/admin/#/causes/new', { waitUntil: 'networkidle' });
    await page.waitForSelector('form', { timeout: 8000 });
    await page.fill('[data-field="title"] input', 'School shoes for Mutare');
    await page.fill('[data-field="summary"] textarea', 'Shoes for a term, for children walking a long way to school.');
    await page.fill('[data-field="targetUsd"] input', '800');
    await page.click('#screen form button[type="submit"]');
    await page.waitForSelector('#screen .save-state.ok', { timeout: 10000 });
    check('a new cause can be added', true);

    /* --------------------------- add an event with no date, like a golf day */
    await page.goto(base + '/admin/#/events/new', { waitUntil: 'networkidle' });
    await page.waitForSelector('form', { timeout: 8000 });
    await page.fill('[data-field="title"] input', 'Charity Cricket Day');
    await page.fill('[data-field="sport"] input', 'Cricket');
    await page.fill('[data-field="dateNote"] input', 'Date to be announced');
    await page.click('#screen form button[type="submit"]');
    await page.waitForSelector('#screen .save-state.ok', { timeout: 10000 });
    check('an event can be added before it has a date', true);

    /* --------------------------------------------------------- upload path */
    await page.goto(base + '/admin/#/sponsors', { waitUntil: 'networkidle' });
    await page.waitForSelector('.item', { timeout: 8000 });
    const sponsorCount = await page.locator('.item').count();
    check('every sponsor is listed, hidden ones included', sponsorCount === 17, `${sponsorCount}`);
    if (shots) await page.screenshot({ path: path.join(shots, 'admin-sponsors.png'), fullPage: true });

    await page.goto(base + '/admin/#/sponsors/crystal', { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-field="logo"] input[type="file"]', { timeout: 8000 });
    await page.setInputFiles('[data-field="logo"] input[type="file"]',
      path.join(ROOT, 'assets', 'logos', 'hannah-ai-tile.png'));
    await page.waitForSelector('[data-field="logo"] .upload-preview img[src^="../assets/img/uploads/"]', { timeout: 20000 });
    check('uploading a logo returns a usable path', true);

    await page.click('#screen form button[type="submit"]');
    await page.waitForSelector('#screen .save-state.ok', { timeout: 10000 });
    check('the sponsor saves with its new logo', true);

    /* -------------------------------------------------------- validation */
    await page.goto(base + '/admin/#/donate', { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-field="url"] input', { timeout: 8000 });
    await page.fill('[data-field="url"] input', 'not a web address');
    await page.click('#screen form button[type="submit"]');
    await page.waitForSelector('[data-field="url"].has-error', { timeout: 8000 });
    const fieldError = (await page.textContent('[data-field="url"] .error')).trim();
    check('a bad donate link is refused against its own field', fieldError.length > 0, fieldError);

    await page.fill('[data-field="url"] input', 'https://donate.contipay.co.zw/charity-sports');
    await page.click('#screen form button[type="submit"]');
    await page.waitForSelector('#screen .save-state.ok', { timeout: 10000 });
    check('a good donate link saves', true);

    /* ------------------------------------------------------------ publish */
    const badgeBefore = (await page.textContent('#publishState')).trim();
    check('the panel says the live site is behind', /not published/i.test(badgeBefore), badgeBefore);

    await page.click('#publishBtn');
    await page.waitForSelector('#dialog[open]', { timeout: 5000 });
    await page.click('#dialogConfirm');
    await page.waitForFunction(
      () => /up to date/i.test(document.getElementById('publishState').textContent),
      null, { timeout: 20000 });
    check('publishing marks the live site up to date', true);

    const snapshot = await fsp.readFile(path.join(home, 'data', 'site-data.js'), 'utf8');
    check('the snapshot on disk carries the new number', /"livesHelped":\s*37/.test(snapshot));
    check('the snapshot carries the new cause', /school-shoes-for-mutare/.test(snapshot));
    check('the snapshot carries the new donate link', /donate\.contipay\.co\.zw\/charity-sports/.test(snapshot));

    /* ------------------------------------------------- the public site now */
    const site = await context.newPage();
    await site.goto(base + '/', { waitUntil: 'networkidle' });
    await site.waitForSelector('html[data-ready="1"]', { timeout: 8000 });
    await site.locator('#impact').scrollIntoViewIfNeeded();
    await site.waitForTimeout(2000);
    const shown = (await site.textContent('#counterValue')).trim();
    check('the public page shows the published number', shown === '37', shown);

    const causeCount = await site.locator('.cause-card').count();
    check('the new cause appears on the public page', causeCount === 3, `${causeCount}`);
    const tbc = await site.locator('[data-status="tbc"]').count();
    check('both undated events show as date-to-be-announced', tbc === 2, `${tbc}`);

    const audit = await fsp.readFile(path.join(home, 'content', 'audit.log'), 'utf8');
    check('the history records the sign-in, the edit and the publish',
      /"action":"login"/.test(audit) && /"action":"update_section"/.test(audit) && /"action":"publish"/.test(audit));
    check('the history never contains a password',
      !audit.includes(firstPassword) && !audit.includes(newPassword));

    /* ----------------------------------------------- audit and users screens */
    await page.goto(base + '/admin/#/audit', { waitUntil: 'networkidle' });
    await page.waitForSelector('.table tbody tr', { timeout: 8000 });
    check('the history screen lists entries', (await page.locator('.table tbody tr').count()) > 3);

    await page.goto(base + '/admin/#/users', { waitUntil: 'networkidle' });
    await page.waitForSelector('.table tbody tr', { timeout: 8000 });
    check('the people screen lists the account', (await page.locator('.table tbody tr').count()) === 1);
    if (shots) await page.screenshot({ path: path.join(shots, 'admin-users.png'), fullPage: true });

    /* --------------------------------- with the server gone, the site stands */
    await site.close();
    server.kill('SIGTERM');
    await new Promise((r) => setTimeout(r, 1200));

    const offline = await context.newPage();
    const offlineErrors = [];
    offline.on('pageerror', (err) => offlineErrors.push(err.message));
    await offline.goto('file://' + path.join(home, 'index.html'), { waitUntil: 'domcontentloaded' });
    await offline.waitForSelector('html[data-ready="1"]', { timeout: 8000 });
    await offline.locator('#impact').scrollIntoViewIfNeeded();
    await offline.waitForTimeout(1500);
    const offlineValue = (await offline.textContent('#counterValue')).trim();
    check('with the server stopped the page still renders from the snapshot', offlineValue === '37', offlineValue);
    check('and it throws nothing', offlineErrors.length === 0, offlineErrors.join(' | '));
    await offline.close();

    check('no unexpected errors in the admin panel', errors.length === 0, errors.slice(0, 3).join(' | '));
  } finally {
    await browser.close();
    try { server.kill('SIGKILL'); } catch (err) { /* already gone */ }
    await fsp.rm(home, { recursive: true, force: true });
  }

  const failed = results.filter((r) => !r.ok);
  console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
  if (failed.length) {
    console.log('Failures:\n' + failed.map((f) => `  - ${f.name}${f.detail ? ': ' + f.detail : ''}`).join('\n'));
    process.exit(1);
  }
}

main().catch((err) => { console.error(err); process.exit(1); });
