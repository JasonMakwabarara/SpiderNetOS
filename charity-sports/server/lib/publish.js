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

async function writeLocal(content, config) {
  const snapshotText = serialize.snapshot(content);
  const previous = await fsp.readFile(config.snapshotPath, 'utf8').catch(() => null);
  const snapshotChanged = previous !== snapshotText;
  if (snapshotChanged) await writeTextAtomic(config.snapshotPath, snapshotText);

  let jsonLdChanged = false;
  let indexText = null;
  try {
    const html = await fsp.readFile(config.indexPath, 'utf8');
    const result = serialize.replaceJsonLd(html, serialize.jsonLd(content, config.siteUrl));
    if (result.replaced && result.html !== html) {
      await writeTextAtomic(config.indexPath, result.html);
      jsonLdChanged = true;
      indexText = result.html;
    } else if (result.replaced) {
      indexText = result.html;
    }
  } catch (err) {
    if (err.code !== 'ENOENT') throw err;
  }

  return { snapshotText, snapshotChanged, jsonLdChanged, indexText };
}

/* ------------------------------------------------------------------ GitHub */

async function githubPut(config, repoPath, text, message) {
  const api = `https://api.github.com/repos/${config.githubRepo}/contents/${encodeURI(repoPath)}`;
  const headers = {
    Authorization: `Bearer ${config.githubToken}`,
    Accept: 'application/vnd.github+json',
    'X-GitHub-Api-Version': '2022-11-28',
    'User-Agent': 'charity-sports-admin'
  };

  let sha;
  const head = await fetch(`${api}?ref=${encodeURIComponent(config.githubBranch)}`, { headers });
  if (head.ok) {
    const json = await head.json();
    sha = json.sha;
  } else if (head.status !== 404) {
    throw new Error(`GitHub read failed (${head.status}). Check GITHUB_TOKEN and GITHUB_REPO.`);
  }

  const res = await fetch(api, {
    method: 'PUT',
    headers: { ...headers, 'Content-Type': 'application/json' },
    body: JSON.stringify({
      message,
      content: Buffer.from(text, 'utf8').toString('base64'),
      branch: config.githubBranch,
      sha
    })
  });
  if (!res.ok) {
    const body = await res.text().catch(() => '');
    throw new Error(`GitHub write failed (${res.status}): ${body.slice(0, 200)}`);
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

  const result = {
    target: config.publishTarget,
    wroteSnapshot: local.snapshotChanged,
    wroteJsonLd: local.jsonLdChanged,
    committed: false,
    commitUrl: null,
    message: null
  };

  if (!local.snapshotChanged && !local.jsonLdChanged) {
    result.message = 'The live site already matches. Nothing to publish.';
    return result;
  }

  const message = `Content update via admin panel${actor ? ` (${actor.username})` : ''}`;

  try {
    if (config.publishTarget === 'github') {
      result.commitUrl = await githubPut(config, relSnapshot, local.snapshotText, message);
      if (local.jsonLdChanged && local.indexText) {
        await githubPut(config, relIndex, local.indexText, message + ' (structured data)');
      }
      result.committed = true;
      result.message = 'Published. GitHub Pages usually rebuilds within a minute.';
    } else if (config.publishTarget === 'git') {
      const files = [relSnapshot];
      if (local.jsonLdChanged) files.push(relIndex);
      const sha = await gitCommit(config, files, message);
      result.committed = !!sha;
      result.message = sha ? 'Published and pushed.' : 'Published. Nothing new to commit.';
    } else {
      result.message = 'Published to this server. The public site here is up to date.';
    }
  } catch (err) {
    /* The local write already succeeded, so say so rather than implying
       nothing happened. */
    result.message = `Saved on this server, but the commit failed: ${err.message}`;
    result.error = err.message;
  }

  return result;
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
