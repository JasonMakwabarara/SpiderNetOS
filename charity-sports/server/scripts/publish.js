#!/usr/bin/env node
'use strict';
/* Publish from the command line, for a cron job or a quick check. */
const { load } = require('../config');
const { createStore } = require('../lib/store');
const { createAudit } = require('../lib/audit');
const publisher = require('../lib/publish');

async function main() {
  const config = load();
  const audit = createAudit({ auditPath: config.auditPath, logLevel: config.logLevel });
  const store = createStore(config, audit);
  await store.loadContent();
  const result = await publisher.publish(store.get(), config, {});
  console.log('\n' + result.message);
  if (result.commitUrl) console.log('  ' + result.commitUrl);
  console.log('');
  if (result.error) process.exit(1);
}

main().catch((err) => { console.error('\n' + err.message + '\n'); process.exit(1); });
