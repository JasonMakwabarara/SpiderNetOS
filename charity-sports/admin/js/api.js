/* One place where the admin talks to the server.
   Attaches the CSRF token, carries If-Match, and turns every failure into
   something a screen can show without knowing about HTTP. */
(function () {
  'use strict';
  var A = (window.ADMIN = window.ADMIN || {});

  var BASE = '../api';
  var state = { updatedAt: null, user: null };

  function csrfToken() {
    var match = /(?:^|;\s*)cs_csrf=([^;]+)/.exec(document.cookie);
    return match ? decodeURIComponent(match[1]) : '';
  }

  function ensureCsrf() {
    if (csrfToken()) return Promise.resolve(csrfToken());
    return fetch(BASE + '/csrf', { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (body) { return body.token; });
  }

  /** Thrown for anything the caller might want to react to specifically. */
  function ApiError(status, body) {
    this.name = 'ApiError';
    this.status = status;
    this.body = body || {};
    this.code = this.body.error || 'error';
    this.errors = this.body.errors || null;
    this.message = this.body.message ||
      (this.errors ? 'Some fields need attention.' : 'Something went wrong.');
  }
  ApiError.prototype = Object.create(Error.prototype);

  function request(method, path, options) {
    var opts = options || {};
    var init = {
      method: method,
      credentials: 'same-origin',
      headers: Object.assign({ Accept: 'application/json' }, opts.headers || {})
    };

    if (opts.body instanceof FormData) {
      init.body = opts.body;
    } else if (opts.body !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(opts.body);
    }

    var prepare = method === 'GET' ? Promise.resolve() : ensureCsrf().then(function (token) {
      init.headers['X-CSRF-Token'] = token;
      if (opts.ifMatch !== false && state.updatedAt) init.headers['If-Match'] = state.updatedAt;
    });

    return prepare
      .then(function () { return fetch(BASE + path, init); })
      .then(function (res) {
        if (res.status === 204) return null;
        var type = res.headers.get('content-type') || '';
        var payload = type.indexOf('application/json') === 0 ? res.json() : res.text();
        return payload.then(function (body) {
          if (res.ok) {
            if (body && body.updatedAt) state.updatedAt = body.updatedAt;
            return body;
          }

          /* A stale CSRF token is worth exactly one silent retry: it happens
             whenever a tab has been left open a long time. */
          if (res.status === 403 && body && body.error === 'csrf' && !opts._retried) {
            document.cookie = 'cs_csrf=; Max-Age=0; path=/';
            return ensureCsrf().then(function () {
              return request(method, path, Object.assign({}, opts, { _retried: true }));
            });
          }

          if (res.status === 401) {
            window.location.href = '../admin/login';
            return Promise.reject(new ApiError(401, body));
          }
          if (res.status === 403 && body && body.error === 'password_change_required') {
            if (window.location.hash !== '#/password') window.location.hash = '#/password';
          }
          throw new ApiError(res.status, body);
        });
      }, function () {
        throw new ApiError(0, { message: 'Could not reach the server. Check your connection.' });
      });
  }

  A.api = {
    ApiError: ApiError,
    get state() { return state; },
    setVersion: function (updatedAt) { state.updatedAt = updatedAt || null; },

    me: function () { return request('GET', '/auth/me'); },
    signOut: function () { return request('POST', '/auth/logout', { body: {} }); },
    changePassword: function (currentPassword, newPassword) {
      return request('POST', '/auth/password', { body: { currentPassword: currentPassword, newPassword: newPassword } });
    },

    content: function () {
      return request('GET', '/admin/content').then(function (body) {
        state.updatedAt = body.updatedAt;
        return body;
      });
    },
    saveSection: function (section, value) {
      return request('PUT', '/admin/content/' + encodeURIComponent(section), { body: { value: value } });
    },
    createItem: function (collection, value) {
      return request('POST', '/admin/' + encodeURIComponent(collection), { body: { value: value } });
    },
    saveItem: function (collection, id, value) {
      return request('PUT', '/admin/' + encodeURIComponent(collection) + '/' + encodeURIComponent(id), { body: { value: value } });
    },
    deleteItem: function (collection, id) {
      return request('DELETE', '/admin/' + encodeURIComponent(collection) + '/' + encodeURIComponent(id));
    },
    reorder: function (collection, ids) {
      return request('POST', '/admin/' + encodeURIComponent(collection) + '/reorder', { body: { ids: ids } });
    },

    users: function () { return request('GET', '/admin/users'); },
    createUser: function (username, displayName) {
      return request('POST', '/admin/users', { body: { username: username, displayName: displayName } });
    },
    updateUser: function (id, patch) { return request('PUT', '/admin/users/' + encodeURIComponent(id), { body: patch }); },
    resetUserPassword: function (id) { return request('POST', '/admin/users/' + encodeURIComponent(id) + '/reset-password', { body: {} }); },
    deleteUser: function (id) { return request('DELETE', '/admin/users/' + encodeURIComponent(id)); },

    audit: function (before) { return request('GET', '/admin/audit?limit=50&before=' + (before || 0)); },

    publishStatus: function () { return request('GET', '/admin/publish/status'); },
    publish: function () { return request('POST', '/admin/publish', { body: {} }); },

    /** Upload with progress, which fetch cannot report. */
    upload: function (file, kind, onProgress) {
      return ensureCsrf().then(function (token) {
        return new Promise(function (resolve, reject) {
          var form = new FormData();
          form.append('kind', kind);
          form.append('file', file);

          var xhr = new XMLHttpRequest();
          xhr.open('POST', BASE + '/admin/uploads');
          xhr.withCredentials = true;
          xhr.setRequestHeader('X-CSRF-Token', token);
          xhr.upload.addEventListener('progress', function (event) {
            if (onProgress && event.lengthComputable) onProgress(event.loaded / event.total);
          });
          xhr.addEventListener('load', function () {
            var body = {};
            try { body = JSON.parse(xhr.responseText); } catch (err) { /* not json */ }
            if (xhr.status >= 200 && xhr.status < 300) resolve(body);
            else reject(new ApiError(xhr.status, body));
          });
          xhr.addEventListener('error', function () {
            reject(new ApiError(0, { message: 'The upload did not reach the server.' }));
          });
          xhr.send(form);
        });
      });
    }
  };
})();
