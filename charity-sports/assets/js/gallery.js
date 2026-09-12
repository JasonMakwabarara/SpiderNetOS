/* Gallery grid, type filters and an accessible <dialog> lightbox.
   showModal() gives focus trapping and Esc handling natively. */
(function () {
  'use strict';
  var CS = (window.CS = window.CS || {});

  var items = [];        // currently visible, in display order
  var index = 0;
  var lastTrigger = null;
  var bound = false;

  var LABELS = { photo: 'Photos', poster: 'Posters', video: 'Videos' };
  var SINGULAR = { photo: 'Photo', poster: 'Poster', video: 'Video' };

  function thumbFor(item) {
    return {
      src: item.thumb || item.src || item.poster,
      webp: item.thumbWebp || null,
      width: 480,
      height: item.width && item.height ? Math.round((480 * item.height) / item.width) : null,
      alt: item.alt || item.title || ''
    };
  }

  function gridItem(item, position) {
    var figure = CS.el('li', { class: 'gallery-item', dataset: { type: item.type || 'photo', id: item.id || '' } });
    var button = CS.el('button', {
      class: 'gallery-btn',
      type: 'button',
      dataset: { index: String(position) }
    });

    var pic = CS.picture(thumbFor(item), { sizes: '(min-width: 900px) 25vw, 50vw' });
    if (pic) button.appendChild(pic);
    button.appendChild(CS.el('span', { class: 'gallery-type', text: SINGULAR[item.type] || 'Photo' }));
    if (item.type === 'video') {
      button.appendChild(CS.el('span', { class: 'gallery-play' }, [CS.el('span', null, [CS.icon('play')])]));
    }
    button.appendChild(CS.el('span', { class: 'visually-hidden', text: 'Open: ' + (item.title || item.alt || 'gallery item') }));

    figure.appendChild(button);
    figure.appendChild(CS.el('figcaption', { class: 'gallery-caption' }, [
      CS.el('b', { text: item.title || '' }),
      item.caption ? CS.el('span', { text: item.caption }) : null
    ]));
    return figure;
  }

  /* ------------------------------------------------------------- lightbox */

  function media(item) {
    if (item.type === 'video' && item.provider === 'youtube' && item.youtubeId) {
      return CS.el('iframe', {
        src: 'https://www.youtube-nocookie.com/embed/' + item.youtubeId + '?autoplay=1&rel=0',
        title: item.title || 'Video',
        allow: 'accelerometer; autoplay; encrypted-media; picture-in-picture',
        allowfullscreen: true,
        loading: 'lazy'
      });
    }
    if (item.type === 'video' && item.videoSrc) {
      return CS.el('video', { src: item.videoSrc, controls: true, playsinline: true, poster: item.poster || null });
    }
    return CS.picture(
      { src: item.src, webp: item.webp, width: item.width, height: item.height, alt: item.alt || item.title || '' },
      { eager: true }
    );
  }

  function show(position) {
    var dialog = document.getElementById('lightbox');
    if (!dialog || !items.length) return;
    index = (position + items.length) % items.length;
    var item = items[index];

    var slot = document.getElementById('lightboxMedia');
    CS.clear(slot);
    var node = media(item);
    if (node) slot.appendChild(node);

    var title = document.getElementById('lightboxTitle');
    var caption = document.getElementById('lightboxCaption');
    var count = document.getElementById('lightboxCount');
    if (title) title.textContent = item.title || '';
    if (caption) caption.textContent = item.caption || '';
    if (count) count.textContent = (index + 1) + ' / ' + items.length;

    var prev = document.getElementById('lightboxPrev');
    var next = document.getElementById('lightboxNext');
    if (prev) prev.hidden = items.length < 2;
    if (next) next.hidden = items.length < 2;

    dialog.setAttribute('aria-label', item.title ? 'Gallery: ' + item.title : 'Gallery viewer');
  }

  function open(position, trigger) {
    var dialog = document.getElementById('lightbox');
    if (!dialog) return;
    lastTrigger = trigger || null;
    show(position);
    if (typeof dialog.showModal === 'function') {
      if (!dialog.open) dialog.showModal();
      var close = document.getElementById('lightboxClose');
      if (close) close.focus();
    } else if (items[index] && items[index].src) {
      window.open(items[index].src, '_blank', 'noopener');
    }
  }

  function close() {
    var dialog = document.getElementById('lightbox');
    if (!dialog) return;
    if (dialog.open) dialog.close();
  }

  function bindLightbox() {
    if (bound) return;
    bound = true;
    var dialog = document.getElementById('lightbox');
    if (!dialog) return;

    var closeBtn = document.getElementById('lightboxClose');
    var prev = document.getElementById('lightboxPrev');
    var next = document.getElementById('lightboxNext');
    if (closeBtn) closeBtn.addEventListener('click', close);
    if (prev) prev.addEventListener('click', function () { show(index - 1); });
    if (next) next.addEventListener('click', function () { show(index + 1); });

    dialog.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowLeft') { event.preventDefault(); show(index - 1); }
      else if (event.key === 'ArrowRight') { event.preventDefault(); show(index + 1); }
      else if (event.key === 'Home') { event.preventDefault(); show(0); }
      else if (event.key === 'End') { event.preventDefault(); show(items.length - 1); }
    });

    /* Clicking the backdrop (the dialog element itself, outside its content). */
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) close();
    });

    dialog.addEventListener('close', function () {
      /* Clearing the media stops any video or embed from playing on. */
      CS.clear(document.getElementById('lightboxMedia'));
      if (lastTrigger && document.contains(lastTrigger)) lastTrigger.focus();
      lastTrigger = null;
      if (CS.gallery.onClose) { var fn = CS.gallery.onClose; CS.gallery.onClose = null; fn(); }
    });
  }

  /* --------------------------------------------------------------- render */

  function paint(all, filter) {
    var grid = document.getElementById('galleryGrid');
    if (!grid) return;
    items = filter === 'all' ? all.slice() : all.filter(function (i) { return (i.type || 'photo') === filter; });
    CS.clear(grid);
    items.forEach(function (item, position) { grid.appendChild(gridItem(item, position)); });
  }

  CS.gallery = {
    onClose: null,

    render: function (data) {
      var grid = document.getElementById('galleryGrid');
      var filters = document.getElementById('galleryFilters');
      var section = document.getElementById('gallery');
      if (!grid) return;

      var all = CS.activeOnly(CS.get(data, 'gallery', []));
      if (section) section.hidden = all.length === 0;
      if (!all.length) { CS.clear(grid); if (filters) CS.clear(filters); return; }

      bindLightbox();

      /* Only offer filters for types that actually have items. */
      var present = ['photo', 'poster', 'video'].filter(function (type) {
        return all.some(function (i) { return (i.type || 'photo') === type; });
      });

      if (filters) {
        CS.clear(filters);
        if (present.length > 1) {
          var options = [{ key: 'all', label: 'All' }].concat(present.map(function (t) {
            return { key: t, label: LABELS[t] };
          }));
          options.forEach(function (option, i) {
            filters.appendChild(CS.el('button', {
              class: 'chip',
              type: 'button',
              'aria-pressed': i === 0 ? 'true' : 'false',
              dataset: { filter: option.key },
              text: option.label
            }));
          });
        }
      }

      paint(all, 'all');

      if (filters && !filters.dataset.bound) {
        filters.dataset.bound = '1';
        filters.addEventListener('click', function (event) {
          var chip = event.target.closest('.chip');
          if (!chip) return;
          Array.prototype.forEach.call(filters.querySelectorAll('.chip'), function (c) {
            c.setAttribute('aria-pressed', c === chip ? 'true' : 'false');
          });
          paint(all, chip.dataset.filter);
        });
      }

      if (!grid.dataset.bound) {
        grid.dataset.bound = '1';
        grid.addEventListener('click', function (event) {
          var button = event.target.closest('.gallery-btn');
          if (!button) return;
          open(Number(button.dataset.index), button);
        });
      }
    },

    init: function (data) { CS.gallery.render(data); },
    isOpen: function () {
      var dialog = document.getElementById('lightbox');
      return !!(dialog && dialog.open);
    }
  };
})();
