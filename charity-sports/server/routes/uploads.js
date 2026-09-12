'use strict';
/* Image uploads. Nothing reaches the disk until the bytes have been checked. */
const express = require('express');
const multer = require('multer');
const path = require('path');
const images = require('../lib/images');
const schema = require('../lib/schema');
const { clientIp } = require('../lib/ratelimit');

function createUploadRoutes({ config, store, audit }) {
  const router = express.Router();

  const upload = multer({
    storage: multer.memoryStorage(),
    limits: { fileSize: config.uploadMaxBytes, files: 1, fields: 10, parts: 12 }
  });

  router.post('/', upload.single('file'), async (req, res, next) => {
    try {
      if (!req.file) {
        return res.status(422).json({ errors: [{ path: 'file', message: 'Choose an image to upload.' }] });
      }
      const kind = String((req.body && req.body.kind) || 'gallery');
      if (!images.PRESETS[kind]) {
        return res.status(422).json({ errors: [{ path: 'kind', message: 'Unknown image type.' }] });
      }

      const result = await images.storeUpload({
        buffer: req.file.buffer,
        originalName: req.file.originalname,
        declaredType: req.file.mimetype,
        kind,
        config
      });

      await audit.write({
        actor: req.user,
        action: 'upload',
        target: Object.values(result.ref).find((v) => typeof v === 'string') || kind,
        summary: { kind, detectedAs: result.sniffed, originalName: result.originalName },
        ip: clientIp(req)
      });

      res.status(201).json({ ok: true, ref: result.ref });
    } catch (err) {
      if (err instanceof images.UploadError) {
        return res.status(err.status).json({ error: 'bad_upload', message: err.message });
      }
      next(err);
    }
  });

  router.delete('/:name', async (req, res, next) => {
    try {
      const name = path.basename(req.params.name);
      const needle = `${config.uploadUrlBase}/${name}`;

      /* Refuse while anything still points at it, so the site cannot end up
         with a broken image because of a tidy-up. */
      const data = store.get().data;
      const used = JSON.stringify(data).includes(needle);
      if (used) {
        return res.status(409).json({
          error: 'in_use',
          message: 'That image is still used somewhere on the site. Remove it there first.'
        });
      }

      await images.removeUpload(name, config);
      await audit.write({ actor: req.user, action: 'delete_upload', target: name, ip: clientIp(req) });
      res.json({ ok: true });
    } catch (err) {
      if (err instanceof images.UploadError) {
        return res.status(err.status).json({ error: 'bad_upload', message: err.message });
      }
      if (err.code === 'ENOENT') {
        return res.status(404).json({ error: 'not_found', message: 'That file is already gone.' });
      }
      next(err);
    }
  });

  return router;
}

module.exports = { createUploadRoutes };
