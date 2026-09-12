/* Image upload with an instant local preview and a real progress bar.
   The server returns the resized variants, so nobody ever types a file path. */
(function () {
  'use strict';
  var A = (window.ADMIN = window.ADMIN || {});

  var PRIMARY = { logo: 'logo', gallery: 'src', hero: 'src', poster: 'src', cause: 'src' };

  /* Two shapes exist in the content and both have to work here.
     A nested one, where the picture is an object under a single key
     (hero.image, causes.image, events.poster), and a flat one, where the
     paths sit on the item itself (sponsors.logo, gallery.src). A spec marked
     flat:true is handed the whole item and gives back top-level keys. */
  function readIn(spec, value) {
    if (!value || typeof value !== 'object') return null;
    if (!spec.flat) return Object.assign({}, value);
    var picked = {};
    var found = false;
    (spec.keys || [spec.key]).forEach(function (key) {
      if (value[key] !== undefined && value[key] !== null) { picked[key] = value[key]; found = true; }
    });
    return found ? picked : null;
  }

  function field(spec, value, ctx) {
    var el = A.ui.el;
    var current = readIn(spec, value);

    var preview = el('div', { class: 'upload-preview' });
    var bar = el('div', { class: 'upload-bar', hidden: true }, [el('span')]);
    var fill = bar.firstChild;
    var input = el('input', { type: 'file', accept: 'image/png,image/jpeg,image/webp,image/svg+xml' });
    var status = el('span', { class: 'hint' });

    function paint() {
      A.ui.clear(preview);
      var src = current && (current[spec.key] || current[PRIMARY[spec.kind]] || current.src || current.logo || current.thumb);
      if (src) {
        preview.appendChild(el('img', { src: '../' + src, alt: '' }));
      } else {
        preview.appendChild(el('p', { text: 'No image yet. A name tile is shown instead, which is fine.' }));
      }
      preview.className = 'upload-preview' + (spec.kind === 'logo' ? '' : ' light');
      status.textContent = current && current.width
        ? current.width + ' by ' + current.height + ' pixels'
        : '';
    }

    function removeImage() {
      current = null;
      input.value = '';
      paint();
    }

    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file) return;

      /* Show the chosen file straight away, before the round trip. */
      var localUrl = URL.createObjectURL(file);
      A.ui.clear(preview);
      preview.appendChild(el('img', { src: localUrl, alt: '' }));

      bar.hidden = false;
      fill.style.setProperty('width', '2%');
      status.textContent = 'Uploading…';

      A.api.upload(file, spec.kind === 'cause' ? 'hero' : spec.kind, function (ratio) {
        fill.style.setProperty('width', Math.round(ratio * 90) + '%');
      })
        .then(function (result) {
          fill.style.setProperty('width', '100%');
          current = Object.assign({}, current || {}, result.ref);
          if (spec.kind === 'gallery' || spec.kind === 'poster') {
            current.thumb = result.ref.thumb || current.thumb;
            current.thumbWebp = result.ref.thumbWebp || current.thumbWebp;
          }
          URL.revokeObjectURL(localUrl);
          paint();
          A.ui.toast('Image uploaded. Remember to save.', 'ok');
        })
        .catch(function (err) {
          URL.revokeObjectURL(localUrl);
          paint();
          status.textContent = '';
          A.ui.toast(err.message || 'That upload did not work.', 'err');
        })
        .then(function () {
          setTimeout(function () { bar.hidden = true; fill.style.setProperty('width', '0'); }, 600);
        });
    });

    paint();

    var box = el('div', { class: 'field', dataset: { field: spec.key } }, [
      el('span', { class: 'field-label', text: spec.label }),
      el('div', { class: 'upload' }, [
        preview,
        bar,
        el('div', { class: 'upload-row' }, [
          input,
          el('button', {
            class: 'btn btn-quiet btn-sm', type: 'button', text: 'Remove',
            on: { click: removeImage }
          }),
          status
        ])
      ])
    ]);
    if (spec.hint) box.appendChild(el('span', { class: 'hint', text: spec.hint }));

    /* Alt text lives with the image for hero and cause pictures, where the
       surrounding form has no separate field for it. */
    var altInput = null;
    if (spec.kind !== 'logo' && spec.kind !== 'gallery' && spec.kind !== 'poster') {
      altInput = el('input', { type: 'text', value: (current && current.alt) || '' });
      box.appendChild(el('div', { class: 'field' }, [
        el('span', { class: 'field-label', text: 'Photo description for screen readers' }),
        altInput,
        el('span', { class: 'hint', text: 'Say what the photo shows, in one sentence.' })
      ]));
    }
    box.appendChild(el('span', { class: 'error', hidden: true }));

    return {
      node: box,
      read: function () {
        if (spec.flat) {
          /* Always name every key, so removing a picture clears the old
             paths instead of leaving them behind. */
          var flat = {};
          (spec.keys || [spec.key]).forEach(function (key) {
            flat[key] = current && current[key] !== undefined ? current[key] : null;
          });
          return flat;
        }
        if (!current) return null;
        var out = Object.assign({}, current);
        if (altInput) out.alt = altInput.value.trim() || null;
        return out;
      },
      focus: function () { input.focus(); }
    };
  }

  A.upload = { field: field };
})();
