'use strict';
/* Turns the live content into the two files the static site reads:
   data/site-data.js and the JSON-LD block inside index.html.

   Output is deterministic: the same content always produces byte-identical
   files, so publishing twice with no changes makes no commit noise. */
const schema = require('./schema');

const HEADER = `/* ---------------------------------------------------------------------------
 * Charity Sports — website content.
 *
 * GENERATED FILE. The admin panel rewrites this every time someone presses
 * Publish, so hand edits here are lost on the next publish. Edit it by hand
 * only if you are not using the admin panel.
 *
 * Quick rules if you do edit it
 *   - Keep the quotes and the commas. A missing comma stops the whole page
 *     rendering. If the page goes blank, open the browser console (F12): it
 *     names the line. GitHub's History tab will undo it.
 *   - Dates look like "2026-09-17" (year-month-day).
 *   - Times look like "19:00" (24-hour, Harare time). null means "to be
 *     confirmed".
 *   - Money is a plain number: 3200, not "US$3,200".
 *   - null means "we do not have this yet" and hides that bit of the page.
 *   - "active": false hides an item without deleting it.
 * ------------------------------------------------------------------------- */

`;

/** Nothing in the data can close the surrounding script element. */
function guard(text) {
  return text.replace(/<\/(script)/gi, '<\\/$1');
}

function snapshot(content) {
  const payload = {
    schemaVersion: content.schemaVersion,
    updatedAt: content.updatedAt,
    ...schema.publicProjection(content)
  };
  const json = JSON.stringify(payload, null, 2);
  return HEADER + 'window.CHARITY_DATA = ' + guard(json) + ';\n';
}

/* ------------------------------------------------------------------ JSON-LD */

const START = '<!-- JSONLD:START -->';
const END = '<!-- JSONLD:END -->';

function absolute(base, relative) {
  if (!relative) return undefined;
  try { return new URL(relative, base).toString(); } catch (err) { return undefined; }
}

function jsonLd(content, siteUrl) {
  const data = schema.publicProjection(content);
  const base = (siteUrl || (data.org && data.org.siteUrl) || '').replace(/\/*$/, '/');
  const org = data.org || {};
  const contact = data.contact || {};

  const graph = [{
    '@type': 'NGO',
    '@id': base + '#org',
    name: org.name,
    alternateName: org.trustName || undefined,
    url: base || undefined,
    email: contact.email || undefined,
    telephone: contact.whatsapp ? '+' + String(contact.whatsapp).replace(/\D/g, '') : undefined,
    description: org.mission || undefined,
    slogan: org.tagline || undefined,
    address: {
      '@type': 'PostalAddress',
      addressLocality: (org.location || '').split(',')[0].trim() || undefined,
      addressCountry: 'ZW'
    }
  }];

  (data.events || []).forEach((event) => {
    const sessions = Array.isArray(event.sessions) && event.sessions.length
      ? event.sessions
      : (event.startDate ? [{ date: event.startDate, startTime: event.startTime, endTime: event.endTime }] : []);
    sessions.forEach((session, i) => {
      if (!session.date) return;
      const start = session.startTime || event.startTime;
      const end = session.endTime || event.endTime;
      graph.push({
        '@type': 'SportsEvent',
        '@id': `${base}#event-${event.id}-${i}`,
        name: event.title + (session.label ? ' — ' + session.label : ''),
        description: event.summary || undefined,
        /* Harare is UTC+2 all year, so the offset is safe to write out. */
        startDate: `${session.date}T${start || '00:00'}:00+02:00`,
        endDate: end ? `${session.date}T${end}:00+02:00` : undefined,
        eventStatus: 'https://schema.org/EventScheduled',
        eventAttendanceMode: 'https://schema.org/OfflineEventAttendanceMode',
        image: absolute(base, event.poster && event.poster.src),
        location: event.venue && event.venue.name ? {
          '@type': 'Place',
          name: event.venue.name,
          address: event.venue.address || undefined
        } : undefined,
        organizer: { '@id': base + '#org' }
      });
    });
  });

  return JSON.stringify({ '@context': 'https://schema.org', '@graph': graph });
}

/** Replace a marked region of index.html, leaving the rest of the file alone. */
function replaceRegion(html, startMarker, endMarker, body) {
  const startIndex = html.indexOf(startMarker);
  const endIndex = html.indexOf(endMarker);
  if (startIndex === -1 || endIndex === -1 || endIndex < startIndex) {
    return { html, replaced: false };
  }
  const next = html.slice(0, startIndex) + `${startMarker}\n${body}\n` + html.slice(endIndex);
  return { html: next, replaced: true };
}

function replaceJsonLd(html, blockJson) {
  return replaceRegion(html, START, END,
    `<script type="application/ld+json">\n${guard(blockJson)}\n</script>`);
}

