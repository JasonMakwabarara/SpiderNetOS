/* Who can sign in. Starts as one shared account; this is how it stops being
   shared once more than one person is doing the work. */
(function () {
  'use strict';
  var A = (window.ADMIN = window.ADMIN || {});
  A.screens = A.screens || {};

  A.screens.users = {
    title: 'People',
    async render(root, app) {
      var el = A.ui.el;
      var response = await A.api.users();
      var users = response.users || [];

      root.appendChild(el('p', { class: 'screen-intro',
        text: 'Everyone who can sign in here. Give each person their own account rather than ' +
              'sharing one: the history then shows who changed what.' }));

      if (users.length === 1) {
        root.appendChild(el('p', { class: 'banner banner-info',
          text: 'There is one shared account. Adding a named account for each person is worth doing ' +
                'before more than one of you is editing.' }));
      }

      var table = el('table', { class: 'table' }, [
        el('thead', null, [el('tr', null, [
          el('th', { text: 'Username' }), el('th', { text: 'Name' }),
          el('th', { text: 'Last signed in' }), el('th', { text: 'State' }), el('th', { text: '' })
        ])]),
        el('tbody', null, users.map(function (user) {
          var states = [];
          if (user.disabled) states.push('switched off');
          if (user.mustChangePassword) states.push('must change password');
          if (user.lockedUntil && new Date(user.lockedUntil) > new Date()) states.push('locked');
          if (user.id === (app.user && app.user.id)) states.push('this is you');

          return el('tr', null, [
            el('td', null, [el('b', { text: user.username })]),
            el('td', { text: user.displayName }),
            el('td', null, [el('time', { text: A.ui.fmtDateTime(user.lastLoginAt) })]),
            el('td', { text: states.join(', ') || 'active' }),
            el('td', null, [el('div', { class: 'item-actions' }, [
              el('button', {
                class: 'btn btn-quiet btn-sm', type: 'button', text: 'Reset password',
                on: { click: function () { resetPassword(user, app); } }
              }),
              user.id === (app.user && app.user.id) ? null : el('button', {
                class: 'btn btn-quiet btn-sm', type: 'button',
                text: user.disabled ? 'Switch on' : 'Switch off',
                on: { click: function () { toggle(user, app); } }
              }),
              user.id === (app.user && app.user.id) ? null : el('button', {
                class: 'btn btn-danger btn-sm', type: 'button', text: 'Delete',
                on: { click: function () { remove(user, app); } }
              })
            ])])
          ]);
        }))
      ]);

      root.appendChild(el('div', { class: 'card' }, [
        el('div', { class: 'card-head' }, [el('h2', { text: 'Accounts' })]),
        el('div', { class: 'table-wrap' }, [table])
      ]));

      /* ------------------------------------------------------- add someone */
      var username = el('input', { type: 'text', id: 'newUsername', autocapitalize: 'none', spellcheck: 'false' });
      var displayName = el('input', { type: 'text', id: 'newDisplayName' });
      var save = A.ui.saveButton('Create the account');

      var form = el('form', { novalidate: true }, [
        el('div', { class: 'field-row' }, [
          el('div', { class: 'field' }, [
            el('label', { for: 'newUsername', text: 'Username' }),
            username,
            el('span', { class: 'hint', text: '3 to 32 letters, numbers, dots, dashes or underscores.' }),
            el('span', { class: 'error', hidden: true })
          ]),
          el('div', { class: 'field' }, [
            el('label', { for: 'newDisplayName', text: 'Full name' }),
            displayName
          ])
        ]),
        save.node
      ]);

      form.addEventListener('submit', async function (event) {
        event.preventDefault();
        try {
          var result = await save.run(function () {
            return A.api.createUser(username.value.trim(), displayName.value.trim());
          });
          await A.ui.showSecret({
            title: 'Account created for ' + username.value.trim(),
            lines: [
              'This is their password. It is shown once and is not stored anywhere.',
              'Send it to them in person or through a channel you trust, not in the same message as the web address.',
              'They will be asked to choose their own password the first time they sign in.'
            ],
            secret: result.temporaryPassword
          });
          app.rerender();
        } catch (err) {
          if (err.errors) {
            var box = form.querySelector('[for="newUsername"]').parentNode;
            box.classList.add('has-error');
            var slot = box.querySelector('.error');
            slot.textContent = err.errors[0].message;
            slot.hidden = false;
          } else {
            A.ui.toast(err.message, 'err');
          }
        }
      });

      root.appendChild(el('div', { class: 'card' }, [
        el('div', { class: 'card-head' }, [el('h2', { text: 'Add someone' })]),
        form
      ]));
    }
  };

  async function resetPassword(user, app) {
    var yes = await A.ui.confirmDialog({
      title: 'Reset the password for ' + user.username + '?',
      lines: [
        'A new password is generated and shown to you once.',
        'They are signed out everywhere and will have to choose their own password next time they sign in.'
      ],
      confirmLabel: 'Reset it'
    });
    if (!yes) return;
    try {
      var result = await A.api.resetUserPassword(user.id);
      await A.ui.showSecret({
        title: 'New password for ' + user.username,
        lines: ['Shown once. Pass it on through something you trust.'],
        secret: result.temporaryPassword
      });
      app.rerender();
    } catch (err) { A.ui.toast(err.message, 'err'); }
  }

  async function toggle(user, app) {
    try {
      await A.api.updateUser(user.id, { disabled: !user.disabled });
      A.ui.toast(user.disabled ? 'Account switched back on.' : 'Account switched off and signed out.', 'ok');
      app.rerender();
    } catch (err) {
      A.ui.toast(err.errors ? err.errors[0].message : err.message, 'err');
    }
  }

  async function remove(user, app) {
    var yes = await A.ui.confirmDialog({
      title: 'Delete the account "' + user.username + '"?',
      lines: [
        'They will be signed out and will not be able to sign in again.',
        'What they already changed stays in the history.'
      ],
      confirmLabel: 'Delete the account',
      tone: 'danger'
    });
    if (!yes) return;
    try {
      await A.api.deleteUser(user.id);
      A.ui.toast('Account deleted.', 'ok');
      app.rerender();
    } catch (err) {
      A.ui.toast(err.errors ? err.errors[0].message : err.message, 'err');
    }
  }
})();
