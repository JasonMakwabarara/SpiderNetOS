/* A screen that manages a list: causes, events, sponsors, gallery, ways to
   support. List, add, edit, reorder, delete. All from the spec in fields.js. */
(function () {
  'use strict';
  var A = (window.ADMIN = window.ADMIN || {});
  A.screens = A.screens || {};

  A.screens.collection = function (name) {
    var spec = A.fields.COLLECTIONS[name];

    return {
      title: spec.title,
      async render(root, app, params) {
        var el = A.ui.el;
        var items = (app.content.data[name] || []).slice();

        if (params && params.id) {
          var editing = params.id === 'new' ? null : items.find(function (i) { return i.id === params.id; });
          if (params.id !== 'new' && !editing) {
            A.ui.toast('That ' + spec.singular + ' no longer exists.', 'err');
            window.location.hash = '#/' + name;
            return;
          }
          return renderEditor(root, app, editing);
        }

        root.appendChild(el('p', { class: 'screen-intro', text: spec.intro }));

        root.appendChild(el('div', { class: 'card-head' }, [
          el('h2', { text: items.length + ' ' + (items.length === 1 ? spec.singular : spec.title.toLowerCase()) }),
          el('span', { class: 'spacer' }),
          el('a', { class: 'btn btn-primary btn-sm', href: '#/' + name + '/new' },
            [A.ui.icon('plus'), ' Add a ' + spec.singular])
        ]));

        var list = A.list.create({
          items: items,
          summaryOf: spec.summaryOf,
          badgeOf: spec.badgeOf,
          thumbOf: spec.thumbOf,
          emptyText: 'No ' + spec.title.toLowerCase() + ' yet. Add the first one.',
          onEdit: function (item) { window.location.hash = '#/' + name + '/' + item.id; },
          onDelete: async function (item) {
            var yes = await A.ui.confirmDialog({
              title: 'Delete "' + (item.title || item.name) + '"?',
              lines: [
                'It will be removed from the website the next time you publish.',
                'If you only want to hide it for now, edit it and untick "Show this on the website" instead.'
              ],
              confirmLabel: 'Delete it',
              tone: 'danger'
            });
            if (!yes) return;
            try {
              await A.api.deleteItem(name, item.id);
              A.ui.toast('Deleted.', 'ok');
              await app.reload();
              app.rerender();
              app.refreshPublishState();
            } catch (err) {
              A.ui.toast(err.message, 'err');
            }
          },
          onReorder: async function (ids) {
            try {
              await A.api.reorder(name, ids);
              await app.reload({ quiet: true });
              app.refreshPublishState();
              A.ui.toast('New order saved.', 'ok', 2500);
            } catch (err) {
              A.ui.toast(err.message + ' Reloading the list.', 'err');
              await app.reload();
              app.rerender();
            }
          }
        });
        root.appendChild(list.node);
      }
    };

    async function renderEditor(root, app, item) {
      var el = A.ui.el;
      var isNew = !item;
      var content = app.content.data;
      var ctx = {
        sponsorTiers: (content.sponsorTiers || []).map(function (tier) {
          return { value: tier.id, label: tier.title };
        })
      };

      var value = isNew ? Object.assign({}, spec.blank) : item;

      var current = item;
      var form = A.form.create(spec, value, ctx);
      var save = A.ui.saveButton(isNew ? 'Add this ' + spec.singular : 'Save changes');
      var heading = el('h2', { text: isNew ? 'New ' + spec.singular : (item.title || item.name) });

      form.node.addEventListener('submit', async function (event) {
        event.preventDefault();
        form.clearErrors();
        var payload = form.read();
        delete payload.__media;

        try {
          var result = await save.run(function () {
            return current ? A.api.saveItem(name, current.id, payload) : A.api.createItem(name, payload);
          });
          await app.reload();
          app.refreshPublishState();

          if (!current && result && result.value) {
            /* Switch the editor from "new" to "editing" in place. Jumping to a
               new route here would wipe the confirmation the person just
               earned, and make it look as though nothing had happened. */
            current = result.value;
            save.setLabel('Save changes');
            heading.textContent = current.title || current.name;
            window.history.replaceState(null, '', '#/' + name + '/' + current.id);
            A.ui.toast('Added. Press Publish when you want it on the live site.', 'ok', 7000);
          } else {
            A.ui.toast('Saved. Press Publish when you want it on the live site.', 'ok', 6000);
          }
        } catch (err) {
          if (err.errors) form.showErrors(err.errors);
          else A.ui.toast(err.message, 'err');
          if (err.status === 409) app.offerReload();
        }
      });

      root.appendChild(el('p', null, [
        el('a', { class: 'btn btn-quiet btn-sm', href: '#/' + name, text: '← Back to ' + spec.title.toLowerCase() })
      ]));
      root.appendChild(heading);

      form.node.appendChild(save.node);
      root.appendChild(el('div', { class: 'card' }, [form.node]));
    }
  };
})();
