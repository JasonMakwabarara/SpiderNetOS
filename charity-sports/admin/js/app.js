/* Ties the panel together: routing, the sidebar, the publish button, and the
   one copy of the content every screen reads from. */
(function () {
  'use strict';
  var A = (window.ADMIN = window.ADMIN || {});

  var ROUTES = [
    { hash: '', title: 'Dashboard', icon: 'gauge', screen: function () { return A.screens.dashboard; } },
    { group: 'The website' },
    { hash: 'impact', icon: 'gauge', screen: function () { return A.screens.section('impact'); } },
    { hash: 'causes', icon: 'heart', screen: function () { return A.screens.collection('causes'); } },
    { hash: 'events', icon: 'calendar', screen: function () { return A.screens.collection('events'); } },
    { hash: 'sponsors', icon: 'star', screen: function () { return A.screens.collection('sponsors'); } },
    { hash: 'gallery', icon: 'image', screen: function () { return A.screens.collection('gallery'); } },
    { hash: 'waysToSupport', icon: 'hand', screen: function () { return A.screens.collection('waysToSupport'); } },
    { group: 'Words and settings' },
    { hash: 'hero', icon: 'list', screen: function () { return A.screens.section('hero'); } },
    { hash: 'about', icon: 'list', screen: function () { return A.screens.section('about'); } },
    { hash: 'accountability', icon: 'check', screen: function () { return A.screens.section('accountability'); } },
    { hash: 'donate', icon: 'heart', screen: function () { return A.screens.section('donate'); } },
    { hash: 'contact', icon: 'gear', screen: function () { return A.screens.section('contact'); } },
    { hash: 'org', icon: 'gear', screen: function () { return A.screens.section('org'); } },
    { hash: 'sponsorsMeta', icon: 'star', screen: function () { return A.screens.section('sponsorsMeta'); } },
    { hash: 'signup', icon: 'people', label: 'Sign-up form', screen: function () { return A.screens.section('signup'); } },
    { group: 'Admin' },
    { hash: 'signups', icon: 'people', label: 'Supporters', screen: function () { return A.screens.signups; } },
    { hash: 'users', icon: 'people', label: 'People', screen: function () { return A.screens.users; } },
    { hash: 'audit', icon: 'list', label: 'History', screen: function () { return A.screens.audit; } },
    { hash: 'password', icon: 'gear', label: 'Your password', screen: function () { return A.screens.password; } }
  ];

  var app = {
    content: null,
    user: null,
    publish: null,
    current: null
  };

  function titleFor(route) {
    if (route.label) return route.label;
    if (route.hash === '') return 'Dashboard';
    if (A.fields.SECTIONS[route.hash]) return A.fields.SECTIONS[route.hash].title;
    if (A.fields.COLLECTIONS[route.hash]) return A.fields.COLLECTIONS[route.hash].title;
    return route.hash.charAt(0).toUpperCase() + route.hash.slice(1);
  }

  /* --------------------------------------------------------------- sidebar */
  function buildNav() {
    var el = A.ui.el;
    var nav = document.getElementById('nav');
    A.ui.clear(nav);
    ROUTES.forEach(function (route) {
      if (route.group) {
        nav.appendChild(el('p', { class: 'nav-group', text: route.group }));
        return;
      }
      nav.appendChild(el('a', {
        href: '#/' + route.hash,
        dataset: { route: route.hash }
      }, [A.ui.icon(route.icon), titleFor(route)]));
    });
  }

  function markActive(hash) {
    Array.prototype.forEach.call(document.querySelectorAll('#nav a'), function (link) {
      link.classList.toggle('is-active', link.dataset.route === hash);
    });
  }

  /* --------------------------------------------------------------- publish */
  async function refreshPublishState() {
    var badge = document.getElementById('publishState');
    var button = document.getElementById('publishBtn');
    if (!badge || !button) return;      // screen already torn down
    try {
      var status = await A.api.publishStatus();
      app.publish = status;
      badge.className = 'publish-state ' + (status.dirty ? 'dirty' : 'clean');
      badge.textContent = status.dirty ? 'Not published yet' : 'Live site is up to date';
      button.disabled = !status.dirty;
    } catch (err) {
      badge.className = 'publish-state dirty';
      badge.textContent = 'Cannot check';
      button.disabled = false;
    }
  }

  async function doPublish() {
    var button = document.getElementById('publishBtn');
    var target = (app.publish && app.publish.target) || 'file';
    var lines = ['This copies everything you have saved into the website’s content file, so the public site shows it.'];
    if (target === 'github') lines.push('It also commits that file to GitHub, and the live site rebuilds in about a minute.');
    if (target === 'file') lines.push('The site served from this same server updates straight away.');

    var yes = await A.ui.confirmDialog({
      title: 'Publish to the live site?',
      lines: lines,
      confirmLabel: 'Publish now'
    });
    if (!yes) return;

    button.disabled = true;
    button.textContent = 'Publishing…';
    try {
      var result = await A.api.publish();
      A.ui.toast(result.message || 'Published.', result.ok ? 'ok' : 'err', 8000);
      if (result.commitUrl) {
        A.ui.toast('Commit: ' + result.commitUrl, 'info', 12000);
      }
    } catch (err) {
      A.ui.toast(err.message, 'err');
    } finally {
      button.textContent = 'Publish';
      await refreshPublishState();
    }
  }

  /* ----------------------------------------------------------------- state */
  async function reload(options) {
    app.content = await A.api.content();
    if (!options || !options.quiet) A.api.setVersion(app.content.updatedAt);
    return app.content;
  }

  async function loadUser() {
    var response = await A.api.me();
    app.user = response.user;
    var name = document.getElementById('whoName');
    var role = document.getElementById('whoRole');
    if (name) name.textContent = app.user.displayName || app.user.username;
    if (role) role.textContent = app.user.username;
    return app.user;
  }

  function offerReload() {
    A.ui.confirmDialog({
      title: 'Someone else saved first',
      lines: [
        'Another person changed this while you were editing, so your change was not saved.',
        'Reload to see theirs, then apply your change again.'
      ],
      confirmLabel: 'Reload'
    }).then(function (yes) {
      if (yes) {
        reload().then(rerender).catch(function (err) {
          A.ui.toast(err.message || 'Could not reload.', 'err');
        });
      }
    }).catch(function () { /* the dialog was dismissed */ });
  }

  /* --------------------------------------------------------------- routing */
  function parseHash() {
    var raw = (window.location.hash || '#/').replace(/^#\/?/, '');
    var parts = raw.split('/').filter(Boolean);
    return { hash: parts[0] || '', id: parts[1] || null };
  }

  var rendering = false;

  async function render() {
    if (rendering) return;
    rendering = true;

    var root = document.getElementById('screen');
    var target = parseHash();
    var route = ROUTES.find(function (r) { return !r.group && r.hash === target.hash; });

    if (!route) {
      window.location.hash = '#/';
      rendering = false;
      return;
    }

    /* A password change that is still owed blocks every other screen, on the
       server as well as here, so send people straight to it. */
    if (app.user && app.user.mustChangePassword && target.hash !== 'password') {
      window.location.hash = '#/password';
      rendering = false;
      return;
    }

    markActive(target.hash);
    document.getElementById('screenTitle').textContent = titleFor(route);
    A.ui.clear(root);
    root.appendChild(A.ui.el('p', { class: 'loading', text: 'Loading…' }));

    try {
      var screen = route.screen();
      if (!app.content && target.hash !== 'password' && !(app.user && app.user.mustChangePassword)) {
        await reload();
      }
      A.ui.clear(root);
      await screen.render(root, app, target);
      app.current = target;
      root.focus && root.focus();
    } catch (err) {
      A.ui.clear(root);
      root.appendChild(A.ui.el('p', { class: 'banner banner-danger',
        text: 'This screen did not load: ' + (err.message || 'unknown problem') }));
      console.error(err);
    } finally {
      rendering = false;
    }
  }

  function rerender() { render(); }

  /* ------------------------------------------------------------------ boot */

  /* While somebody still owes a password change the server refuses every other
     admin request, which is exactly what it should do. Several of those
     requests are started in parallel during boot, so one can land after its
     caller has already moved on and end up as an unexplained console error.
     It is an expected condition, not a fault, so it is absorbed here by name.
     Anything else that goes unhandled is worth telling the person about. */
  window.addEventListener('unhandledrejection', function (event) {
    var reason = event.reason || {};
    if (reason.code === 'password_change_required' || reason.status === 401) {
      event.preventDefault();
      return;
    }
    if (reason.name === 'ApiError') {
      event.preventDefault();
      A.ui.toast(reason.message || 'Something went wrong.', 'err');
    }
  });

  async function boot() {
    Object.assign(app, { reload, rerender, refreshPublishState, offerReload, loadUser });

    buildNav();

    var toggle = document.getElementById('menuToggle');
    var nav = document.getElementById('nav');
    if (toggle && nav) {
      toggle.addEventListener('click', function () {
        var open = nav.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      nav.addEventListener('click', function (event) {
        if (event.target.closest('a')) {
          nav.classList.remove('is-open');
          toggle.setAttribute('aria-expanded', 'false');
        }
      });
    }

    document.getElementById('publishBtn').addEventListener('click', doPublish);
    document.getElementById('signOut').addEventListener('click', async function () {
      try { await A.api.signOut(); } catch (err) { /* going anyway */ }
      window.location.href = '../admin/login';
    });

    /* Ctrl/Cmd+S saves the form on screen, which is what everyone tries. */
    document.addEventListener('keydown', function (event) {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
        var form = document.querySelector('#screen form');
        if (form) {
          event.preventDefault();
          form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
        }
      }
    });

    window.addEventListener('hashchange', render);

    try {
      await loadUser();
    } catch (err) {
      /* api.js already redirects to the login page on a 401. */
      return;
    }

    /* Someone who still owes a password change is refused every admin request
       by design. Rather than firing them off and handling the refusals, skip
       them: the password screen needs none of it, and they run as soon as the
       change is done. */
    if (!app.user.mustChangePassword) {
      try {
        await reload();
      } catch (err) {
        A.ui.toast(err.message || 'The content could not be loaded.', 'err');
      }
    }

    await render();
    if (!app.user.mustChangePassword) await refreshPublishState();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
