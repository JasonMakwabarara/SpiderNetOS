'use strict';
/* Append-only record of who changed what. One JSON object per line.
   Never contains a password, a hash, or a session token. */
const fs = require('fs');
const fsp = fs.promises;
const path = require('path');

const MAX_BYTES = 5 * 1024 * 1024;
const TRUNCATE = 200;

function summarise(value) {
  if (value === null || value === undefined) return null;
  if (typeof value === 'string') return value.length > TRUNCATE ? value.slice(0, TRUNCATE) + '…' : value;
  if (typeof value === 'number' || typeof value === 'boolean') return value;
  if (Array.isArray(value)) return `[${value.length} item${value.length === 1 ? '' : 's'}]`;
  return '{…}';
}

/** A shallow, truncated key-level diff. Enough to see what moved. */
function diff(before, after) {
  const out = {};
  const keys = new Set([...Object.keys(before || {}), ...Object.keys(after || {})]);
  for (const key of keys) {
    if (/password|hash|token|secret/i.test(key)) continue;      // never log these
    const a = before ? before[key] : undefined;
    const b = after ? after[key] : undefined;
    if (JSON.stringify(a) === JSON.stringify(b)) continue;
    out[key] = { from: summarise(a), to: summarise(b) };
  }
  return out;
}

function createAudit({ auditPath, logLevel = 'info' }) {
  let rotating = null;

  async function rotateIfBig() {
    if (rotating) return rotating;
    rotating = (async () => {
      try {
        const stat = await fsp.stat(auditPath);
        if (stat.size < MAX_BYTES) return;
        const stamp = new Date().toISOString().replace(/[:.]/g, '-');
        await fsp.rename(auditPath, path.join(path.dirname(auditPath), `audit-${stamp}.log`));
      } catch (err) { /* nothing to rotate */ }
    })().finally(() => { rotating = null; });
    return rotating;
  }

  return {
    async write(entry) {
      const row = {
        ts: new Date().toISOString(),
        actor: entry.actor ? { id: entry.actor.id, username: entry.actor.username } : null,
        action: entry.action,
        target: entry.target || null,
        summary: entry.summary || null,
        ip: entry.ip || null,
        ua: entry.ua ? String(entry.ua).slice(0, 200) : null,
        result: entry.result || 'ok'
      };
      try {
        await fsp.mkdir(path.dirname(auditPath), { recursive: true });
        await fsp.appendFile(auditPath, JSON.stringify(row) + '\n', { mode: 0o600 });
        await rotateIfBig();
      } catch (err) {
        if (logLevel === 'debug') console.error('[audit] could not write:', err.message);
      }
      return row;
    },

    /**
     * Newest first, paged with a simple offset cursor.
     * Filtering happens here rather than in the browser, so narrowing by
     * person or action searches the whole history instead of only the page
     * that happens to be on screen.
     */
    async read({ limit = 50, before = 0, actor = null, action = null } = {}) {
      let text = '';
      try {
        text = await fsp.readFile(auditPath, 'utf8');
      } catch (err) {
        if (err.code === 'ENOENT') {
          return { entries: [], total: 0, nextCursor: null, actors: [], actions: [] };
        }
        throw err;
      }
      const lines = text.split('\n').filter(Boolean);
      const parsed = [];
      for (let i = lines.length - 1; i >= 0; i--) {
        try { parsed.push(JSON.parse(lines[i])); } catch (err) { /* skip a torn line */ }
      }

      /* The choices offered in the filters come from the whole log, so a
         person who only appears in old entries is still selectable. */
      const actors = Array.from(new Set(parsed
        .map((row) => row.actor && row.actor.username).filter(Boolean))).sort();
      const actions = Array.from(new Set(parsed.map((row) => row.action).filter(Boolean))).sort();

      const matching = parsed.filter((row) => {
        if (actor && (!row.actor || row.actor.username !== actor)) return false;
        if (action && row.action !== action) return false;
        return true;
      });

      const start = Number(before) || 0;
      const page = matching.slice(start, start + limit);
      return {
        entries: page,
        total: matching.length,
        nextCursor: start + limit < matching.length ? start + limit : null,
        actors,
        actions
      };
    },

    diff
  };
}

module.exports = { createAudit, diff, summarise };
