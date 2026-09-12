#!/usr/bin/env node
'use strict';
/* Creates the first admin account and prints its password once.
   The password is never stored in the clear and never written to the log. */
const crypto = require('crypto');
const { load } = require('../config');
const { createStore } = require('../lib/store');
const { createAudit } = require('../lib/audit');
const auth = require('../lib/auth');
const { USERNAME } = require('../routes/users');

async function main() {
  const args = process.argv.slice(2);
  const force = args.includes('--force');
  const nameArg = args.find((a) => !a.startsWith('--'));
  const username = (nameArg || 'admin').trim();

  if (!USERNAME.test(username)) {
    console.error(`"${username}" is not a usable username. Use 3 to 32 letters, numbers, dots, dashes or underscores.`);
    process.exit(1);
  }

  const config = load();
  const audit = createAudit({ auditPath: config.auditPath, logLevel: config.logLevel });
  const store = createStore(config, audit);
  await store.loadUsers();

  if (store.users.length && !force) {
    console.error(
      `\nAn account already exists (${store.users.map((u) => u.username).join(', ')}).\n` +
      `  To reset its password:   npm run reset-password -- ${store.users[0].username}\n` +
      `  To add another account:  use the Users screen in the admin panel.\n` +
      `  To start over anyway:    npm run setup -- ${username} --force\n`
    );
    process.exit(1);
  }
  if (store.findUser(username)) {
    console.error(`\n"${username}" already exists. Reset its password instead:\n  npm run reset-password -- ${username}\n`);
    process.exit(1);
  }

  const password = auth.generatePassword(24);
  const now = new Date().toISOString();
  const user = {
    id: crypto.randomUUID(),
    username,
    displayName: username,
    passwordHash: await auth.hashPassword(password),
    role: 'admin',
    mustChangePassword: true,
    disabled: false,
    failedCount: 0,
    lockedUntil: null,
    createdAt: now,
    updatedAt: now,
    lastLoginAt: null
  };

  await store.updateUsers((users) => { users.push(user); });
  await audit.write({ action: 'create_user', target: username, summary: { via: 'setup script' } });

  const line = '='.repeat(60);
  console.log(`\n${line}`);
  console.log('  Admin account created');
  console.log(line);
  console.log(`  Username:  ${username}`);
  console.log(`  Password:  ${password}`);
  console.log(line);
  console.log('  Copy this password now. It is not stored anywhere and cannot');
  console.log('  be shown again. You will be asked to change it when you first');
  console.log('  sign in.');
  console.log(`${line}\n`);
  console.log(`  Sign in at:  http://localhost:${config.port}/admin\n`);

  if (!auth.hasArgon()) {
    console.log('  Note: the argon2 module did not load, so scrypt was used instead.');
    console.log('  That is still secure. Reinstall dependencies to switch to argon2.\n');
  }
}

main().catch((err) => { console.error(err.message); process.exit(1); });
