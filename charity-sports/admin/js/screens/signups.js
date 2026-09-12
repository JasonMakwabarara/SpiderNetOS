/* The people who asked to hear about the next event. */
(function () {
  'use strict';
  var A = (window.ADMIN = window.ADMIN || {});
  A.screens = A.screens || {};

  A.screens.signups = {
    title: 'Supporters',
    async render(root, app) {
      var el = A.ui.el;
      var response = await A.api.signups();
      var list = response.signups || [];

      root.appendChild(el('p', { class: 'screen-intro',
        text: 'People who left their details on the website so you can tell them when the next ' +
              'event is on. This list lives on your own server and is not shared with anyone.' }));

      if (!list.length) {
        root.appendChild(el('div', { class: 'card' }, [
          el('p', { class: 'empty',
            text: 'Nobody has signed up yet. The form only appears on the website when this ' +
                  'server is reachable, so check the site is being served from here.' })
        ]));
        return;
      }

      var table = el('table', { class: 'table' }, [
        el('thead', null, [el('tr', null, [
          el('th', { text: 'Name' }), el('th', { text: 'Contact' }),
          el('th', { text: 'Added' }), el('th', { text: '' })
        ])]),
        el('tbody', null, list.map(function (person) {
          return el('tr', null, [
            el('td', null, [el('b', { text: person.name })]),
            el('td', null, [
              person.kind === 'email'
                ? el('a', { href: 'mailto:' + person.contact, text: person.contact })
                : el('a', {
                    href: 'https://wa.me/' + person.contact.replace(/\D/g, ''),
                    target: '_blank', rel: 'noopener noreferrer', text: person.contact
                  })
            ]),
            el('td', null, [el('time', { text: A.ui.fmtDateTime(person.addedAt) })]),
            el('td', null, [
              el('button', {
                class: 'btn btn-danger btn-sm', type: 'button', text: 'Remove',
                on: { click: function () { remove(person, app); } }
              })
            ])
          ]);
        }))
      ]);

      root.appendChild(el('div', { class: 'card' }, [
        el('div', { class: 'card-head' }, [
          el('h2', { text: list.length + (list.length === 1 ? ' person' : ' people') }),
          el('span', { class: 'spacer' }),
          el('a', {
            class: 'btn btn-quiet btn-sm',
            href: A.api.signupsCsvUrl,
            download: 'charity-sports-supporters.csv'
          }, [A.ui.icon('down'), ' Download as a spreadsheet'])
        ]),
        el('div', { class: 'table-wrap' }, [table]),
        el('p', { class: 'hint',
          text: 'Remove anyone who asks. It takes effect immediately and is recorded in the history.' })
      ]));
    }
  };

  async function remove(person, app) {
    var yes = await A.ui.confirmDialog({
      title: 'Remove ' + person.name + ' from the list?',
      lines: ['They will stop hearing about events. This cannot be undone from here.'],
      confirmLabel: 'Remove them',
      tone: 'danger'
    });
    if (!yes) return;
    try {
      await A.api.deleteSignup(person.id);
      A.ui.toast('Removed.', 'ok');
      app.rerender();
    } catch (err) {
      A.ui.toast(err.message, 'err');
    }
  }
})();
