#!/usr/bin/env node
'use strict';
/* Sets a new random password for an account and requires a change at next
   sign-in. This is the way back in when someone is locked out. */
const { load } = require('../config');
const { createStore } = require('../lib/store');
const { createAudit } = require('../lib/audit');
const auth = require('../lib/auth');

async function main() {
  const username = (process.argv[2] || '').trim();
  const config = load();
  const audit = createAudit({ auditPath: config.auditPath, logLevel: config.logLevel });
  const store = createStore(config, audit);
  await store.loadUsers();

  if (!store.users.length) {
    console.error('\nThere are no accounts yet. Create one with:  npm run setup\n');
    process.exit(1);
  }
  if (!username) {
    console.error(`\nWhich account? Try:\n  npm run reset-password -- ${store.users[0].username}\n`);
    console.error('Accounts: ' + store.users.map((u) => u.username).join(', ') + '\n');
    process.exit(1);
  }

  const user = store.findUser(username);
  if (!user) {
    console.error(`\nNo account called "${username}". Accounts: ${store.users.map((u) => u.username).join(', ')}\n`);
    process.exit(1);
  }

  const password = auth.generatePassword(24);
  const hash = await auth.hashPassword(password);
  await store.updateUsers((users) => {
    const row = users.find((u) => u.id === user.id);
    row.passwordHash = hash;
    row.mustChangePassword = true;
    row.failedCount = 0;
    row.lockedUntil = null;
    row.disabled = false;
    row.updatedAt = new Date().toISOString();
  });
  await audit.write({ action: 'reset_password', target: user.username, summary: { via: 'reset script' } });

  const line = '='.repeat(60);
  console.log(`\n${line}`);
  console.log(`  New password for ${user.username}`);
  console.log(line);
  console.log(`  ${password}`);
  console.log(line);
  console.log('  Shown once. Any lock on the account has been cleared and a');
  console.log('  password change is required at next sign-in.');
  console.log(`${line}\n`);
  console.log('  Anyone signed in as this account has been signed out.\n');
}

main().catch((err) => { console.error(err.message); process.exit(1); });
