/* Sponsors, grouped into tiers. A sponsor with no logo file renders as a
   typographic tile, which is the honest fallback until a real logo arrives. */
(function () {
  'use strict';
  var CS = (window.CS = window.CS || {});

  function tile(sponsor, links) {
    var href = sponsor.link ? CS.resolveHref(sponsor.link, links) : null;
    var attrs = { class: 'sponsor-tile', dataset: { sponsorId: sponsor.id || '' } };
    var node = href ? CS.link(href, attrs) : CS.el('div', attrs);
    if (href) node.rel = 'noopener noreferrer sponsored';

    if (sponsor.logo) {
      var box = CS.el('div', { class: 'sponsor-logo' });
      var pic = CS.el('picture');
      if (sponsor.logoWebp) pic.appendChild(CS.el('source', { type: 'image/webp', srcset: sponsor.logoWebp }));
      pic.appendChild(CS.el('img', {
        src: sponsor.logo,
        alt: sponsor.alt || sponsor.name || '',
        loading: 'lazy',
        decoding: 'async'
      }));
      box.appendChild(pic);
      node.appendChild(box);
      /* The name still appears beneath the logo, exactly as it does on the
         printed flyer, so a logo that fails to load never loses the sponsor. */
    }

    node.appendChild(CS.el('span', { class: 'sponsor-name', text: sponsor.name || '' }));
    if (sponsor.tagline) node.appendChild(CS.el('span', { class: 'sponsor-tagline', text: sponsor.tagline }));
    return node;
  }

  function ctaTile(meta, links) {
    if (!meta || !meta.ctaTile) return null;
    var cta = meta.ctaTile;
    var href = CS.resolveHref(cta.href, links);
    var attrs = { class: 'sponsor-tile is-cta' };
    var node = href ? CS.link(href, attrs) : CS.el('div', attrs);
    node.appendChild(CS.el('span', { class: 'sponsor-name', text: cta.title || 'Sponsor slot available' }));
    if (cta.subtitle) node.appendChild(CS.el('span', { class: 'sponsor-tagline', text: cta.subtitle }));
    node.appendChild(CS.el('span', { class: 'sponsor-cta-label' }, [cta.label || 'Become a sponsor', CS.icon('arrow')]));
    return node;
  }

  CS.sponsors = {
    render: function (data) {
      var root = document.getElementById('sponsorTiers');
      if (!root) return;

      var links = CS.buildLinks(data);
      var sponsors = CS.activeOnly(CS.get(data, 'sponsors', []));
      var tiers = (CS.get(data, 'sponsorTiers', []) || []).slice().sort(CS.byOrder);
      var meta = CS.get(data, 'sponsorsMeta', {});

      CS.clear(root);

      tiers.forEach(function (tier, tierIndex) {
        var members = sponsors.filter(function (s) { return s.tier === tier.id; });
        var isLast = tierIndex === tiers.length - 1;
        if (!members.length && !isLast) return;

        var section = CS.el('section', {
          class: 'tier' + (tier.size === 'large' ? ' tier-large' : ''),
          dataset: { tier: tier.id }
        });
        var head = CS.el('div', { class: 'tier-head' }, [CS.el('h3', { text: tier.title || '' })]);
        if (tier.subtitle) head.appendChild(CS.el('p', { text: tier.subtitle }));
        section.appendChild(head);

        var grid = CS.el('div', { class: 'sponsor-grid' }, members.map(function (s) { return tile(s, links); }));
        /* The open-slot invitation sits at the end of the last tier. */
        if (isLast) {
          var cta = ctaTile(meta, links);
          if (cta) grid.appendChild(cta);
        }
        section.appendChild(grid);
        root.appendChild(section);
      });
    },
    init: function (data) { CS.sponsors.render(data); }
  };
})();
