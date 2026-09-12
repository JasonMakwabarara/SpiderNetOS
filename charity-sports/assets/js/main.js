/* Boots the page: binds the simple text, renders each section, then offers the
   optional live refresh. Runs last, after every other module has registered. */
(function () {
  'use strict';
  var CS = (window.CS = window.CS || {});

  /* ------------------------------------------------------------ data guard */
  function failHard(reason) {
    var main = document.getElementById('main');
    var banner = CS.el('div', { class: 'data-error' }, [
      CS.el('p', null, [CS.el('b', { text: 'The website content could not be loaded.' })]),
      CS.el('p', null, ['Check ', CS.el('code', { text: 'data/site-data.js' }),
        ' — a missing comma or quote will do this. The browser console (F12) names the line.']),
      CS.el('p', { text: 'Details: ' + reason }),
      CS.el('p', null, ['You can still reach us on ',
        CS.el('a', { href: 'https://wa.me/263776437764', text: 'WhatsApp' }), ' or at ',
        CS.el('a', { href: 'mailto:charitysport@yahoo.com', text: 'charitysport@yahoo.com' }), '.'])
    ]);
    if (main && main.parentNode) main.parentNode.insertBefore(banner, main);
    console.error('[charity-sports] ' + reason);
  }

  /* --------------------------------------------------------- simple fields */
  function bindScalars(data, links) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-bind]'), function (node) {
      var value = CS.get(data, node.dataset.bind, null);
      if (value === null || typeof value === 'object') return;
      node.textContent = String(value);
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-bind-href]'), function (node) {
      var value = CS.get(data, node.dataset.bindHref, null);
      if (!value) return;
      node.setAttribute('href', String(value));
    });

    var primary = document.getElementById('heroPrimary');
    var secondary = document.getElementById('heroSecondary');
    [[primary, 'hero.primaryCta'], [secondary, 'hero.secondaryCta']].forEach(function (pair) {
      var node = pair[0];
      var cta = CS.get(data, pair[1], null);
      if (!node || !cta) return;
      node.textContent = cta.label || node.textContent;
      var href = CS.resolveHref(cta.href, links);
      if (!href) return;
      node.setAttribute('href', href);
      if (CS.isExternal(href)) { node.target = '_blank'; node.rel = 'noopener noreferrer'; }
      else { node.removeAttribute('target'); node.removeAttribute('rel'); }
    });

    var title = CS.get(data, 'org.name', null);
    if (title && document.title.indexOf(title) === -1) {
      document.title = title + ' — ' + CS.get(data, 'hero.headline', 'sport that changes lives');
    }
  }

  /* ------------------------------------------------------------- sections */
  function renderHero(data) {
    var figure = document.getElementById('heroFigure');
    var image = CS.get(data, 'hero.image', null);
    if (!figure) return;
    CS.clear(figure);
    var pic = CS.picture(image, { eager: true, sizes: '(min-width: 900px) 46vw, 100vw' });
    if (pic) figure.appendChild(pic);
    figure.hidden = !pic;
  }

  function renderAbout(data) {
    var copy = document.getElementById('aboutCopy');
    var how = document.getElementById('howItWorks');
    if (copy) {
      CS.clear(copy);
      (CS.get(data, 'about.paragraphs', []) || []).forEach(function (text) {
        copy.appendChild(CS.el('p', { text: text }));
      });
    }
    if (how) {
      CS.clear(how);
      (CS.get(data, 'about.howItWorks', []) || []).forEach(function (step) {
        how.appendChild(CS.el('li', null, [
          CS.el('span', { class: 'how-icon' }, [CS.icon(step.icon || 'target')]),
          CS.el('div', null, [
            CS.el('h3', { text: step.title || '' }),
            CS.el('p', { text: step.text || '' })
          ])
        ]));
      });
    }
  }

  /* Accountability. The whole section stays hidden until the charity has
     supplied at least one fact or statement, so the site never displays a
     half-filled "to be confirmed" panel to somebody deciding whether to give. */
  function renderAccountability(data) {
    var section = document.getElementById('accountability');
    var factList = document.getElementById('trustFacts');
    var statementList = document.getElementById('trustStatements');
    var navLink = document.getElementById('trustNavLink');
    if (!section || !factList || !statementList) return;

    var block = CS.get(data, 'accountability', {}) || {};

    var facts = [];
    if (block.registrationNumber) {
      facts.push({ label: block.registrationLabel || 'Registration number', value: block.registrationNumber });
    }
    if (block.bankedWith) facts.push({ label: 'Funds held', value: block.bankedWith });
    if (block.financeContactName) {
      facts.push({
        label: block.financeContactRole || 'Questions about money',
        value: block.financeContactName,
        email: block.financeContactEmail || null
      });
    } else if (block.financeContactEmail) {
      facts.push({ label: 'Questions about money', value: block.financeContactEmail, email: block.financeContactEmail });
    }
    if (block.receiptsPolicy) facts.push({ label: 'Receipts', value: block.receiptsPolicy });

    var statements = (block.statements || []).filter(Boolean);

    CS.clear(factList);
    facts.forEach(function (fact) {
      var value = fact.email
        ? CS.el('p', { class: 'trust-value' }, [CS.link('mailto:' + fact.email, null, fact.value)])
        : CS.el('p', { class: 'trust-value', text: fact.value });
      factList.appendChild(CS.el('li', null, [
        CS.el('span', { class: 'trust-label', text: fact.label }),
        value
      ]));
    });

    CS.clear(statementList);
    statements.forEach(function (line) {
      statementList.appendChild(CS.el('li', { text: line }));
    });

    var hasSomething = facts.length > 0 || statements.length > 0;
    section.hidden = !hasSomething;
    if (navLink) navLink.hidden = !hasSomething;
  }

  function renderSupport(data, links) {
    var grid = document.getElementById('supportGrid');
    if (!grid) return;
    CS.clear(grid);
    CS.activeOnly(CS.get(data, 'waysToSupport', [])).forEach(function (way) {
      var card = CS.el('article', { class: 'support-card', dataset: { supportId: way.id || '' } }, [
        CS.el('span', { class: 'support-icon' }, [CS.icon(way.icon || 'heart')]),
        CS.el('h3', { text: way.title || '' }),
        way.price ? CS.el('p', { class: 'support-price', text: way.price }) : null,
        CS.el('p', { text: way.text || '' })
      ]);
      var href = way.cta && CS.resolveHref(way.cta.href, links);
      if (href) card.appendChild(CS.link(href, { class: 'btn btn-ghost btn-sm' }, way.cta.label || 'Find out more'));
      grid.appendChild(card);
    });
  }

  function contactItems(data, links) {
    var rows = [];
    if (links.whatsapp('default')) {
      rows.push(CS.el('li', null, [
        CS.icon('whatsapp'),
        CS.link(links.whatsapp('default'), null, 'WhatsApp ' + (links.whatsappNumber || ''))
      ]));
    }
    if (links.email) {
      rows.push(CS.el('li', null, [CS.icon('mail'), CS.link(links.email, null, links.emailAddress)]));
    }
    (CS.get(data, 'contact.social', []) || []).forEach(function (social) {
      if (!social || !social.url) return;
      rows.push(CS.el('li', null, [CS.icon('arrow'), CS.link(social.url, null, social.label || social.url)]));
    });
    return rows;
  }

  function renderDonate(data, links) {
    var where = document.getElementById('whereMoneyGoes');
    if (where) {
      CS.clear(where);
      (CS.get(data, 'donate.whereMoneyGoes', []) || []).forEach(function (line) {
        where.appendChild(CS.el('li', { text: line }));
      });
    }

    var note = document.getElementById('paymentNote');
    if (note) {
      var text = CS.get(data, 'donate.paymentNote', '');
      CS.clear(note);
      if (text) note.appendChild(CS.el('span', { text: text }));
      note.hidden = !text;
    }

    var alt = document.getElementById('donateContact');
    if (alt) {
      CS.clear(alt);
      contactItems(data, links).forEach(function (row) { alt.appendChild(row); });
    }
  }

  function renderFooter(data, links) {
    var list = document.getElementById('footerContact');
    if (list) {
      CS.clear(list);
      contactItems(data, links).forEach(function (row) { list.appendChild(row); });
    }
    var year = document.getElementById('year');
    if (year) year.textContent = String(new Date().getFullYear());
  }

  /* ---------------------------------------------------------------- render */
  function renderAll(data, only) {
    var links = CS.buildLinks(data);
    var wants = function (key) { return !only || only.indexOf(key) > -1; };

    bindScalars(data, links);
    if (wants('hero') || wants('org')) renderHero(data);
    if (wants('about')) renderAbout(data);
    if (wants('accountability')) renderAccountability(data);
    if (wants('impact')) safely('counter', function () { CS.counter.render(data); });
    if (wants('causes')) safely('causes', function () { CS.causes.render(data); });
    if (wants('events') || wants('contact')) safely('events', function () { CS.events.render(data); });
    if (wants('waysToSupport') || wants('contact')) renderSupport(data, links);
    if (wants('sponsors') || wants('sponsorTiers') || wants('sponsorsMeta')) {
      safely('sponsors', function () { CS.sponsors.render(data); });
    }
    if (wants('gallery')) safely('gallery', function () { CS.gallery.render(data); });
    if (wants('donate') || wants('contact')) renderDonate(data, links);
    if (wants('contact') || wants('org')) renderFooter(data, links);
    if (wants('share')) safely('share', function () { CS.engage.render(data); });
  }

  /** One broken section must never take the rest of the page down with it. */
  function safely(name, fn) {
    try { fn(); } catch (err) { console.error('[charity-sports] ' + name + ' failed:', err); }
  }

  /* ------------------------------------------------------------------ boot */
  function boot() {
    var data = window.CHARITY_DATA;
    if (!data || typeof data !== 'object') {
      failHard('window.CHARITY_DATA is missing. data/site-data.js did not load or did not parse.');
      return;
    }

    var links = CS.buildLinks(data);
    safely('nav', function () { CS.nav.init(); });
    bindScalars(data, links);
    renderHero(data);
    renderAbout(data);
    renderAccountability(data);
    safely('causes', function () { CS.causes.init(data); });
    safely('events', function () { CS.events.init(data); });
    renderSupport(data, links);
    safely('sponsors', function () { CS.sponsors.init(data); });
    safely('gallery', function () { CS.gallery.init(data); });
    renderDonate(data, links);
    renderFooter(data, links);
    safely('counter', function () { CS.counter.render(data); CS.counter.init(data); });
    safely('engage', function () { CS.engage.init(data); });

    document.documentElement.dataset.ready = '1';

    /* A repeat visit on a dropped connection still gets the page. Only over
       http(s): there is no worker on a file:// path, and registering inside
       the admin panel would risk caching a signed-in response. */
    if ('serviceWorker' in navigator &&
        /^https?:$/.test(window.location.protocol) &&
        window.location.pathname.indexOf('/admin') === -1) {
      window.addEventListener('load', function () {
        /* Registered from the site root so its scope is the whole site.
           A worker under assets/ could only ever control assets/. */
        navigator.serviceWorker.register('sw.js').catch(function () {
          /* Unsupported, blocked, or served from a path it cannot control.
             The site works exactly as before without it. */
        });
      });
    }

    safely('live', function () {
      CS.live.start(data, function (merged, changed) {
        window.CHARITY_DATA = merged;
        var apply = function () {
          renderAll(merged, changed);
          document.documentElement.dataset.live = '1';
        };
        /* Never yank the gallery out from under an open lightbox. */
        if (changed.indexOf('gallery') > -1 && CS.gallery.isOpen()) CS.gallery.onClose = apply;
        else apply();
      });
    });
  }

  CS.renderAll = renderAll;

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
