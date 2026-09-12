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

/** Replace the marked JSON-LD region of index.html, leaving the rest alone. */
function replaceJsonLd(html, blockJson) {
  const startIndex = html.indexOf(START);
  const endIndex = html.indexOf(END);
  if (startIndex === -1 || endIndex === -1 || endIndex < startIndex) {
    return { html, replaced: false };
  }
  const block = `${START}\n<script type="application/ld+json">\n${guard(blockJson)}\n</script>\n`;
  const next = html.slice(0, startIndex) + block + html.slice(endIndex);
  return { html: next, replaced: true };
}

module.exports = { snapshot, jsonLd, replaceJsonLd, HEADER, START, END, guard };
