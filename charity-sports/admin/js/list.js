/* A reorderable list of items.
   Drag with a pointer, or focus a handle and use the arrow keys, which is
   both an accessibility requirement and simply easier on a laptop. */
(function () {
  'use strict';
  var A = (window.ADMIN = window.ADMIN || {});

  function create(options) {
    var el = A.ui.el, icon = A.ui.icon;
    var items = options.items.slice();
    var list = el('div', { class: 'item-list' });
    var lifted = null;

    function announce(message) {
      var live = document.getElementById('reorderLive');
      if (live) live.textContent = message;
    }

    function order() {
      return Array.prototype.map.call(list.children, function (row) { return row.dataset.id; });
    }

    function commit() {
      options.onReorder(order());
    }

    function move(row, delta) {
      var siblings = Array.prototype.slice.call(list.children);
      var index = siblings.indexOf(row);
      var next = index + delta;
      if (next < 0 || next >= siblings.length) return;
      if (delta > 0) list.insertBefore(siblings[next], row);
      else list.insertBefore(row, siblings[next]);
      announce(row.dataset.name + ' moved to position ' + (next + 1) + ' of ' + siblings.length);
      commit();
    }

    items.forEach(function (item) {
      var badge = options.badgeOf && options.badgeOf(item);
      var thumb = options.thumbOf && options.thumbOf(item);

      var handle = el('button', {
        class: 'item-handle', type: 'button', draggable: 'true',
        'aria-label': 'Reorder ' + (item.title || item.name || 'item') +
          '. Press space to pick up, then the arrow keys.',
        on: {
          keydown: function (event) {
            if (event.key === ' ' || event.key === 'Enter') {
              event.preventDefault();
              lifted = lifted === row ? null : row;
              row.classList.toggle('is-lifted', lifted === row);
              announce(lifted ? 'Picked up. Use the arrow keys, then space to drop.' : 'Dropped.');
            } else if (lifted === row && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
              event.preventDefault();
              move(row, event.key === 'ArrowDown' ? 1 : -1);
              handle.focus();
            } else if (event.key === 'Escape' && lifted === row) {
              lifted = null;
              row.classList.remove('is-lifted');
            }
          },
          dragstart: function (event) {
            lifted = row;
            row.classList.add('is-lifted');
            event.dataTransfer.effectAllowed = 'move';
            try { event.dataTransfer.setData('text/plain', item.id); } catch (err) { /* older browsers */ }
          },
          dragend: function () {
            if (lifted) lifted.classList.remove('is-lifted');
            lifted = null;
            commit();
          }
        }
      }, [icon('drag')]);

      var body = el('div', { class: 'item-body' }, [
        el('b', { text: item.title || item.name || item.id }),
        el('span', { text: (options.summaryOf && options.summaryOf(item)) || '' })
      ]);
      if (badge) body.insertBefore(el('span', { class: 'tag ' + badge.className, text: badge.text }), body.firstChild.nextSibling);
      if (item.active === false) {
        body.appendChild(el('span', { class: 'tag tag-hidden', text: 'hidden' }));
      }

      var actions = el('div', { class: 'item-actions' }, [
        el('button', { class: 'btn btn-quiet btn-sm', type: 'button', text: 'Edit',
          on: { click: function () { options.onEdit(item); } } }),
        el('button', { class: 'btn btn-danger btn-sm', type: 'button', text: 'Delete',
          on: { click: function () { options.onDelete(item); } } })
      ]);

      var row = el('div', {
        class: 'item' + (item.active === false ? ' is-inactive' : ''),
        dataset: { id: item.id, name: item.title || item.name || item.id },
        on: {
          dragover: function (event) {
            if (!lifted || lifted === row) return;
            event.preventDefault();
            var box = row.getBoundingClientRect();
            var after = event.clientY > box.top + box.height / 2;
            list.insertBefore(lifted, after ? row.nextSibling : row);
          },
          drop: function (event) { event.preventDefault(); }
        }
      }, [handle, thumb ? el('img', { class: 'item-thumb', src: '../' + thumb, alt: '' }) : null, body, actions]);

      /* Keep the three-column grid when a thumbnail is present. */
      if (thumb) row.style.setProperty('grid-template-columns', 'auto auto 1fr auto');

      list.appendChild(row);
    });

    if (!items.length) {
      list.appendChild(el('p', { class: 'empty', text: options.emptyText || 'Nothing here yet.' }));
    }

    return {
      node: el('div', null, [
        list,
        el('p', { class: 'visually-hidden', id: 'reorderLive', 'aria-live': 'polite' })
      ]),
      order: order
    };
  }

  A.list = { create: create };
})();
