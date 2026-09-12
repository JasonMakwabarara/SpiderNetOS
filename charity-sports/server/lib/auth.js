'use strict';
/* Password hashing and policy.
 *
 * argon2id at the OWASP 2024 baseline, through @node-rs/argon2 (prebuilt, so
 * no compiler is needed on the host). If the native module will not load, we
 * fall back to node:crypto scrypt and record which was used in the hash
 * string, so both can coexist and a scrypt hash is upgraded to argon2id the
 * next time that person signs in successfully. */
const crypto = require('crypto');
const { promisify } = require('util');
const scrypt = promisify(crypto.scrypt);

const ARGON_OPTIONS = { memoryCost: 19456, timeCost: 2, parallelism: 1, outputLen: 32 };
/* N=2^15 with r=8 needs about 32 MB, which is exactly Node's default scrypt
   ceiling, so maxmem has to be raised or every hash throws. */
const SCRYPT = { N: 1 << 15, r: 8, p: 1, keylen: 32, maxmem: 64 * 1024 * 1024 };

let argon2 = null;
try {
  argon2 = require('@node-rs/argon2');
} catch (err) {
  argon2 = null;
}

/* A few hundred of the passwords attackers try first. Short list on purpose:
   the length rule does most of the work, this just blocks the obvious. */
const COMMON = new Set([
  'password', 'password1', 'password123', 'passw0rd', 'p@ssword', 'p@ssw0rd',
  '123456', '1234567', '12345678', '123456789', '1234567890', '12345678910',
  'qwerty', 'qwertyuiop', 'qwerty123', 'asdfghjkl', 'zxcvbnm',
  'iloveyou', 'admin', 'administrator', 'letmein', 'welcome', 'welcome1',
  'monkey', 'dragon', 'sunshine', 'princess', 'football', 'baseball',
  'charity', 'charitysports', 'charitysport', 'padel', 'harare', 'zimbabwe',
  'changeme', 'secret', 'default', 'temporary', 'abc123', 'abcd1234',
  'trustno1', 'superman', 'batman', 'starwars', 'whatever', 'password!',
  'test1234', 'root', 'toor', 'guest', 'login', 'passport'
]);

function hasArgon() { return argon2 !== null; }

async function hashPassword(plain) {
  if (argon2) return argon2.hash(plain, ARGON_OPTIONS);
  const salt = crypto.randomBytes(16);
  const key = await scrypt(plain, salt, SCRYPT.keylen,
    { N: SCRYPT.N, r: SCRYPT.r, p: SCRYPT.p, maxmem: SCRYPT.maxmem });
  return ['scrypt', SCRYPT.N, SCRYPT.r, SCRYPT.p, salt.toString('base64'), key.toString('base64')].join('$');
}

async function verifyPassword(hash, plain) {
  if (typeof hash !== 'string' || !hash) return false;
  try {
    if (hash.startsWith('scrypt$')) {
      const [, N, r, p, salt, key] = hash.split('$');
      const expected = Buffer.from(key, 'base64');
      const actual = await scrypt(plain, Buffer.from(salt, 'base64'), expected.length,
        { N: Number(N), r: Number(r), p: Number(p), maxmem: SCRYPT.maxmem });
      return expected.length === actual.length && crypto.timingSafeEqual(expected, actual);
    }
    if (!argon2) return false;
    return await argon2.verify(hash, plain);
  } catch (err) {
    return false;
  }
}

/** True when the stored hash should be re-made with the current algorithm. */
function needsRehash(hash) {
  return hasArgon() && typeof hash === 'string' && hash.startsWith('scrypt$');
}

/* A hash of a value nobody knows, verified against whenever the username does
   not exist. It makes a wrong username cost the same as a wrong password, so
   response timing does not reveal which accounts are real. */
let dummyHash = null;
async function dummyVerify(plain) {
  if (!dummyHash) dummyHash = await hashPassword(crypto.randomBytes(32).toString('hex'));
  await verifyPassword(dummyHash, plain);
  return false;
}

const MIN_LENGTH = 12;
const MAX_LENGTH = 200;

/** Length and obviousness only, per NIST SP 800-63B. No composition rules. */
function checkPasswordPolicy(plain, { username = '' } = {}) {
  const errors = [];
  if (typeof plain !== 'string' || !plain.length) {
    return [{ path: 'newPassword', message: 'Choose a password.' }];
  }
  if (plain.length < MIN_LENGTH) {
    errors.push({ path: 'newPassword', message: `Use at least ${MIN_LENGTH} characters. A short sentence works well.` });
  }
  if (plain.length > MAX_LENGTH) {
    errors.push({ path: 'newPassword', message: `Keep it under ${MAX_LENGTH} characters.` });
  }
  const lower = plain.toLowerCase();
  if (username && lower.includes(String(username).toLowerCase())) {
    errors.push({ path: 'newPassword', message: 'Do not put your username in your password.' });
  }
  if (COMMON.has(lower) || COMMON.has(lower.replace(/[^a-z0-9]/g, ''))) {
    errors.push({ path: 'newPassword', message: 'That password is one of the first ones attackers try. Pick another.' });
  }
  if (/^(.)\1+$/.test(plain)) {
    errors.push({ path: 'newPassword', message: 'That is the same character repeated. Pick another.' });
  }
  return errors;
}

/** A strong password made of unambiguous characters, for the setup script. */
function generatePassword(length = 24) {
  const alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789-_';
  const bytes = crypto.randomBytes(length * 2);
  let out = '';
  for (let i = 0; out.length < length && i < bytes.length; i++) {
    const index = bytes[i] % 256;
    if (index >= 256 - (256 % alphabet.length)) continue;   // avoid modulo bias
    out += alphabet[index % alphabet.length];
  }
  return out;
}

function safeEqual(a, b) {
  const bufA = Buffer.from(String(a || ''), 'utf8');
  const bufB = Buffer.from(String(b || ''), 'utf8');
  if (bufA.length !== bufB.length) return false;
  return crypto.timingSafeEqual(bufA, bufB);
}

module.exports = {
  hashPassword, verifyPassword, needsRehash, dummyVerify,
  checkPasswordPolicy, generatePassword, safeEqual, hasArgon,
  MIN_LENGTH, MAX_LENGTH, ARGON_OPTIONS
};
