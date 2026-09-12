'use strict';
/* Entry point. Loads config, builds the app, listens, and shuts down cleanly
   so open sessions are flushed to disk instead of lost. */
const { load } = require('./config');
const { createApp } = require('./app');

async function main() {
  let config;
  try {
    config = load();
  } catch (err) {
    console.error('\nConfiguration problem:\n  ' + err.message + '\n');
    process.exit(1);
  }

  let app;
  try {
    app = await createApp(config);
  } catch (err) {
    console.error('\nCould not start:\n  ' + err.message + '\n');
    process.exit(1);
  }

  const server = app.listen(config.port, config.host, () => {
    const where = `http://${config.host === '0.0.0.0' ? 'localhost' : config.host}:${config.port}`;
    console.log(`Charity Sports server running in ${config.env} mode`);
    console.log(`  Public site : ${where}/`);
    console.log(`  Admin panel : ${where}/admin`);
    if (!app.locals.store.users.length) {
      console.log('\n  No admin account exists yet. Create one with:  npm run setup\n');
    }
  });

  let closing = false;
  async function shutdown(signal) {
    if (closing) return;
    closing = true;
    console.log(`\n${signal} received, shutting down.`);
    server.close();
    try { await app.locals.shutdown(); } catch (err) { /* best effort */ }
    process.exit(0);
  }
  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('SIGINT', () => shutdown('SIGINT'));
}

main();
