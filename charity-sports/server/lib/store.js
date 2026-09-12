'use strict';
/* The content and user stores.
 *
 * Both are plain JSON on disk, written atomically with a backup kept on every
 * change. All mutations run through one mutex, so a read-modify-write cannot
 * interleave with another request and lose an edit. */
const path = require('path');
const crypto = require('crypto');
const { readJson, writeJsonAtomic, createMutex, ensureDir } = require('./atomic');
const schema = require('./schema');

function createStore(config, audit) {
  const mutate = createMutex();
  let content = null;
  let users = null;
  let projectionCache = null;
  let etag = null;

  /* ------------------------------------------------------------- content */

  async function loadContent() {
    let raw = await readJson(config.contentPath, null);

    if (raw === null) {
      raw = await readJson(config.seedPath, null);
      if (raw === null) {
        throw new Error(
          `No content found.\n` +
          `  Expected ${config.contentPath} or a seed at ${config.seedPath}.\n` +
          `  Create the seed with: npm run seed`
        );
      }
      if (config.logLevel !== 'silent') console.log('[store] first run: seeding content from', path.basename(config.seedPath));
    }

    const version = Number(raw.schemaVersion || 0);
    if (version > schema.SCHEMA_VERSION) {
      throw new Error(
        `Content file is from a newer version (v${version} > v${schema.SCHEMA_VERSION}).\n` +
        `  Update the server, or restore a backup from ${config.backupDir}.`
      );
    }

    content = schema.normalise(raw);
    if (content.warnings && content.warnings.length && config.logLevel === 'debug') {
      console.warn(`[store] ${content.warnings.length} field(s) were corrected on load`);
    }
    delete content.warnings;

    /* Persist if this was a seed, a migration, or a correction. */
    const changed = raw === null || version !== schema.SCHEMA_VERSION ||
      JSON.stringify(raw.data || raw) !== JSON.stringify(content.data);
    if (changed) await persistContent();
    invalidate();
    return content;
  }

  async function persistContent() {
    await writeJsonAtomic(config.contentPath, content, { backupDir: config.backupDir });
  }

  function invalidate() {
    projectionCache = null;
    etag = null;
  }

  function get() { return content; }

  function publicJson() {
    if (!projectionCache) {
      projectionCache = { schemaVersion: content.schemaVersion, updatedAt: content.updatedAt, data: schema.publicProjection(content) };
      etag = '"' + crypto.createHash('sha256').update(JSON.stringify(projectionCache)).digest('base64url').slice(0, 27) + '"';
    }
    return { body: projectionCache, etag };
  }

  /**
   * Apply a change under the mutex.
   * @param {function} mutator receives a deep clone of content.data; return
   *   {errors} to reject, anything else to accept.
   */
  function update(mutator, meta = {}) {
    return mutate(async () => {
      if (meta.ifMatch && meta.ifMatch !== content.updatedAt) {
        return { ok: false, conflict: true, current: content.updatedAt };
      }

      const draft = structuredClone(content.data);
      const result = await mutator(draft);
      if (result && result.errors && result.errors.length) {
        return { ok: false, errors: result.errors };
      }

      const normalised = schema.normalise({ schemaVersion: schema.SCHEMA_VERSION, data: draft });
      if (normalised.warnings && normalised.warnings.length) {
        return { ok: false, errors: normalised.warnings };
      }

      const before = content.data;
      content = {
        schemaVersion: schema.SCHEMA_VERSION,
        updatedAt: new Date().toISOString(),
        data: normalised.data
      };
      await persistContent();
      invalidate();

      if (audit && meta.action) {
        await audit.write({
          actor: meta.actor,
          action: meta.action,
          target: meta.target,
          summary: meta.summary || audit.diff(before[meta.section] || {}, content.data[meta.section] || {}),
          ip: meta.ip,
          ua: meta.ua
        });
      }

      return { ok: true, updatedAt: content.updatedAt, value: result && result.value };
    });
  }

  /* --------------------------------------------------------------- users */

  async function loadUsers() {
    users = await readJson(config.usersPath, []);
    if (!Array.isArray(users)) users = [];
    return users;
  }

  async function persistUsers() {
    await ensureDir(path.dirname(config.usersPath));
    await writeJsonAtomic(config.usersPath, users, { backupDir: config.backupDir });
  }

  function listUsers() {
    return users.map(publicUser);
  }

  function publicUser(user) {
    if (!user) return null;
    return {
      id: user.id,
      username: user.username,
      displayName: user.displayName || user.username,
      role: user.role || 'admin',
      disabled: !!user.disabled,
      mustChangePassword: !!user.mustChangePassword,
      createdAt: user.createdAt,
      lastLoginAt: user.lastLoginAt || null,
      lockedUntil: user.lockedUntil || null
    };
  }

  function findUser(username) {
    const needle = String(username || '').trim().toLowerCase();
    return users.find((u) => u.username.toLowerCase() === needle) || null;
  }

  function findUserById(id) {
    return users.find((u) => u.id === id) || null;
  }

  function enabledAdmins() {
    return users.filter((u) => !u.disabled);
  }

  function updateUsers(mutator) {
    return mutate(async () => {
      const result = await mutator(users);
      if (result && result.errors && result.errors.length) return { ok: false, errors: result.errors };
      await persistUsers();
      return { ok: true, value: result && result.value };
    });
  }

  return {
    loadContent, loadUsers, get, publicJson, update,
    listUsers, publicUser, findUser, findUserById, enabledAdmins, updateUsers,
    persistUsers,
    get users() { return users; },
    get contentPath() { return config.contentPath; }
  };
}

module.exports = { createStore };
