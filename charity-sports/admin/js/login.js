/* The login form posts as a normal HTML form when JavaScript is off. With it
   on, we post the same fields through the API so errors land in the page
   instead of on a blank screen. */
(function () {
  'use strict';
  var form = document.getElementById('loginForm');
  var errorBox = document.getElementById('loginError');
  var submit = document.getElementById('loginSubmit');
  if (!form) return;

  function show(message) {
    errorBox.textContent = message;
    errorBox.hidden = false;
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    errorBox.hidden = true;
    submit.disabled = true;
    submit.textContent = 'Signing in…';

    var data = new FormData(form);
    fetch('../api/auth/login', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': String(data.get('_csrf') || '')
      },
      body: JSON.stringify({
        username: String(data.get('username') || ''),
        password: String(data.get('password') || '')
      })
    })
      .then(function (res) {
        return res.json().then(function (body) { return { status: res.status, body: body }; });
      })
      .then(function (result) {
        if (result.status === 200) {
          window.location.href = result.body.mustChangePassword ? './#/password' : './';
          return;
        }
        show((result.body && result.body.message) || 'Could not sign in. Try again.');
        submit.disabled = false;
        submit.textContent = 'Sign in';
        var password = document.getElementById('password');
        if (password) { password.value = ''; password.focus(); }
      })
      .catch(function () {
        show('Could not reach the server. Check your connection and try again.');
        submit.disabled = false;
        submit.textContent = 'Sign in';
      });
  });
})();