/* --------------------------------------------------------------------- meta */

const META_START = '<!-- META:START -->';
const META_END = '<!-- META:END -->';

function escapeAttr(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
    .replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * The head tags that depend on where the site is hosted, generated from the
 * content rather than typed into the HTML. Before this existed, moving to a
 * custom domain meant hand-editing six tags in index.html, which contradicts
 * the promise that all content lives in one file.
 */
function metaTags(content, siteUrl) {
  const data = schema.publicProjection(content);
  const org = data.org || {};
  const hero = data.hero || {};
  const base = (siteUrl || org.siteUrl || '').replace(/\/*$/, '/');

  const title = org.name ? `${org.name} — ${hero.headline || 'sport that changes lives'}` : (hero.headline || '');
  const description = hero.subheadline || org.mission || '';
  const image = absolute(base, 'assets/img/og-image.jpg');
  const heroImage = hero.image || {};

  const lines = [];
  const tag = (html) => lines.push(html);

  if (base) tag(`<link rel="canonical" href="${escapeAttr(base)}">`);

  tag('<meta property="og:type" content="website">');
  if (org.name) tag(`<meta property="og:site_name" content="${escapeAttr(org.name)}">`);
  if (title) tag(`<meta property="og:title" content="${escapeAttr(title)}">`);
  if (description) tag(`<meta property="og:description" content="${escapeAttr(description)}">`);
  if (base) tag(`<meta property="og:url" content="${escapeAttr(base)}">`);
  if (image) {
    tag(`<meta property="og:image" content="${escapeAttr(image)}">`);
    tag('<meta property="og:image:width" content="1200">');
    tag('<meta property="og:image:height" content="630">');
    if (heroImage.alt) tag(`<meta property="og:image:alt" content="${escapeAttr(heroImage.alt)}">`);
  }
  tag('<meta property="og:locale" content="en_ZW">');

  tag('<meta name="twitter:card" content="summary_large_image">');
  if (title) tag(`<meta name="twitter:title" content="${escapeAttr(title)}">`);
  if (description) tag(`<meta name="twitter:description" content="${escapeAttr(description)}">`);
  if (image) tag(`<meta name="twitter:image" content="${escapeAttr(image)}">`);

  /* The hero picture is built by JavaScript, so the browser's preload scanner
     never sees it and discovers it only once the scripts have run. On a slow
     connection that delays the largest paint badly. This tells it up front. */
  if (heroImage.src) {
    const webp = heroImage.webp;
    const srcset = webp
      ? (heroImage.webpSmall ? `${heroImage.webpSmall} 800w, ${webp} ${heroImage.width || 1280}w` : webp)
      : (heroImage.srcSmall ? `${heroImage.srcSmall} 800w, ${heroImage.src} ${heroImage.width || 1280}w` : heroImage.src);
    tag(
      `<link rel="preload" as="image" fetchpriority="high" href="${escapeAttr(webp || heroImage.src)}"` +
      ` imagesrcset="${escapeAttr(srcset)}" imagesizes="(min-width: 900px) 46vw, 100vw"` +
      (webp ? ' type="image/webp"' : '') + '>'
    );
  }

  return lines.join('\n');
}

function replaceMeta(html, content, siteUrl) {
  return replaceRegion(html, META_START, META_END, metaTags(content, siteUrl));
}

/* ------------------------------------------------------------------ sitemap */

/** One page, so this is short, but it keeps the address in one place. */
function sitemap(content, siteUrl) {
  const data = schema.publicProjection(content);
  const base = (siteUrl || (data.org && data.org.siteUrl) || '').replace(/\/*$/, '/');
  const updated = (content.updatedAt || new Date().toISOString()).slice(0, 10);
  return [
    '<?xml version="1.0" encoding="UTF-8"?>',
    '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
    '  <url>',
    `    <loc>${escapeAttr(base)}</loc>`,
    `    <lastmod>${updated}</lastmod>`,
    '    <changefreq>weekly</changefreq>',
    '    <priority>1.0</priority>',
    '  </url>',
    '</urlset>',
    ''
  ].join('\n');
}

function robots(content, siteUrl) {
  const data = schema.publicProjection(content);
  const base = (siteUrl || (data.org && data.org.siteUrl) || '').replace(/\/*$/, '/');
  return [
    'User-agent: *',
    'Allow: /',
    'Disallow: /admin/',
    'Disallow: /server/',
    'Disallow: /content/',
    '',
    base ? `Sitemap: ${base}sitemap.xml` : '',
    ''
  ].filter((line, i, all) => !(line === '' && all[i - 1] === '')).join('\n');
}

module.exports = {
  snapshot, jsonLd, replaceJsonLd, replaceMeta, metaTags, sitemap, robots,
  HEADER, START, END, META_START, META_END, guard
};
