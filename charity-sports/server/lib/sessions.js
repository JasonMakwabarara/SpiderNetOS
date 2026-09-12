'use strict';
/* Server-side sessions in memory, mirrored to disk so a restart does not sign
   everyone out. The cookie holds an id plus an HMAC of that id, so a forged
   id is rejected before any lookup happens. */
const crypto = require('crypto');
const { writeJsonAtomic, readJson } = require('./atomic');
const { safeEqual } = require('./auth');

function sign(id, secret) {
  return crypto.createHmac('sha256', secret).update(id).digest('base64url');
}

function createSessions({ secret, sessionsPath, idleMinutes, absoluteHours, cookieSecure, logLevel }) {
  const store = new Map();
  const cookieName = cookieSecure ? '__Host-cs_sid' : 'cs_sid';
  let saveTimer = null;
  let sweepTimer = null;

  const idleMs = idleMinutes * 60 * 1000;
  const absoluteMs = absoluteHours * 60 * 60 * 1000;

  async function load() {
    const rows = await readJson(sessionsPath, []);
    if (!Array.isArray(rows)) return;
    const now = Date.now();
    rows.forEach((row) => {
      if (!row || !row.id) return;
      if (now - new Date(row.createdAt).getTime() > absoluteMs) return;
      if (now - new Date(row.lastSeenAt).getTime() > idleMs) return;
      store.set(row.id, row);
    });
  }

  function scheduleSave() {
    if (saveTimer) return;
    saveTimer = setTimeout(() => { saveTimer = null; save(); }, 10000);
    if (saveTimer.unref) saveTimer.unref();
  }

  async function save() {
    try {
      await writeJsonAtomic(sessionsPath, Array.from(store.values()));
    } catch (err) {
      if (logLevel === 'debug') console.error('[sessions] could not save:', err.message);
    }
  }

  function create(user, req) {
    const id = crypto.randomBytes(32).toString('base64url');
    const now = new Date().toISOString();
    store.set(id, {
      id,
      userId: user.id,
      createdAt: now,
      lastSeenAt: now,
      ip: (req && req.ip) || null,
      ua: req && req.get ? String(req.get('user-agent') || '').slice(0, 200) : null
    });
    scheduleSave();
    return id;
  }

  function read(cookieValue) {
    if (typeof cookieValue !== 'string' || !cookieValue.includes('.')) return null;
    const index = cookieValue.indexOf('.');
    const id = cookieValue.slice(0, index);
    const mac = cookieValue.slice(index + 1);
    if (!safeEqual(sign(id, secret), mac)) return null;

    const row = store.get(id);
    if (!row) return null;

    const now = Date.now();
    if (now - new Date(row.createdAt).getTime() > absoluteMs) { store.delete(id); return null; }
    if (now - new Date(row.lastSeenAt).getTime() > idleMs) { store.delete(id); return null; }

    /* Refresh the idle clock at most once a minute, to avoid writing on
       every single request. */
    if (now - new Date(row.lastSeenAt).getTime() > 60000) {
      row.lastSeenAt = new Date(now).toISOString();
      scheduleSave();
    }
    return row;
  }

  function destroy(id) {
    if (store.delete(id)) scheduleSave();
  }

  function destroyForUser(userId) {
    let removed = 0;
    for (const [id, row] of store) if (row.userId === userId) { store.delete(id); removed++; }
    if (removed) scheduleSave();
    return removed;
  }

  function cookieValue(id) { return `${id}.${sign(id, secret)}`; }

  function cookieOptions() {
    return {
      httpOnly: true,
      sameSite: 'lax',
      secure: cookieSecure,
      path: '/'
      /* No maxAge on purpose: it is a session cookie and the server owns
         expiry, so a stolen cookie cannot outlive the server-side record. */
    };
  }

  function startSweeper() {
    sweepTimer = setInterval(() => {
      const now = Date.now();
      let removed = 0;
      for (const [id, row] of store) {
        if (now - new Date(row.createdAt).getTime() > absoluteMs ||
            now - new Date(row.lastSeenAt).getTime() > idleMs) {
          store.delete(id); removed++;
        }
      }
      if (removed) scheduleSave();
    }, 5 * 60 * 1000);
    if (sweepTimer.unref) sweepTimer.unref();
  }

  async function stop() {
    if (saveTimer) { clearTimeout(saveTimer); saveTimer = null; }
    if (sweepTimer) { clearInterval(sweepTimer); sweepTimer = null; }
    await save();
  }

  return {
    load, create, read, destroy, destroyForUser,
    cookieName, cookieValue, cookieOptions, startSweeper, stop, save,
    get size() { return store.size; }
  };
}

module.exports = { createSessions };
