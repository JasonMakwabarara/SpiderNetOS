#!/usr/bin/env node
'use strict';
/* Builds content/seed/content.seed.json from data/site-data.js, so the site
   content is written once and the server bootstraps from the same source.
   The snapshot is evaluated in a sandbox with a stub window: it is a data
   file, not a program, and nothing in it gets access to this process. */
const fs = require('fs');
const fsp = fs.promises;
const path = require('path');
const vm = require('vm');
const { load } = require('../config');
const schema = require('../lib/schema');
const { writeJsonAtomic } = require('../lib/atomic');

async function readSnapshot(snapshotPath) {
  const source = await fsp.readFile(snapshotPath, 'utf8');
  const sandbox = { window: {} };
  vm.createContext(sandbox);
  try {
    new vm.Script(source, { filename: path.basename(snapshotPath) }).runInContext(sandbox, { timeout: 2000 });
  } catch (err) {
    throw new Error(`${path.basename(snapshotPath)} could not be read: ${err.message}`);
  }
  if (!sandbox.window.CHARITY_DATA) {
    throw new Error(`${path.basename(snapshotPath)} did not set window.CHARITY_DATA.`);
  }
  return sandbox.window.CHARITY_DATA;
}

async function main() {
  const config = load();
  const raw = await readSnapshot(config.snapshotPath);
  const normalised = schema.normalise(raw);

  if (normalised.warnings && normalised.warnings.length) {
    console.log(`\n${normalised.warnings.length} field(s) were corrected while reading the snapshot:`);
    normalised.warnings.slice(0, 10).forEach((w) => console.log(`  ${w.path}: ${w.message}`));
    if (normalised.warnings.length > 10) console.log(`  ...and ${normalised.warnings.length - 10} more`);
  }
  delete normalised.warnings;

  await writeJsonAtomic(config.seedPath, normalised, { mode: 0o644 });

  const counts = schema.COLLECTIONS
    .map((key) => `${(normalised.data[key] || []).length} ${key}`)
    .join(', ');
  console.log(`\nSeed written to ${path.relative(config.rootDir, config.seedPath)}`);
  console.log(`  ${counts}\n`);
}

main().catch((err) => { console.error('\n' + err.message + '\n'); process.exit(1); });
