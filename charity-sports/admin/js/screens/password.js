/* Change password. Also the screen someone is forced onto the first time they
   sign in, which is why it explains itself rather than just showing inputs. */
(function () {
  'use strict';
  var A = (window.ADMIN = window.ADMIN || {});
  A.screens = A.screens || {};

  A.screens.password = {
    title: 'Your password',
    async render(root, app) {
      var el = A.ui.el;
      var forced = app.user && app.user.mustChangePassword;

      if (forced) {
        root.appendChild(el('p', { class: 'banner banner-warn',
          text: 'Choose your own password before you can change anything else. ' +
                'The one you were given was generated for the handover and should not be kept.' }));
      }

      var current = el('input', { type: 'password', id: 'currentPassword', autocomplete: 'current-password', required: true });
      var next = el('input', { type: 'password', id: 'newPassword', autocomplete: 'new-password', required: true });
      var again = el('input', { type: 'password', id: 'confirmPassword', autocomplete: 'new-password', required: true });
      var save = A.ui.saveButton('Change my password');

      var form = el('form', { novalidate: true }, [
        el('div', { class: 'field', dataset: { field: 'currentPassword' } }, [
          el('label', { for: 'currentPassword', text: 'Your current password' }),
          current,
          el('span', { class: 'error', hidden: true })
        ]),
        el('div', { class: 'field', dataset: { field: 'newPassword' } }, [
          el('label', { for: 'newPassword', text: 'New password' }),
          next,
          el('span', { class: 'hint',
            text: 'At least 12 characters. A short sentence you will remember beats a scramble you will not, ' +
                  'for example "three padel nights in Borrowdale".' }),
          el('span', { class: 'error', hidden: true })
        ]),
        el('div', { class: 'field', dataset: { field: 'confirmPassword' } }, [
          el('label', { for: 'confirmPassword', text: 'New password again' }),
          again,
          el('span', { class: 'error', hidden: true })
        ]),
        save.node
      ]);

      function showError(field, message) {
        var box = form.querySelector('[data-field="' + field + '"]');
        if (!box) { A.ui.toast(message, 'err'); return; }
        box.classList.add('has-error');
        var slot = box.querySelector('.error');
        slot.textContent = message;
        slot.hidden = false;
      }

      function clearErrors() {
        Array.prototype.forEach.call(form.querySelectorAll('.field'), function (box) {
          box.classList.remove('has-error');
          var slot = box.querySelector('.error');
          if (slot) { slot.textContent = ''; slot.hidden = true; }
        });
      }

      form.addEventListener('submit', async function (event) {
        event.preventDefault();
        clearErrors();

        if (next.value !== again.value) {
          showError('confirmPassword', 'These two do not match.');
          again.focus();
          return;
        }

        try {
          await save.run(function () { return A.api.changePassword(current.value, next.value); });
          current.value = next.value = again.value = '';
          A.ui.toast('Password changed. Everywhere else you were signed in has been signed out.', 'ok', 7000);
          await app.loadUser();
          /* The content could not be fetched while the change was outstanding,
             so load it now before sending anyone to a screen that needs it. */
          await app.reload();
          /* The publish badge was left saying "cannot check", because the
             status call was refused while the change was outstanding. */
          app.refreshPublishState();
          if (window.location.hash === '#/' || window.location.hash === '') app.rerender();
          else window.location.hash = '#/';
        } catch (err) {
          if (err.errors) err.errors.forEach(function (e) { showError(e.path, e.message); });
          else A.ui.toast(err.message, 'err');
        }
      });

      root.appendChild(el('div', { class: 'card' }, [form]));
    }
  };
})();
