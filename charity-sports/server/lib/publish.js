'use strict';
/* Publishing = writing the static snapshot from the live content, so the
   public site shows what the admin panel has saved.

   Three targets:
     file    write the files on this server only (the default)
     github  also commit them through the GitHub Contents API, which needs no
             git binary and works on a host with no persistent disk
     git     also commit and push with the local git binary
*/
const fs = require('fs');
const fsp = fs.promises;
const path = require('path');
const { execFile } = require('child_process');
const { promisify } = require('util');
const execFileAsync = promisify(execFile);
const { writeTextAtomic } = require('./atomic');
const serialize = require('./serialize');
const schema = require('./schema');

/** Every uploaded file the published content actually points at. */
function referencedUploads(content, config) {
  const text = JSON.stringify(schema.publicProjection(content));
  const prefix = config.uploadUrlBase.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const re = new RegExp(prefix + '\\/([A-Za-z0-9._-]+)', 'g');
  const names = new Set();
  let match;
  while ((match = re.exec(text))) names.add(match[1]);
  return Array.from(names);
}

async function writeLocal(content, config) {
  const snapshotText = serialize.snapshot(content);
  const previous = await fsp.readFile(config.snapshotPath, 'utf8').catch(() => null);
  const snapshotChanged = previous !== snapshotText;
  if (snapshotChanged) await writeTextAtomic(config.snapshotPath, snapshotText);

  /* The page's head and its structured data are both generated from the
     content, so the web address only ever has to be right in one place. */
  let indexChanged = false;
  let indexText = null;
  try {
    const html = await fsp.readFile(config.indexPath, 'utf8');
    const withMeta = serialize.replaceMeta(html, content, config.siteUrl);
    const withBoth = serialize.replaceJsonLd(withMeta.html, serialize.jsonLd(content, config.siteUrl));
    indexText = withBoth.html;
    if (indexText !== html) {
      await writeTextAtomic(config.indexPath, indexText);
      indexChanged = true;
    }
  } catch (err) {
    if (err.code !== 'ENOENT') throw err;
  }

  /* robots.txt and sitemap.xml carry the address too. */
  const root = config.rootDir;
  const sitemapText = serialize.sitemap(content, config.siteUrl);
  const robotsText = serialize.robots(content, config.siteUrl);
  const sitemapPath = path.join(root, 'sitemap.xml');
  const robotsPath = path.join(root, 'robots.txt');
  const sitemapChanged = (await fsp.readFile(sitemapPath, 'utf8').catch(() => null)) !== sitemapText;
  const robotsChanged = (await fsp.readFile(robotsPath, 'utf8').catch(() => null)) !== robotsText;
  if (sitemapChanged) await writeTextAtomic(sitemapPath, sitemapText);
  if (robotsChanged) await writeTextAtomic(robotsPath, robotsText);

  return {
    snapshotText, snapshotChanged,
    indexText, indexChanged,
    sitemapText, sitemapChanged,
    robotsText, robotsChanged
  };
}

/* ------------------------------------------------------------------ GitHub */

const GITHUB_TIMEOUT_MS = 20000;

function githubHeaders(config) {
  return {
    Authorization: `Bearer ${config.githubToken}`,
    Accept: 'application/vnd.github+json',
    'X-GitHub-Api-Version': '2022-11-28',
    'User-Agent': 'charity-sports-admin'
  };
}

function contentsUrl(config, repoPath) {
  return `https://api.github.com/repos/${config.githubRepo}/contents/${encodeURI(repoPath)}`;
}

/** Every call is bounded: a hung request must not wedge the publish button. */
function githubFetch(url, options) {
  const init = { ...options };
  if (AbortSignal && AbortSignal.timeout) {
    try { init.signal = AbortSignal.timeout(GITHUB_TIMEOUT_MS); } catch (err) { /* older node */ }
  }
  return fetch(url, init).catch((err) => {
    if (err && (err.name === 'TimeoutError' || err.name === 'AbortError')) {
      throw new Error(`GitHub did not answer within ${GITHUB_TIMEOUT_MS / 1000} seconds.`);
    }
    throw new Error(`GitHub could not be reached: ${err.message}`);
  });
}

/** The sha of a file already on the branch, or null if it is not there. */
async function githubSha(config, repoPath) {
  const res = await githubFetch(
    `${contentsUrl(config, repoPath)}?ref=${encodeURIComponent(config.githubBranch)}`,
    { headers: githubHeaders(config) }
  );
  if (res.status === 404) return null;
  if (!res.ok) {
    throw new Error(`GitHub read failed (${res.status}). Check GITHUB_TOKEN and GITHUB_REPO.`);
  }
  const json = await res.json();
  return json.sha || null;
}

async function githubHas(config, repoPath) {
  return (await githubSha(config, repoPath)) !== null;
}

/** @param {string|Buffer} body text for a file, or raw bytes for an image. */
async function githubPut(config, repoPath, body, message) {
  const sha = await githubSha(config, repoPath);
  const bytes = Buffer.isBuffer(body) ? body : Buffer.from(body, 'utf8');

  const res = await githubFetch(contentsUrl(config, repoPath), {
    method: 'PUT',
    headers: { ...githubHeaders(config), 'Content-Type': 'application/json' },
    body: JSON.stringify({
      message,
      content: bytes.toString('base64'),
      branch: config.githubBranch,
      sha: sha || undefined
    })
  });
  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`GitHub write failed (${res.status}): ${text.slice(0, 200)}`);
  }
  const json = await res.json();
  return json.commit && json.commit.html_url;
}

