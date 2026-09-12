'use strict';
/* One shape, used by the API, the admin forms and the published snapshot.
 *
 * validate() never throws on bad input: it returns the cleaned value plus a
 * list of {path, message} so the admin panel can paint the message under the
 * offending field. Nothing is ever stored as HTML, and every URL is checked
 * against a scheme allowlist. */

const SCHEMA_VERSION = 1;

const SECTIONS = ['org', 'hero', 'impact', 'about', 'accountability', 'share', 'signup', 'donate',
  'contact', 'sponsorsMeta', 'sponsorTiers', 'api'];
const COLLECTIONS = ['causes', 'events', 'sponsors', 'gallery', 'waysToSupport'];

const ICONS = ['target', 'racket', 'heart', 'star', 'camera', 'calendar', 'pin', 'clock', 'whatsapp', 'mail', 'arrow'];
const ACCENTS = ['green', 'orange', 'gold'];
const CAUSE_STATUS = ['current', 'funded', 'closed'];
const GALLERY_TYPES = ['photo', 'poster', 'video'];
const TIER_SIZES = ['large', 'normal'];

/* Keyword hrefs the front end resolves itself, so the WhatsApp number and the
   donate URL only ever live in one place. */
const HREF_KEYWORDS = /^(donate|email|tel|whatsapp(:[a-z][a-z0-9_-]{0,30})?)$/;
const SLUG = /^[a-z0-9][a-z0-9-]{0,63}$/;
const DATE = /^(\d{4})-(\d{2})-(\d{2})$/;
const TIME = /^([01]\d|2[0-3]):([0-5]\d)$/;
const EMAIL = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;
const ASSET_PATH = /^assets\/[A-Za-z0-9][A-Za-z0-9._/-]*$/;

/* ------------------------------------------------------------------ toolkit */

class Ctx {
  constructor() { this.errors = []; }
  fail(path, message) { this.errors.push({ path, message }); return undefined; }
}

/* Control characters become a space; zero-width marks are dropped entirely,
 * because they can hide text inside a string that looks innocent. Runs of
 * whitespace then collapse. No field in this schema is multi-line: paragraphs
 * and bullet lists are stored as arrays, one entry per line. */
function cleanString(value) {
  if (typeof value !== 'string') return null;
  let out = '';
  for (const ch of value) {
    const code = ch.codePointAt(0);
    const zeroWidth = (code >= 0x200b && code <= 0x200d) || code === 0x2060 || code === 0xfeff;
    const control = code < 0x20 || code === 0x7f || (code >= 0x80 && code <= 0x9f);
    if (zeroWidth) continue;
    out += control ? ' ' : ch;
  }
  return out.normalize('NFC').replace(/\s+/g, ' ').trim();
}

