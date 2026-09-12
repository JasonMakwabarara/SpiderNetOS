'use strict';
/* Crash-safe JSON writes.
 *
 * Write to a temp file, fsync it, back up whatever is already there, then
 * rename over the target. Rename within a filesystem is atomic, so a power
 * cut or a kill leaves either the old file or the new one, never a half
 * written one. Every mutation also leaves a timestamped copy behind.
 */
const fs = require('fs');
const fsp = fs.promises;
const path = require('path');
const crypto = require('crypto');

const KEEP_BACKUPS = 50;

async function ensureDir(dir) {
  await fsp.mkdir(dir, { recursive: true });
}

async function readJson(file, fallback = null) {
  try {
    const raw = await fsp.readFile(file, 'utf8');
    return JSON.parse(raw);
  } catch (err) {
    if (err.code === 'ENOENT') return fallback;
    throw err;
  }
}

async function backupExisting(file, backupDir) {
  if (!backupDir) return null;
  try {
    await fsp.access(file);
  } catch (err) {
    return null;                       // nothing there yet, nothing to keep
  }
  await ensureDir(backupDir);
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const dest = path.join(backupDir, `${path.basename(file, '.json')}-${stamp}.json`);
  await fsp.copyFile(file, dest);
  await pruneBackups(backupDir, path.basename(file, '.json'));
  return dest;
}

async function pruneBackups(backupDir, prefix) {
  try {
    const names = (await fsp.readdir(backupDir))
      .filter((n) => n.startsWith(prefix + '-') && n.endsWith('.json'))
      .sort();
    const excess = names.slice(0, Math.max(0, names.length - KEEP_BACKUPS));
    await Promise.all(excess.map((n) => fsp.unlink(path.join(backupDir, n)).catch(() => {})));
  } catch (err) { /* pruning is best-effort */ }
}

async function writeJsonAtomic(file, value, { backupDir = null, mode = 0o600 } = {}) {
  /* Serialise first. If the value cannot be turned into JSON we fail here,
     before anything has been created on disk, so a bad write leaves no
     temp file behind. */
  const text = JSON.stringify(value, null, 2) + '\n';

  const dir = path.dirname(file);
  await ensureDir(dir);
  const tmp = path.join(dir, `.${path.basename(file)}.tmp-${crypto.randomUUID()}`);

  let handle;
  try {
    handle = await fsp.open(tmp, 'wx', mode);
    await handle.writeFile(text, 'utf8');
    await handle.sync();
  } catch (err) {
    await fsp.unlink(tmp).catch(() => {});
    throw err;
  } finally {
    if (handle) await handle.close();
  }

  try {
    await backupExisting(file, backupDir);
    await fsp.rename(tmp, file);
    /* fsync the directory so the rename itself survives a power loss. */
    let dirHandle;
    try {
      dirHandle = await fsp.open(dir, 'r');
      await dirHandle.sync();
    } catch (err) {
      /* Not supported on every platform; the rename is still atomic. */
    } finally {
      if (dirHandle) await dirHandle.close();
    }
  } catch (err) {
    await fsp.unlink(tmp).catch(() => {});
    throw err;
  }
  return file;
}

async function writeTextAtomic(file, text, { mode = 0o644 } = {}) {
  const dir = path.dirname(file);
  await ensureDir(dir);
  const tmp = path.join(dir, `.${path.basename(file)}.tmp-${crypto.randomUUID()}`);
  let handle;
  try {
    handle = await fsp.open(tmp, 'wx', mode);
    await handle.writeFile(text, 'utf8');
    await handle.sync();
  } catch (err) {
    await fsp.unlink(tmp).catch(() => {});
    throw err;
  } finally {
    if (handle) await handle.close();
  }
  try {
    await fsp.rename(tmp, file);
  } catch (err) {
    await fsp.unlink(tmp).catch(() => {});
    throw err;
  }
  return file;
}

/** Serialises read-modify-write cycles so two requests cannot interleave. */
function createMutex() {
  let tail = Promise.resolve();
  return function run(task) {
    const result = tail.then(task, task);
    tail = result.then(() => undefined, () => undefined);
    return result;
  };
}

module.exports = { readJson, writeJsonAtomic, writeTextAtomic, backupExisting, ensureDir, createMutex, KEEP_BACKUPS };
