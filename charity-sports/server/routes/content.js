'use strict';
/* Reading and writing content from the admin panel.
 *
 * Every write carries If-Match with the updatedAt the admin last saw. If
 * someone else saved in the meantime the write is refused with a 409 and the
 * current value, so two people editing at once cannot silently clobber each
 * other. */
const express = require('express');
const schema = require('../lib/schema');
const { clientIp } = require('../lib/ratelimit');

function meta(req, action, target, section) {
  return {
    actor: req.user,
    action,
    target,
    section,
    ip: clientIp(req),
    ua: req.get('user-agent'),
    ifMatch: req.get('if-match') || (req.body && req.body._ifMatch) || null
  };
}

function handle(res, result) {
  if (result.conflict) {
    return res.status(409).json({
      error: 'conflict',
      message: 'Someone else saved a change while you were editing. Reload and apply your change again.',
      current: result.current
    });
  }
  if (result.errors) return res.status(422).json({ errors: result.errors });
  return res.json({ ok: true, updatedAt: result.updatedAt, value: result.value });
}

function createContentRoutes({ store, audit }) {
  const router = express.Router();

  /* The admin sees everything, including items hidden from the public site. */
  router.get('/content', (req, res) => {
    const content = store.get();
    res.setHeader('Cache-Control', 'no-store');
    res.json({ schemaVersion: content.schemaVersion, updatedAt: content.updatedAt, data: content.data });
  });

  /* ------------------------------------------------------------- sections */
  router.put('/content/:section', async (req, res, next) => {
    try {
      const section = req.params.section;
      if (!schema.SECTIONS.includes(section)) {
        return res.status(404).json({ error: 'not_found', message: `There is no "${section}" section.` });
      }
      const check = schema.validate(section, req.body && req.body.value);
      if (!check.ok) return res.status(422).json({ errors: check.errors });

      const result = await store.update((draft) => {
        draft[section] = check.value;
        return { value: check.value };
      }, meta(req, 'update_section', section, section));
      return handle(res, result);
    } catch (err) { next(err); }
  });

  /* ---------------------------------------------------------- collections */
  function collectionGuard(req, res) {
    const name = req.params.collection;
    if (!schema.COLLECTIONS.includes(name)) {
      res.status(404).json({ error: 'not_found', message: `There is no "${name}" list.` });
      return null;
    }
    return name;
  }

  router.post('/:collection', async (req, res, next) => {
    try {
      const name = collectionGuard(req, res);
      if (!name) return;
      const check = schema.validate(name, req.body && req.body.value);
      if (!check.ok) return res.status(422).json({ errors: check.errors });

      let created = null;
      const result = await store.update((draft) => {
        const list = draft[name] || (draft[name] = []);
        const item = { ...check.value };
        const taken = new Set(list.map((row) => row.id));
        item.id = schema.uniqueId(item.id || schema.slugify(item.title || item.name, name), taken, name);
        item.order = list.length;
        list.push(item);
        created = item;
        return { value: item };
      }, meta(req, 'create_item', `${name}/${(check.value && (check.value.title || check.value.name)) || ''}`, name));

      if (result.ok) return res.status(201).json({ ok: true, updatedAt: result.updatedAt, value: created });
      return handle(res, result);
    } catch (err) { next(err); }
  });

  router.put('/:collection/:id', async (req, res, next) => {
    try {
      const name = collectionGuard(req, res);
      if (!name) return;
      const check = schema.validate(name, req.body && req.body.value);
      if (!check.ok) return res.status(422).json({ errors: check.errors });

      let missing = false;
      const result = await store.update((draft) => {
        const list = draft[name] || [];
        const index = list.findIndex((row) => row.id === req.params.id);
        if (index === -1) { missing = true; return; }
        /* The id and the position are the server's to decide, not the
           client's, so a rename cannot break links or reshuffle the page. */
        list[index] = { ...check.value, id: list[index].id, order: list[index].order };
        return { value: list[index] };
      }, meta(req, 'update_item', `${name}/${req.params.id}`, name));

      if (missing) return res.status(404).json({ error: 'not_found', message: 'That item no longer exists.' });
      return handle(res, result);
    } catch (err) { next(err); }
  });

  router.delete('/:collection/:id', async (req, res, next) => {
    try {
      const name = collectionGuard(req, res);
      if (!name) return;
      let missing = false;
      let removed = null;
      const result = await store.update((draft) => {
        const list = draft[name] || [];
        const index = list.findIndex((row) => row.id === req.params.id);
        if (index === -1) { missing = true; return; }
        removed = list.splice(index, 1)[0];
        return { value: removed };
      }, meta(req, 'delete_item', `${name}/${req.params.id}`, name));

      if (missing) return res.status(404).json({ error: 'not_found', message: 'That item no longer exists.' });
      if (result.ok) {
        await audit.write({
          actor: req.user, action: 'delete_item', target: `${name}/${req.params.id}`,
          summary: { title: removed && (removed.title || removed.name) }, ip: clientIp(req)
        });
      }
      return handle(res, result);
    } catch (err) { next(err); }
  });

  router.post('/:collection/reorder', async (req, res, next) => {
    try {
      const name = collectionGuard(req, res);
      if (!name) return;
      const ids = Array.isArray(req.body && req.body.ids) ? req.body.ids : null;
      if (!ids) return res.status(422).json({ errors: [{ path: 'ids', message: 'Send the new order as a list of ids.' }] });

      let problem = null;
      const result = await store.update((draft) => {
        const list = draft[name] || [];
        const byId = new Map(list.map((row) => [row.id, row]));
        if (ids.length !== list.length || ids.some((id) => !byId.has(id))) {
          problem = 'That list does not match what is on the server. Reload and try again.';
          return { errors: [{ path: 'ids', message: problem }] };
        }
        draft[name] = ids.map((id, i) => ({ ...byId.get(id), order: i }));
        return { value: ids };
      }, meta(req, 'reorder', name, name));

      return handle(res, result);
    } catch (err) { next(err); }
  });

  return router;
}

module.exports = { createContentRoutes, handle, meta };
