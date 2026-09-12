/* What has happened, newest first. Read-only on purpose. */
(function () {
  'use strict';
  var A = (window.ADMIN = window.ADMIN || {});
  A.screens = A.screens || {};

  var WORDS = {
    login: 'signed in',
    logout: 'signed out',
    password_change: 'changed their password',
    reset_password: 'reset a password',
    create_user: 'created an account',
    update_user: 'changed an account',
    delete_user: 'deleted an account',
    update_section: 'edited',
    create_item: 'added',
    update_item: 'edited',
    delete_item: 'deleted',
    reorder: 'reordered',
    upload: 'uploaded an image',
    delete_upload: 'deleted an image',
    publish: 'published to the live site',
    backup: 'downloaded a backup',
    restore: 'restored from a backup'
  };

  function describe(entry) {
    var verb = WORDS[entry.action] || entry.action;
    var target = entry.target ? ' ' + entry.target : '';
    if (entry.result && entry.result !== 'ok') return verb + target + ' — ' + entry.result;
    return verb + target;
  }

  function detail(entry) {
    if (!entry.summary || typeof entry.summary !== 'object') return '';
    return Object.keys(entry.summary).slice(0, 4).map(function (key) {
      var change = entry.summary[key];
      if (change && typeof change === 'object' && 'from' in change) {
        return key + ': ' + short(change.from) + ' → ' + short(change.to);
      }
      return key + ': ' + short(change);
    }).join(', ');
  }

  function short(value) {
    if (value === null || value === undefined) return 'empty';
    var text = String(value);
    return text.length > 40 ? text.slice(0, 40) + '…' : text;
  }

  A.screens.audit = {
    title: 'History',
    async render(root) {
      var el = A.ui.el;
      var cursor = 0;
      var filters = { actor: '', action: '' };
      var body = el('tbody');
      var countLine = el('p', { class: 'hint' });

      var actorSelect = el('select', { id: 'filterActor' });
      var actionSelect = el('select', { id: 'filterAction' });
      var filtersBuilt = false;

      function buildFilters(page) {
        if (filtersBuilt) return;
        filtersBuilt = true;
        actorSelect.appendChild(el('option', { value: '', text: 'Everyone' }));
        (page.actors || []).forEach(function (name) {
          actorSelect.appendChild(el('option', { value: name, text: name }));
        });
        actionSelect.appendChild(el('option', { value: '', text: 'Everything' }));
        (page.actions || []).forEach(function (name) {
          actionSelect.appendChild(el('option', { value: name, text: WORDS[name] || name }));
        });
      }

      function onFilterChange() {
        filters.actor = actorSelect.value;
        filters.action = actionSelect.value;
        cursor = 0;
        A.ui.clear(body);
        load();
      }
      actorSelect.addEventListener('change', onFilterChange);
      actionSelect.addEventListener('change', onFilterChange);

      var table = el('table', { class: 'table' }, [
        el('thead', null, [el('tr', null, [
          el('th', { text: 'When' }), el('th', { text: 'Who' }),
          el('th', { text: 'What' }), el('th', { text: 'Details' })
        ])]),
        body
      ]);

      var more = el('button', {
        class: 'btn btn-quiet btn-sm', type: 'button', text: 'Load older', hidden: true
      });

      async function load() {
        more.disabled = true;
        try {
          var page = await A.api.audit(cursor, filters);
          buildFilters(page);
          countLine.textContent = page.total === 1
            ? '1 entry'
            : page.total + ' entries' + (filters.actor || filters.action ? ' match these filters' : '');
          (page.entries || []).forEach(function (entry) {
            body.appendChild(el('tr', null, [
              el('td', null, [el('time', { text: A.ui.fmtDateTime(entry.ts) })]),
              el('td', { text: entry.actor ? entry.actor.username : 'someone not signed in' }),
              el('td', { text: describe(entry) }),
              el('td', { text: detail(entry) })
            ]));
          });
          if (!page.entries.length && !cursor) {
            body.appendChild(el('tr', null, [el('td', {
              colspan: '4',
              text: (filters.actor || filters.action)
                ? 'Nothing matches those filters.'
                : 'Nothing recorded yet.'
            })]));
          }
          cursor = page.nextCursor;
          more.hidden = !cursor;
        } catch (err) {
          A.ui.toast(err.message, 'err');
        } finally {
          more.disabled = false;
        }
      }

      more.addEventListener('click', load);

      root.appendChild(el('p', { class: 'screen-intro',
        text: 'Every sign-in, edit, upload and publish. Kept so you can see who changed what, ' +
              'and so an unexpected change is easy to trace.' }));
      root.appendChild(el('div', { class: 'card' }, [
        el('div', { class: 'field-row' }, [
          el('div', { class: 'field' }, [
            el('label', { for: 'filterActor', text: 'Who' }), actorSelect
          ]),
          el('div', { class: 'field' }, [
            el('label', { for: 'filterAction', text: 'What they did' }), actionSelect
          ])
        ]),
        countLine,
        el('div', { class: 'table-wrap' }, [table]),
        more
      ]));

      await load();
    }
  };
})();
