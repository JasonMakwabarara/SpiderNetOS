/* The landing screen: the lives number front and centre, because that is the
   thing people come here to change, plus the state of the live site. */
(function () {
  'use strict';
  var A = (window.ADMIN = window.ADMIN || {});
  A.screens = A.screens || {};

  A.screens.dashboard = {
    title: 'Dashboard',
    async render(root, app) {
      var el = A.ui.el;
      var data = app.content.data;
      var impact = data.impact || {};

      /* ------------------------------------------------- what is still missing
         Two things suppress donations more than anything technical: a visitor
         who cannot see that anyone else has given, and a charity that offers
         nothing to be checked against. Say so here rather than in a document
         nobody opens. */
      var trust = data.accountability || {};
      var hasTrustFacts = !!(trust.registrationNumber || trust.bankedWith ||
        trust.financeContactName || trust.financeContactEmail || trust.receiptsPolicy);
      var hasRaised = typeof impact.raisedTotalUsd === 'number';

      if (!hasTrustFacts || !hasRaised) {
        var missing = el('div', { class: 'card' }, [
          el('div', { class: 'card-head' }, [el('h2', { text: 'Worth completing' })])
        ]);

        if (!hasRaised) {
          missing.appendChild(el('p', null, [
            el('b', { text: 'No fundraising total is published. ' }),
            'A visitor cannot see that a single dollar has been given. People give more ' +
            'readily when they can see that others already have.'
          ]));
          missing.appendChild(el('p', { class: 'quick-links' }, [
            el('a', { class: 'btn btn-quiet btn-sm', href: '#/impact', text: 'Add the total raised' })
          ]));
        }

        if (!hasTrustFacts) {
          missing.appendChild(el('p', null, [
            el('b', { text: 'Nothing on the site says who you are accountable to. ' }),
            'The registration number, who answers questions about money, and whether you ' +
            'issue receipts. The section stays hidden until you fill it in, so nothing ' +
            'unverified is showing in the meantime.'
          ]));
          missing.appendChild(el('p', { class: 'quick-links' }, [
            el('a', { class: 'btn btn-quiet btn-sm', href: '#/accountability', text: 'Fill in accountability' })
          ]));
        }

        root.appendChild(missing);
      }

      /* ------------------------------------------------------ lives helped */
      var number = el('input', { type: 'number', min: 0, step: 1, id: 'livesInput' });
      number.value = impact.livesHelped === null || impact.livesHelped === undefined ? '' : String(impact.livesHelped);

      var save = A.ui.saveButton('Save this number');
      var pct = el('span', { class: 'hint' });

      function updatePct() {
        var value = Number(number.value) || 0;
        var goal = Number(impact.goal) || 1000000;
        pct.textContent = A.ui.fmtInt(value) + ' of ' + A.ui.fmtInt(goal) +
          ' (' + (value / goal * 100).toFixed(value / goal < 0.01 ? 4 : 2) + '%)';
      }
      number.addEventListener('input', updatePct);
      updatePct();

      var steppers = el('span', { class: 'stepper' });
      [1, 5, 20].forEach(function (step) {
        steppers.appendChild(el('button', {
          class: 'btn btn-quiet btn-sm', type: 'button', text: '+' + step,
          on: { click: function () { number.value = String((Number(number.value) || 0) + step); updatePct(); } }
        }));
      });

      var livesForm = el('form', { class: 'lives-editor' }, [
        el('div', { class: 'field' }, [
          el('label', { for: 'livesInput', text: 'Lives helped so far' }),
          number
        ]),
        steppers,
        save.button,
        save.state
      ]);

      livesForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        try {
          await save.run(function () {
            return A.api.saveSection('impact', Object.assign({}, impact, {
              livesHelped: number.value === '' ? null : Number(number.value),
              lastUpdated: harareToday()
            }));
          });
          await app.reload();
          app.refreshPublishState();
          A.ui.toast('Number saved. Press Publish to show it on the website.', 'ok', 6000);
        } catch (err) {
          A.ui.toast(err.message, 'err');
        }
      });

      root.appendChild(el('div', { class: 'card' }, [
        el('div', { class: 'card-head' }, [el('h2', { text: 'Lives helped' })]),
        el('p', { text: 'This is the big number on the front page. Move it when a cause has actually been delivered.' }),
        livesForm,
        pct,
        el('p', { class: 'hint', text: 'Saving also sets the "last updated" date to today.' })
      ]));

      /* --------------------------------------------------------- overview */
      var counts = [
        { label: 'Causes open', value: (data.causes || []).filter(function (c) { return c.status === 'current' && c.active !== false; }).length },
        { label: 'Events listed', value: (data.events || []).filter(function (e) { return e.active !== false; }).length },
        { label: 'Sponsors shown', value: (data.sponsors || []).filter(function (s) { return s.active !== false; }).length },
        { label: 'Gallery items', value: (data.gallery || []).filter(function (g) { return g.active !== false; }).length }
      ];
      root.appendChild(el('div', { class: 'card' }, [
        el('div', { class: 'card-head' }, [el('h2', { text: 'At a glance' })]),
        el('div', { class: 'stat-grid' }, counts.map(function (row) {
          return el('div', { class: 'stat' }, [
            el('b', { text: String(row.value) }),
            el('span', { text: row.label })
          ]);
        }))
      ]));

      /* ----------------------------------------------------------- next up */
      var next = nextEvent(data.events || []);
      root.appendChild(el('div', { class: 'card' }, [
        el('div', { class: 'card-head' }, [el('h2', { text: 'Next event' })]),
        next
          ? el('div', null, [
              el('p', null, [el('b', { text: next.title }), next.when ? ' — ' + next.when : '']),
              el('p', null, [el('a', { class: 'btn btn-quiet btn-sm', href: '#/events/' + next.id, text: 'Edit this event' })])
            ])
          : el('p', { class: 'empty', text: 'Nothing dated is coming up. Add one, or give the next event a date.' })
      ]));

      /* ------------------------------------------------- backup and restore */
      var restoreInput = el('input', {
        type: 'file', accept: 'application/json,.json', id: 'restoreFile'
      });

      restoreInput.addEventListener('change', async function () {
        var file = restoreInput.files && restoreInput.files[0];
        if (!file) return;

        var text;
        try {
          text = await file.text();
        } catch (err) {
          A.ui.toast('That file could not be read.', 'err');
          restoreInput.value = '';
          return;
        }

        var parsed;
        try {
          parsed = JSON.parse(text);
        } catch (err) {
          A.ui.toast('That is not a backup file. It should be the .json file you downloaded from here.', 'err');
          restoreInput.value = '';
          return;
        }

        var when = parsed && parsed.updatedAt ? A.ui.fmtDateTime(parsed.updatedAt) : 'an unknown date';
        var yes = await A.ui.confirmDialog({
          title: 'Restore everything from this backup?',
          lines: [
            'This replaces all the content on the site with the copy in that file, from ' + when + '.',
            'Your current content is saved first, so this can be undone, but only from the server.',
            'Nothing reaches the public site until you press Publish afterwards.'
          ],
          confirmLabel: 'Restore it',
          tone: 'danger'
        });
        restoreInput.value = '';
        if (!yes) return;

        try {
          await A.api.restore(parsed);
          await app.reload();
          app.refreshPublishState();
          A.ui.toast('Restored. Check the site looks right, then press Publish.', 'ok', 9000);
          app.rerender();
        } catch (err) {
          if (err.errors && err.errors.length) {
            A.ui.toast('That backup was refused: ' + err.errors[0].message, 'err', 10000);
          } else {
            A.ui.toast(err.message, 'err');
          }
        }
      });

      root.appendChild(el('div', { class: 'card' }, [
        el('div', { class: 'card-head' }, [el('h2', { text: 'Backup' })]),
        el('p', { text: 'Take a copy before you change anything big. The file holds all the words, ' +
                        'numbers, events and sponsors, but not the pictures themselves.' }),
        el('p', { class: 'quick-links' }, [
          el('a', {
            class: 'btn btn-quiet btn-sm',
            href: A.api.backupUrl,
            download: 'charity-sports-backup.json'
          }, [A.ui.icon('down'), ' Download a backup'])
        ]),
        el('div', { class: 'field' }, [
          el('label', { for: 'restoreFile', text: 'Restore from a backup file' }),
          restoreInput,
          el('span', { class: 'hint',
            text: 'Replaces everything. You will be asked to confirm, and the current content is ' +
                  'saved first so it can be undone.' })
        ])
      ]));

      /* --------------------------------------------------------- shortcuts */
      root.appendChild(el('div', { class: 'card' }, [
        el('div', { class: 'card-head' }, [el('h2', { text: 'Common jobs' })]),
        el('div', { class: 'quick-links' }, [
          el('a', { class: 'btn btn-quiet btn-sm', href: '#/causes/new', text: 'Add a cause' }),
          el('a', { class: 'btn btn-quiet btn-sm', href: '#/events/new', text: 'Add an event' }),
          el('a', { class: 'btn btn-quiet btn-sm', href: '#/sponsors/new', text: 'Add a sponsor' }),
          el('a', { class: 'btn btn-quiet btn-sm', href: '#/gallery/new', text: 'Add a photo' }),
          el('a', { class: 'btn btn-quiet btn-sm', href: '#/donate', text: 'Change the donate link' }),
          el('a', { class: 'btn btn-quiet btn-sm', href: '#/accountability', text: 'Accountability details' })
        ])
      ]));
    }
  };

  function harareToday() {
    return new Date(Date.now() + 2 * 3600 * 1000).toISOString().slice(0, 10);
  }

  function nextEvent(events) {
    var today = harareToday();
    var best = null;
    events.filter(function (e) { return e.active !== false; }).forEach(function (event) {
      var dates = (event.sessions && event.sessions.length)
        ? event.sessions.map(function (s) { return s.date; })
        : (event.startDate ? [event.startDate] : []);
      dates.filter(Boolean).sort().forEach(function (date) {
        if (date < today) return;
        if (!best || date < best.date) best = { id: event.id, title: event.title, date: date, when: A.ui.fmtDate(date) };
      });
    });
    return best;
  }
})();