/* --------------------------------------------------------------------- git */

async function gitCommit(config, files, message) {
  const opts = { cwd: config.rootDir };
  await execFileAsync('git', ['add', ...files], opts);
  const status = await execFileAsync('git', ['status', '--porcelain', ...files], opts);
  if (!status.stdout.trim()) return null;
  await execFileAsync('git', ['commit', '-m', message], opts);
  await execFileAsync('git', ['push', 'origin', config.githubBranch], opts);
  const rev = await execFileAsync('git', ['rev-parse', 'HEAD'], opts);
  return rev.stdout.trim();
}

/* ------------------------------------------------------------------ public */

async function publish(content, config, { actor } = {}) {
  const local = await writeLocal(content, config);
  const relSnapshot = path.relative(config.rootDir, config.snapshotPath).split(path.sep).join('/');
  const relIndex = path.relative(config.rootDir, config.indexPath).split(path.sep).join('/');

  const relUploadBase = config.uploadUrlBase.replace(/^\/+|\/+$/g, '');

  const result = {
    target: config.publishTarget,
    wroteSnapshot: local.snapshotChanged,
    wroteJsonLd: local.indexChanged,
    wroteSitemap: local.sitemapChanged || local.robotsChanged,
    uploadsCommitted: 0,
    committed: false,
    commitUrl: null,
    message: null
  };

  const anythingChanged = local.snapshotChanged || local.indexChanged ||
    local.sitemapChanged || local.robotsChanged;

  /* Images uploaded through the panel live outside the repository, so they
     have to be committed too. Without this, a logo added in the admin shows
     on the server but 404s on the published site, and on a host with no
     persistent disk it disappears entirely at the next restart. */
  const uploads = referencedUploads(content, config);

  if (!anythingChanged && config.publishTarget === 'file') {
    result.message = 'The live site already matches. Nothing to publish.';
    return result;
  }

  const message = `Content update via admin panel${actor ? ` (${actor.username})` : ''}`;

  try {
    if (config.publishTarget === 'github') {
      if (local.snapshotChanged) {
        result.commitUrl = await githubPut(config, relSnapshot, local.snapshotText, message);
      }
      if (local.indexChanged && local.indexText) {
        result.commitUrl = await githubPut(config, relIndex, local.indexText, message + ' (page head)');
      }
      if (local.sitemapChanged) await githubPut(config, 'sitemap.xml', local.sitemapText, message + ' (sitemap)');
      if (local.robotsChanged) await githubPut(config, 'robots.txt', local.robotsText, message + ' (robots)');

      for (const name of uploads) {
        const already = await githubHas(config, `${relUploadBase}/${name}`);
        if (already) continue;
        const bytes = await fsp.readFile(path.join(config.uploadDir, name)).catch(() => null);
        if (!bytes) continue;                       // referenced but missing: skip quietly
        await githubPut(config, `${relUploadBase}/${name}`, bytes, message + ` (image ${name})`);
        result.uploadsCommitted++;
      }

      result.committed = true;
      result.message = result.uploadsCommitted
        ? `Published with ${result.uploadsCommitted} new image${result.uploadsCommitted === 1 ? '' : 's'}. ` +
          'GitHub Pages usually rebuilds within a minute.'
        : 'Published. GitHub Pages usually rebuilds within a minute.';
    } else if (config.publishTarget === 'git') {
      const files = [];
      if (local.snapshotChanged) files.push(relSnapshot);
      if (local.indexChanged) files.push(relIndex);
      if (local.sitemapChanged) files.push('sitemap.xml');
      if (local.robotsChanged) files.push('robots.txt');
      /* Only add uploads that sit inside the repository; on a host with the
         uploads on a mounted disk there is nothing for git to add. */
      if (uploads.length && isInsideRepo(config)) {
        uploads.forEach((name) => files.push(`${relUploadBase}/${name}`));
        result.uploadsCommitted = uploads.length;
      }
      const sha = files.length ? await gitCommit(config, files, message) : null;
      result.committed = !!sha;
      result.message = sha ? 'Published and pushed.' : 'Published. Nothing new to commit.';
    } else {
      result.message = anythingChanged
        ? 'Published to this server. The public site here is up to date.'
        : 'The live site already matches. Nothing to publish.';
    }
  } catch (err) {
    /* The local write already succeeded, so say so rather than implying
       nothing happened. */
    result.message = `Saved on this server, but the commit failed: ${err.message}`;
    result.error = err.message;
  }

  return result;
}

/** Is the uploads folder inside the repository git can see? */
function isInsideRepo(config) {
  const uploads = path.resolve(config.uploadDir);
  const root = path.resolve(config.rootDir);
  return uploads === root || uploads.startsWith(root + path.sep);
}

/** Is the published snapshot behind the live content? */
async function status(content, config) {
  let snapshotUpdatedAt = null;
  try {
    const text = await fsp.readFile(config.snapshotPath, 'utf8');
    const m = /"updatedAt":\s*"([^"]+)"/.exec(text);
    if (m) snapshotUpdatedAt = m[1];
  } catch (err) { /* not published yet */ }
  return {
    snapshotUpdatedAt,
    storeUpdatedAt: content.updatedAt,
    dirty: snapshotUpdatedAt !== content.updatedAt,
    target: config.publishTarget
  };
}

module.exports = { publish, status, writeLocal };
