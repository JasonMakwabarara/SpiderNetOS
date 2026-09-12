/* Charity Sports — shared helpers.
   Everything hangs off window.CS. Classic script, no modules, no build step. */
(function () {
  'use strict';

  var CS = (window.CS = window.CS || {});

  /* ------------------------------------------------------------ DOM building
     Content always goes in as text, never as markup, so nothing typed into the
     admin panel or the data file can inject HTML. */
  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (key) {
        var value = attrs[key];
        if (value === null || value === undefined || value === false) return;
        if (key === 'text') { node.textContent = String(value); return; }
        if (key === 'class') { node.className = String(value); return; }
        if (key === 'dataset') {
          Object.keys(value).forEach(function (d) { node.dataset[d] = String(value[d]); });
          return;
        }
        if (key === 'on') {
          Object.keys(value).forEach(function (evt) { node.addEventListener(evt, value[evt]); });
          return;
        }
        if (value === true) { node.setAttribute(key, ''); return; }
        node.setAttribute(key, String(value));
      });
    }
    append(node, children);
    return node;
  }

  function append(parent, children) {
    if (children === null || children === undefined) return parent;
    if (Array.isArray(children)) {
      children.forEach(function (child) { append(parent, child); });
      return parent;
    }
    parent.appendChild(children instanceof Node ? children : document.createTextNode(String(children)));
    return parent;
  }

  function clear(node) {
    while (node && node.firstChild) node.removeChild(node.firstChild);
    return node;
  }

  /** An <svg><use href="#i-name"> icon from the inline sprite. */
  function icon(name, className) {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', className || 'icon');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', '#i-' + name);
    svg.appendChild(use);
    return svg;
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /* ----------------------------------------------------------------- values */
  function get(obj, path, fallback) {
    var parts = String(path).split('.');
    var cur = obj;
    for (var i = 0; i < parts.length; i++) {
      if (cur === null || cur === undefined) return fallback;
      cur = cur[parts[i]];
    }
    return cur === undefined || cur === null ? fallback : cur;
  }

  var intFormatter = null;
  function fmtInt(n) {
    if (typeof n !== 'number' || !isFinite(n)) return '0';
    try {
      if (!intFormatter) intFormatter = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });
      return intFormatter.format(Math.round(n));
    } catch (err) {
      return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
  }

  function fmtUsd(n) {
    if (typeof n !== 'number' || !isFinite(n)) return null;
    return 'US$' + fmtInt(n);
  }

  /* ------------------------------------------------------------------- time
     Harare is CAT, UTC+2, and has no daylight saving. Dates in the content are
     plain "YYYY-MM-DD" plus optional "HH:MM", so every instant is built from
     UTC minus two hours. No timezone library, and no dependence on where the
     visitor's computer thinks it is. */
  var HARARE_OFFSET_MIN = 120;

  function parseDateParts(dateStr) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(dateStr || ''));
    if (!m) return null;
    return { y: +m[1], m: +m[2], d: +m[3] };
  }

  function parseTimeParts(timeStr) {
    var m = /^(\d{1,2}):(\d{2})$/.exec(String(timeStr || ''));
    if (!m) return null;
    var h = +m[1], min = +m[2];
    if (h > 23 || min > 59) return null;
    return { h: h, min: min };
  }

  /** The instant a Harare wall-clock date/time happens, as a Date. */
  function harareInstant(dateStr, timeStr) {
    var d = parseDateParts(dateStr);
    if (!d) return null;
    var t = parseTimeParts(timeStr) || { h: 0, min: 0 };
    return new Date(Date.UTC(d.y, d.m - 1, d.d, t.h, t.min) - HARARE_OFFSET_MIN * 60000);
  }

  /** The last instant of a Harare day, used to decide "is today still on". */
  function endOfHarareDay(dateStr) {
    var d = parseDateParts(dateStr);
    if (!d) return null;
    return new Date(Date.UTC(d.y, d.m - 1, d.d, 23, 59, 59) - HARARE_OFFSET_MIN * 60000);
  }

  function fmtDate(dateStr, opts) {
    var d = parseDateParts(dateStr);
    if (!d) return '';
    var utc = new Date(Date.UTC(d.y, d.m - 1, d.d));
    var options = Object.assign(
      { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' },
      opts || {}
    );
    try {
      return new Intl.DateTimeFormat('en-GB', options).format(utc);
    } catch (err) {
      return dateStr;
    }
  }

  function fmtTime(timeStr) {
    var t = parseTimeParts(timeStr);
    if (!t) return '';
    var h = t.h % 12 || 12;
    var suffix = t.h < 12 ? 'AM' : 'PM';
    return h + (t.min ? ':' + String(t.min).padStart(2, '0') : '') + ' ' + suffix;
  }

  function fmtTimeRange(start, end) {
    var a = fmtTime(start), b = fmtTime(end);
    if (a && b) return a + ' – ' + b;
    return a || b || '';
  }

  /** Current time. ?now=2026-09-27 or ?now=2026-09-27T10:00 overrides it, which
      is how the verification script tests past/upcoming without waiting. */
  function now() {
    var override = param('now');
    if (override) {
      var forced = new Date(/T/.test(override) ? override + ':00Z' : override + 'T12:00:00Z');
      if (!isNaN(forced.getTime())) return forced;
    }
    return new Date();
  }

  function param(name) {
    try {
      return new URLSearchParams(window.location.search).get(name);
    } catch (err) {
      return null;
    }
  }

  /* ------------------------------------------------------------------ links
     Content stores short keywords rather than full URLs, so the WhatsApp number
     or the donate link only ever has to change in one place. */
  function buildLinks(data) {
    var contact = get(data, 'contact', {}) || {};
    var digits = String(contact.whatsapp || '').replace(/\D/g, '');
    var messages = contact.whatsappMessages || {};
    return {
      donate: get(data, 'donate.url', '') || '',
      whatsappNumber: contact.whatsapp || '',
      whatsapp: function (key) {
        if (!digits) return '';
        var text = messages[key] || messages.default || '';
        return 'https://wa.me/' + digits + (text ? '?text=' + encodeURIComponent(text) : '');
      },
      tel: digits ? 'tel:+' + digits : '',
      email: contact.email
        ? 'mailto:' + contact.email + (contact.emailSubject ? '?subject=' + encodeURIComponent(contact.emailSubject) : '')
        : '',
      emailAddress: contact.email || ''
    };
  }

  /** Turn a stored href into a real one: "donate", "whatsapp:sponsor",
      "email", "tel", "#anchor" or a plain URL all work. */
  function resolveHref(href, links) {
    if (!href) return null;
    var value = String(href);
    if (value.charAt(0) === '#' || /^https?:\/\//i.test(value) || /^(mailto|tel):/i.test(value)) return value;
    if (value === 'donate') return links.donate || null;
    if (value === 'email') return links.email || null;
    if (value === 'tel') return links.tel || null;
    if (value.indexOf('whatsapp') === 0) {
      var key = value.indexOf(':') > -1 ? value.split(':')[1] : 'default';
      return links.whatsapp(key) || null;
    }
    return value;
  }

  function isExternal(href) {
    return /^https?:\/\//i.test(String(href || ''));
  }

  /** An <a> that opens external links safely and internal anchors normally. */
  function link(href, attrs, children) {
    var config = Object.assign({ href: href }, attrs || {});
    if (isExternal(href)) {
      config.target = '_blank';
      config.rel = 'noopener noreferrer';
    }
    return el('a', config, children);
  }

  /** A <picture> with a WebP source and an explicitly sized fallback <img>. */
  function picture(image, opts) {
    if (!image || !image.src) return null;
    var options = opts || {};
    var node = el('picture');
    if (image.webp) {
      node.appendChild(el('source', {
        type: 'image/webp',
        srcset: image.webpSmall ? image.webpSmall + ' 800w, ' + image.webp + ' ' + (image.width || 1280) + 'w' : image.webp,
        sizes: options.sizes || null
      }));
    }
    node.appendChild(el('img', {
      src: image.src,
      srcset: image.srcSmall ? image.srcSmall + ' 800w, ' + image.src + ' ' + (image.width || 1280) + 'w' : null,
      sizes: options.sizes || null,
      width: image.width || null,
      height: image.height || null,
      alt: image.alt || '',
      loading: options.eager ? 'eager' : 'lazy',
      decoding: 'async',
      fetchpriority: options.eager ? 'high' : null
    }));
    return node;
  }

  function reducedMotion() {
    try {
      return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (err) {
      return false;
    }
  }

  function byOrder(a, b) {
    return (a.order || 0) - (b.order || 0);
  }

  function activeOnly(list) {
    return (Array.isArray(list) ? list : []).filter(function (item) {
      return item && item.active !== false;
    }).sort(byOrder);
  }

  CS.el = el;
  CS.append = append;
  CS.clear = clear;
  CS.icon = icon;
  CS.escapeHtml = escapeHtml;
  CS.get = get;
  CS.fmtInt = fmtInt;
  CS.fmtUsd = fmtUsd;
  CS.HARARE_OFFSET_MIN = HARARE_OFFSET_MIN;
  CS.harareInstant = harareInstant;
  CS.endOfHarareDay = endOfHarareDay;
  CS.parseDateParts = parseDateParts;
  CS.fmtDate = fmtDate;
  CS.fmtTime = fmtTime;
  CS.fmtTimeRange = fmtTimeRange;
  CS.now = now;
  CS.param = param;
  CS.buildLinks = buildLinks;
  CS.resolveHref = resolveHref;
  CS.isExternal = isExternal;
  CS.link = link;
  CS.picture = picture;
  CS.reducedMotion = reducedMotion;
  CS.byOrder = byOrder;
  CS.activeOnly = activeOnly;
})();