const T = {
  string({ min = 0, max = 400, required = false, allowEmpty = true } = {}) {
    return (value, path, ctx) => {
      if (value === null || value === undefined) {
        if (required) return ctx.fail(path, 'This is required.');
        return null;
      }
      const text = cleanString(value);
      if (text === null) return ctx.fail(path, 'Must be text.');
      if (!text.length) {
        if (required) return ctx.fail(path, 'This is required.');
        return allowEmpty ? '' : null;
      }
      if (text.length < min) return ctx.fail(path, `Must be at least ${min} characters.`);
      if (text.length > max) return ctx.fail(path, `Must be ${max} characters or fewer.`);
      return text;
    };
  },

  integer({ min = 0, max = Number.MAX_SAFE_INTEGER, required = false } = {}) {
    return (value, path, ctx) => {
      if (value === null || value === undefined || value === '') {
        if (required) return ctx.fail(path, 'This is required.');
        return null;
      }
      const n = typeof value === 'number' ? value : Number(value);
      if (!Number.isFinite(n)) return ctx.fail(path, 'Must be a number.');
      const i = Math.trunc(n);
      if (i < min) return ctx.fail(path, `Must be ${min} or more.`);
      if (i > max) return ctx.fail(path, `Must be ${max} or less.`);
      return i;
    };
  },

  bool(fallback = false) {
    return (value) => (value === undefined || value === null
      ? fallback
      : value !== false && value !== 'false' && value !== 0 && value !== '0');
  },

  enum(values, { required = false, fallback = null } = {}) {
    return (value, path, ctx) => {
      if (value === null || value === undefined || value === '') {
        if (required) return ctx.fail(path, 'This is required.');
        return fallback;
      }
      const text = cleanString(value);
      if (!values.includes(text)) return ctx.fail(path, `Must be one of: ${values.join(', ')}.`);
      return text;
    };
  },

  url({ required = false, schemes = ['https:'], max = 2000 } = {}) {
    return (value, path, ctx) => {
      if (value === null || value === undefined || value === '') {
        if (required) return ctx.fail(path, 'This is required.');
        return null;
      }
      const text = cleanString(value);
      if (text.length > max) return ctx.fail(path, 'That link is too long.');
      let parsed;
      try { parsed = new URL(text); } catch (err) {
        return ctx.fail(path, 'Must be a full web address starting with https://');
      }
      if (!schemes.includes(parsed.protocol)) {
        return ctx.fail(path, `Link must start with ${schemes.map((s) => s + '//').join(' or ')}`);
      }
      return parsed.toString();
    };
  },

  href({ required = false } = {}) {
    return (value, path, ctx) => {
      if (value === null || value === undefined || value === '') {
        if (required) return ctx.fail(path, 'This is required.');
        return null;
      }
      const text = cleanString(value);
      if (HREF_KEYWORDS.test(text)) return text;
      if (/^#[A-Za-z][\w-]*$/.test(text)) return text;
      try {
        const parsed = new URL(text);
        if (!['https:', 'mailto:', 'tel:'].includes(parsed.protocol)) {
          return ctx.fail(path, 'Links must be https, mailto or tel.');
        }
        return parsed.toString();
      } catch (err) {
        return ctx.fail(path, 'Must be a link, a #section, or one of: donate, whatsapp, email, tel.');
      }
    };
  },

  date({ required = false } = {}) {
    return (value, path, ctx) => {
      if (value === null || value === undefined || value === '') {
        if (required) return ctx.fail(path, 'This is required.');
        return null;
      }
      const text = cleanString(value);
      const m = DATE.exec(text);
      if (!m) return ctx.fail(path, 'Use the form 2026-09-17.');
      const y = Number(m[1]), mo = Number(m[2]), d = Number(m[3]);
      const probe = new Date(Date.UTC(y, mo - 1, d));
      if (probe.getUTCFullYear() !== y || probe.getUTCMonth() !== mo - 1 || probe.getUTCDate() !== d) {
        return ctx.fail(path, 'That date does not exist.');
      }
      return text;
    };
  },

  time({ required = false } = {}) {
    return (value, path, ctx) => {
      if (value === null || value === undefined || value === '') {
        if (required) return ctx.fail(path, 'This is required.');
        return null;
      }
      const text = cleanString(value);
      if (!TIME.test(text)) return ctx.fail(path, 'Use 24-hour time, like 19:00.');
      return text;
    };
  },

  assetPath({ required = false } = {}) {
    return (value, path, ctx) => {
      if (value === null || value === undefined || value === '') {
        if (required) return ctx.fail(path, 'This is required.');
        return null;
      }
      const text = cleanString(value);
      if (text.includes('..') || text.startsWith('/')) {
        return ctx.fail(path, 'Must be a relative path inside assets/.');
      }
      if (!ASSET_PATH.test(text)) return ctx.fail(path, 'Must be a file under assets/.');
      return text;
    };
  },

  arrayOf(inner, { max = 50, min = 0 } = {}) {
    return (value, path, ctx) => {
      if (value === null || value === undefined) return [];
      if (!Array.isArray(value)) { ctx.fail(path, 'Must be a list.'); return []; }
      if (value.length > max) ctx.fail(path, `No more than ${max} items.`);
      if (value.length < min) ctx.fail(path, `At least ${min} item${min === 1 ? '' : 's'} needed.`);
      return value.slice(0, max)
        .map((item, i) => inner(item, `${path}[${i}]`, ctx))
        .filter((item) => item !== undefined && item !== null && item !== '');
    };
  },

  object(shape, { nullable = false } = {}) {
    return (value, path, ctx) => {
      if (value === null || value === undefined) return nullable ? null : defaults(shape, path, ctx);
      if (typeof value !== 'object' || Array.isArray(value)) return ctx.fail(path, 'Must be a group of fields.');
      const out = {};
      Object.keys(shape).forEach((key) => {
        const result = shape[key](value[key], path ? `${path}.${key}` : key, ctx);
        if (result !== undefined) out[key] = result;
      });
      return out;
    };
  }
};

function defaults(shape, path, ctx) {
  const out = {};
  Object.keys(shape).forEach((key) => {
    const result = shape[key](undefined, path ? `${path}.${key}` : key, ctx);
    if (result !== undefined) out[key] = result;
  });
  return out;
}

/* ------------------------------------------------------------------ shapes */

const imageRef = T.object({
  src: T.assetPath(),
  webp: T.assetPath(),
  srcSmall: T.assetPath(),
  webpSmall: T.assetPath(),
  thumb: T.assetPath(),
  thumbWebp: T.assetPath(),
  width: T.integer({ min: 1, max: 10000 }),
  height: T.integer({ min: 1, max: 10000 }),
  alt: T.string({ max: 300 })
}, { nullable: true });

const ctaRef = T.object({
  label: T.string({ max: 40 }),
  href: T.href()
}, { nullable: true });

const SHAPES = {
  org: T.object({
    name: T.string({ max: 120, required: true }),
    trustName: T.string({ max: 120 }),
    tagline: T.string({ max: 140 }),
    slogans: T.arrayOf(T.string({ max: 140 }), { max: 5 }),
    location: T.string({ max: 120 }),
    siteUrl: T.url(),
    mission: T.string({ max: 2000 })
  }),

  hero: T.object({
    eyebrow: T.string({ max: 120 }),
    headline: T.string({ max: 120, required: true }),
    subheadline: T.string({ max: 400 }),
    primaryCta: ctaRef,
    secondaryCta: ctaRef,
    image: imageRef
  }),

  impact: T.object({
    livesHelped: T.integer({ min: 0, max: 1e9 }),
    goal: T.integer({ min: 1, max: 1e9 }),
    raisedTotalUsd: T.integer({ min: 0, max: 1e9 }),
    raisedLabel: T.string({ max: 60 }),
    milestones: T.arrayOf(T.integer({ min: 1, max: 1e9 }), { max: 8 }),
    lastUpdated: T.date(),
    heading: T.string({ max: 120 }),
    blurb: T.string({ max: 600 }),
    note: T.string({ max: 600 }),
    remoteUrl: T.url(),
    stats: T.arrayOf(T.object({
      label: T.string({ max: 60 }),
      value: T.string({ max: 20 }),
      suffix: T.string({ max: 6 })
    }), { max: 6 })
  }),

  /* Facts a donor can check the charity against. Every field is optional and
     the section hides itself while they are all empty, so nothing unverified
     is ever shown. */
  accountability: T.object({
    heading: T.string({ max: 120 }),
    intro: T.string({ max: 600 }),
    registrationLabel: T.string({ max: 60 }),
    registrationNumber: T.string({ max: 60 }),
    bankedWith: T.string({ max: 120 }),
    financeContactName: T.string({ max: 80 }),
    financeContactRole: T.string({ max: 60 }),
    financeContactEmail: (value, path, ctx) => {
      if (value === null || value === undefined || value === '') return '';
      const text = cleanString(value);
      if (text.length > 254 || !EMAIL.test(text)) return ctx.fail(path, 'Must be an email address.');
      return text;
    },
    receiptsPolicy: T.string({ max: 400 }),
    statements: T.arrayOf(T.string({ max: 300 }), { max: 6 })
  }),

  about: T.object({
    heading: T.string({ max: 120 }),
    paragraphs: T.arrayOf(T.string({ max: 900 }), { max: 8 }),
    howItWorks: T.arrayOf(T.object({
      icon: T.enum(ICONS, { fallback: 'target' }),
      title: T.string({ max: 40 }),
      text: T.string({ max: 240 })
    }), { max: 6 })
  }),

  share: T.object({
    label: T.string({ max: 40 }),
    message: T.string({ max: 300 })
  }),

  signup: T.object({
    heading: T.string({ max: 120 }),
    text: T.string({ max: 600 }),
    nameLabel: T.string({ max: 60 }),
    contactLabel: T.string({ max: 60 }),
    buttonLabel: T.string({ max: 40 }),
    successText: T.string({ max: 300 }),
    fallbackLabel: T.string({ max: 60 }),
    privacyNote: T.string({ max: 400 })
  }),

  donate: T.object({
    url: T.url({ required: true }),
    provider: T.string({ max: 40 }),
    label: T.string({ max: 40 }),
    heading: T.string({ max: 120 }),
    text: T.string({ max: 600 }),
    whereMoneyGoes: T.arrayOf(T.string({ max: 300 }), { max: 8 }),
    paymentNote: T.string({ max: 400 })
  }),

  contact: T.object({
    whatsapp: (value, path, ctx) => {
      if (value === null || value === undefined || value === '') return '';
      const text = cleanString(value);
      const digits = text.replace(/\D/g, '');
      if (digits.length < 8 || digits.length > 15) {
        return ctx.fail(path, 'Needs between 8 and 15 digits, for example +263 776 437 764.');
      }
      return text;
    },
    email: (value, path, ctx) => {
      if (value === null || value === undefined || value === '') return '';
      const text = cleanString(value);
      if (text.length > 254 || !EMAIL.test(text)) return ctx.fail(path, 'Must be an email address.');
      return text;
    },
    emailSubject: T.string({ max: 120 }),
    social: T.arrayOf(T.object({
      label: T.string({ max: 40 }),
      url: T.url()
    }), { max: 8 }),
    whatsappMessages: T.object({
      default: T.string({ max: 300 }),
      register: T.string({ max: 300 }),
      sponsor: T.string({ max: 300 }),
      portrait: T.string({ max: 300 }),
      monthly: T.string({ max: 300 }),
      golf: T.string({ max: 300 })
    })
  }),

  sponsorsMeta: T.object({
    intro: T.string({ max: 240 }),
    ctaTile: T.object({
      title: T.string({ max: 60 }),
      subtitle: T.string({ max: 60 }),
      label: T.string({ max: 40 }),
      href: T.href()
    }, { nullable: true })
  }),

  api: T.object({
    baseUrl: (value, path, ctx) => {
      if (value === null || value === undefined || value === '') return '';
      const text = cleanString(value);
      try {
        const parsed = new URL(text);
        if (parsed.protocol !== 'https:') return ctx.fail(path, 'Must start with https://');
        return parsed.origin;
      } catch (err) {
        return ctx.fail(path, 'Must be empty (same site) or a full https:// address.');
      }
    }
  }),

  sponsorTiers: T.arrayOf(T.object({
    id: T.string({ max: 64, required: true }),
    title: T.string({ max: 80, required: true }),
    subtitle: T.string({ max: 140 }),
    size: T.enum(TIER_SIZES, { fallback: 'normal' }),
    order: T.integer({ min: 0, max: 9999 })
  }), { max: 8, min: 1 }),

  /* ------------------------------------------------------------ collections */

  causes: T.object({
    id: T.string({ max: 64 }),
    status: T.enum(CAUSE_STATUS, { fallback: 'current' }),
    order: T.integer({ min: 0, max: 9999 }),
    active: T.bool(true),
    tag: T.string({ max: 40 }),
    title: T.string({ max: 90, required: true }),
    summary: T.string({ max: 600 }),
    details: T.arrayOf(T.string({ max: 300 }), { max: 8 }),
    stats: T.arrayOf(T.object({
      value: T.string({ max: 16 }),
      label: T.string({ max: 32 })
    }), { max: 4 }),
    targetUsd: T.integer({ min: 0, max: 1e7 }),
    raisedUsd: T.integer({ min: 0, max: 1e7 }),
    beneficiaries: T.integer({ min: 0, max: 1e7 }),
    period: T.string({ max: 40 }),
    accent: T.enum(ACCENTS, { fallback: 'green' }),
    image: imageRef,
    cta: ctaRef
  }),

  events: T.object({
    id: T.string({ max: 64 }),
    order: T.integer({ min: 0, max: 9999 }),
    active: T.bool(true),
    featured: T.bool(false),
    sport: T.string({ max: 40 }),
    kind: T.string({ max: 40 }),
    title: T.string({ max: 110, required: true }),
    summary: T.string({ max: 600 }),
    startDate: T.date(),
    endDate: T.date(),
    startTime: T.time(),
    endTime: T.time(),
    dateNote: T.string({ max: 120 }),
    sessions: T.arrayOf(T.object({
      date: T.date({ required: true }),
      startTime: T.time(),
      endTime: T.time(),
      label: T.string({ max: 40 }),
      final: T.bool(false)
    }), { max: 60 }),
    highlights: T.arrayOf(T.string({ max: 80 }), { max: 8 }),
    venue: T.object({
      name: T.string({ max: 90 }),
      address: T.string({ max: 200 }),
      mapsUrl: T.url()
    }, { nullable: true }),
    poster: imageRef,
    ctas: T.arrayOf(ctaRef, { max: 3 }),
    note: T.string({ max: 240 })
  }),

  sponsors: T.object({
    id: T.string({ max: 64 }),
    name: T.string({ max: 80, required: true }),
    tier: T.string({ max: 64, required: true }),
    tagline: T.string({ max: 100 }),
    logo: T.assetPath(),
    logoWebp: T.assetPath(),
    logoBg: T.enum(['dark', 'light'], { fallback: 'dark' }),
    alt: T.string({ max: 140 }),
    link: T.url(),
    order: T.integer({ min: 0, max: 9999 }),
    active: T.bool(true)
  }),

  gallery: T.object({
    id: T.string({ max: 64 }),
    order: T.integer({ min: 0, max: 9999 }),
    active: T.bool(true),
    type: T.enum(GALLERY_TYPES, { fallback: 'photo' }),
    title: T.string({ max: 90 }),
    caption: T.string({ max: 200 }),
    src: T.assetPath(),
    webp: T.assetPath(),
    thumb: T.assetPath(),
    thumbWebp: T.assetPath(),
    width: T.integer({ min: 1, max: 10000 }),
    height: T.integer({ min: 1, max: 10000 }),
    alt: T.string({ max: 300 }),
    provider: T.enum(['youtube', 'file'], { fallback: null }),
    youtubeId: (value, path, ctx) => {
      if (value === null || value === undefined || value === '') return null;
      const text = cleanString(value);
      const m = /(?:v=|\/embed\/|youtu\.be\/|^)([A-Za-z0-9_-]{11})(?:[?&].*)?$/.exec(text);
      if (!m) return ctx.fail(path, 'Paste a YouTube link or its 11-character video id.');
      return m[1];
    },
    videoSrc: T.assetPath(),
    poster: T.assetPath()
  }),

  waysToSupport: T.object({
    id: T.string({ max: 64 }),
    order: T.integer({ min: 0, max: 9999 }),
    active: T.bool(true),
    icon: T.enum(ICONS, { fallback: 'heart' }),
    title: T.string({ max: 40, required: true }),
    price: T.string({ max: 40 }),
    text: T.string({ max: 300 }),
    cta: ctaRef
  })
};

/* ------------------------------------------------------------ cross-checks */

const CROSS = {
  events(event, path, ctx) {
    if (event.startDate && event.endDate && event.endDate < event.startDate) {
      ctx.fail(`${path}.endDate`, 'The end date cannot be before the start date.');
    }
    if (event.startTime && event.endTime && event.endTime <= event.startTime) {
      ctx.fail(`${path}.endTime`, 'The finish time must be after the start time.');
    }
  },
  causes(cause, path, ctx) {
    if (typeof cause.raisedUsd === 'number' && typeof cause.targetUsd !== 'number') {
      ctx.fail(`${path}.raisedUsd`, 'Set a target before recording what has been raised.');
    }
  },
  gallery(item, path, ctx) {
    if (item.type === 'video') {
      if (!item.youtubeId && !item.videoSrc) {
        ctx.fail(`${path}.youtubeId`, 'A video needs a YouTube link or an uploaded video file.');
      }
    } else if (!item.src) {
      ctx.fail(`${path}.src`, 'Upload an image for this item.');
    }
    if (item.type !== 'video' && !item.alt) {
      ctx.fail(`${path}.alt`, 'Describe the picture so people using a screen reader know what it shows.');
    }
  },
  impact(impact, path, ctx) {
    if (typeof impact.livesHelped === 'number' && typeof impact.goal === 'number' && impact.livesHelped > impact.goal) {
      ctx.fail(`${path}.goal`, 'The goal cannot be lower than the number already reached.');
    }
    const ms = impact.milestones || [];
    for (let i = 1; i < ms.length; i++) {
      if (ms[i] <= ms[i - 1]) { ctx.fail(`${path}.milestones`, 'Milestones must increase.'); break; }
    }
  }
};

/* --------------------------------------------------------------- public API */

function slugify(text, fallback = 'item') {
  const cleaned = cleanString(text) || '';
  const slug = cleaned
    .toLowerCase()
    .replace(/[‘’']/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 64);
  return SLUG.test(slug) ? slug : fallback;
}

function uniqueId(candidate, taken, fallback = 'item') {
  const id = SLUG.test(candidate || '') ? candidate : slugify(candidate, fallback);
  if (!taken.has(id)) return id;
  let n = 2;
  while (taken.has(`${id}-${n}`)) n++;
  return `${id}-${n}`;
}

/** Validate one entity. `kind` is a section name or a collection name. */
function validate(kind, value) {
  const ctx = new Ctx();
  const shape = SHAPES[kind];
  if (!shape) return { ok: false, value: null, errors: [{ path: kind, message: 'Unknown section.' }] };
  const out = shape(value, '', ctx);
  const cross = CROSS[kind];
  if (cross && out && !ctx.errors.length) cross(out, kind, ctx);
  return { ok: ctx.errors.length === 0, value: out, errors: ctx.errors };
}

/** Fill in anything missing and renumber, so a partial file still boots. */
function normalise(raw) {
  const input = raw && typeof raw === 'object' ? raw : {};
  const data = input.data && typeof input.data === 'object' ? input.data : input;
  const ctx = new Ctx();
  const out = {};

  SECTIONS.forEach((key) => {
    if (key === 'sponsorTiers') {
      const tiers = Array.isArray(data[key]) && data[key].length ? data[key] : [
        { id: 'prize', title: 'Prize sponsors', size: 'large', order: 0 },
        { id: 'supporter', title: 'Our proud sponsors and supporters', size: 'normal', order: 1 }
      ];
      out[key] = SHAPES.sponsorTiers(tiers, key, ctx);
      return;
    }
    out[key] = SHAPES[key](data[key], key, ctx);
  });

  COLLECTIONS.forEach((key) => {
    const taken = new Set();
    const rows = Array.isArray(data[key]) ? data[key] : [];
    out[key] = rows
      .map((row, i) => {
        const item = SHAPES[key](row, `${key}[${i}]`, ctx) || {};
        item.id = uniqueId(item.id || slugify(item.title || item.name, `${key}-${i + 1}`), taken, `${key}-${i + 1}`);
        taken.add(item.id);
        if (typeof item.order !== 'number') item.order = i;
        return item;
      })
      .sort((a, b) => a.order - b.order)
      .map((item, i) => { item.order = i; return item; });   // dense, gap-free

    if (!taken.size && rows.length) ctx.fail(key, 'Nothing in this list could be read.');
  });

  /* Exactly one featured event: the first wins, the rest are cleared. */
  let featureSeen = false;
  out.events.forEach((event) => {
    if (event.featured && !featureSeen) featureSeen = true;
    else event.featured = false;
  });

  /* Sponsors must point at a tier that exists. */
  const tierIds = new Set(out.sponsorTiers.map((t) => t.id));
  const fallbackTier = out.sponsorTiers[out.sponsorTiers.length - 1].id;
  out.sponsors.forEach((s) => { if (!tierIds.has(s.tier)) s.tier = fallbackTier; });

  /* Unknown keys ride along untouched, so a newer admin can add a field
     without an older server silently deleting it. */
  Object.keys(data).forEach((key) => {
    if (!SECTIONS.includes(key) && !COLLECTIONS.includes(key)) out[key] = data[key];
  });

  return {
    schemaVersion: SCHEMA_VERSION,
    updatedAt: typeof input.updatedAt === 'string' ? input.updatedAt : new Date().toISOString(),
    data: out,
    warnings: ctx.errors
  };
}

/** What the public site and the snapshot are allowed to see. */
function publicProjection(content) {
  const data = (content && content.data) || {};
  const out = {};
  SECTIONS.forEach((key) => { if (data[key] !== undefined) out[key] = data[key]; });
  COLLECTIONS.forEach((key) => {
    out[key] = (data[key] || [])
      .filter((item) => item.active !== false)
      .slice()
      .sort((a, b) => (a.order || 0) - (b.order || 0));
  });
  return out;
}

module.exports = {
  SCHEMA_VERSION, SECTIONS, COLLECTIONS, ICONS, CAUSE_STATUS, GALLERY_TYPES,
  validate, normalise, publicProjection, slugify, uniqueId, cleanString, SLUG
};
