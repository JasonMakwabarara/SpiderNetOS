/* Small DOM helpers, toasts and a confirm dialog. Text always goes in as
   text, so nothing typed into the panel can become markup. */
(function () {
  'use strict';
  var A = (window.ADMIN = window.ADMIN || {});

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (key) {
        var value = attrs[key];
        if (value === null || value === undefined || value === false) return;
        if (key === 'text') { node.textContent = String(value); return; }
        if (key === 'class') { node.className = String(value); return; }
        if (key === 'dataset') { Object.keys(value).forEach(function (d) { node.dataset[d] = String(value[d]); }); return; }
        if (key === 'on') { Object.keys(value).forEach(function (e) { node.addEventListener(e, value[e]); }); return; }
        if (key === 'value') { node.value = value; return; }
        if (value === true) { node.setAttribute(key, ''); return; }
        node.setAttribute(key, String(value));
      });
    }
    append(node, children);
    return node;
  }

  function append(parent, children) {
    if (children === null || children === undefined) return parent;
    if (Array.isArray(children)) { children.forEach(function (c) { append(parent, c); }); return parent; }
    parent.appendChild(children instanceof Node ? children : document.createTextNode(String(children)));
    return parent;
  }

  function clear(node) {
    while (node && node.firstChild) node.removeChild(node.firstChild);
    return node;
  }

  function icon(name, className) {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', className || 'icon');
    svg.setAttribute('aria-hidden', 'true');
    var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', '#i-' + name);
    svg.appendChild(use);
    return svg;
  }

  /* ---------------------------------------------------------------- toasts */
  function toast(message, tone, ms) {
    var host = document.getElementById('toasts');
    if (!host) return;
    var node = el('div', { class: 'toast ' + (tone || 'info') }, [
      el('span', { text: message }),
      el('button', { type: 'button', 'aria-label': 'Dismiss', text: '×', on: { click: remove } })
    ]);
    host.appendChild(node);
    var timer = setTimeout(remove, ms || (tone === 'err' ? 9000 : 4500));
    function remove() { clearTimeout(timer); if (node.parentNode) node.parentNode.removeChild(node); }
  }

  /* --------------------------------------------------------------- confirm */
  function confirmDialog(options) {
    var dialog = document.getElementById('dialog');
    var title = document.getElementById('dialogTitle');
    var body = document.getElementById('dialogBody');
    var confirmBtn = document.getElementById('dialogConfirm');
    var cancelBtn = document.getElementById('dialogCancel');
    if (!dialog || !dialog.showModal) {
      return Promise.resolve(window.confirm(options.title || 'Are you sure?'));
    }

    title.textContent = options.title || 'Are you sure?';
    clear(body);
    (Array.isArray(options.lines) ? options.lines : [options.body]).forEach(function (line) {
      if (line) body.appendChild(el('p', { text: line }));
    });
    confirmBtn.textContent = options.confirmLabel || 'Yes, do it';
    confirmBtn.className = 'btn ' + (options.tone === 'danger' ? 'btn-danger' : 'btn-primary');
    cancelBtn.textContent = options.cancelLabel || 'Cancel';
    cancelBtn.hidden = !!options.acknowledge;

    return new Promise(function (resolve) {
      function done() {
        dialog.removeEventListener('close', done);
        resolve(dialog.returnValue === 'confirm');
      }
      dialog.addEventListener('close', done);
      dialog.showModal();
      (options.acknowledge ? confirmBtn : cancelBtn).focus();
    });
  }

  /** A one-off secret, shown once, with a copy button. */
  function showSecret(options) {
    var dialog = document.getElementById('dialog');
    var body = document.getElementById('dialogBody');
    var title = document.getElementById('dialogTitle');
    title.textContent = options.title;
    clear(body);
    (options.lines || []).forEach(function (line) { body.appendChild(el('p', { text: line })); });
    body.appendChild(el('code', { class: 'secret', text: options.secret }));
    body.appendChild(el('p', null, [
      el('button', {
        class: 'btn btn-quiet btn-sm', type: 'button', text: 'Copy to clipboard',
        on: {
          click: function () {
            if (navigator.clipboard) {
              navigator.clipboard.writeText(options.secret)
                .then(function () { toast('Copied.', 'ok'); })
                .catch(function () { toast('Could not copy. Select it and copy by hand.', 'err'); });
            } else {
              toast('Select the text above and copy it by hand.', 'info');
            }
          }
        }
      })
    ]));
    document.getElementById('dialogConfirm').textContent = 'I have saved it';
    document.getElementById('dialogConfirm').className = 'btn btn-primary';
    document.getElementById('dialogCancel').hidden = true;
    return new Promise(function (resolve) {
      function done() { dialog.removeEventListener('close', done); resolve(true); }
      dialog.addEventListener('close', done);
      dialog.showModal();
    });
  }

  /* --------------------------------------------------------------- helpers */
  function fmtInt(n) {
    if (typeof n !== 'number' || !isFinite(n)) return '0';
    try { return new Intl.NumberFormat('en-US').format(Math.round(n)); }
    catch (err) { return String(Math.round(n)); }
  }

  function fmtDateTime(iso) {
    if (!iso) return '—';
    var d = new Date(iso);
    if (isNaN(d.getTime())) return String(iso);
    try {
      return new Intl.DateTimeFormat('en-GB', {
        day: 'numeric', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
        timeZone: 'Africa/Harare'
      }).format(d);
    } catch (err) { return d.toISOString().slice(0, 16).replace('T', ' '); }
  }

  function fmtDate(value) {
    if (!value) return '—';
    try {
      var parts = String(value).split('-');
      return new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })
        .format(new Date(Date.UTC(+parts[0], +parts[1] - 1, +parts[2])));
    } catch (err) { return String(value); }
  }

  /** A save button that shows its own progress and result inline. */
  function saveButton(label, onSave) {
    var state = el('span', { class: 'save-state' });
    var button = el('button', { class: 'btn btn-primary', type: 'submit', text: label || 'Save' });
    var timer = null;

    function show(text, tone) {
      clearTimeout(timer);
      state.textContent = text;
      state.className = 'save-state ' + (tone || '');
      if (tone === 'ok') timer = setTimeout(function () { state.textContent = ''; }, 4000);
    }

    return {
      button: button,
      state: state,
      node: el('div', { class: 'form-actions' }, [button, state]),
      async run(fn) {
        button.disabled = true;
        show('Saving…');
        try {
          var result = await fn();
          show('Saved ' + new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }), 'ok');
          return result;
        } catch (err) {
          show(err.status === 409 ? 'Someone else saved first' : 'Not saved', 'err');
          throw err;
        } finally {
          button.disabled = false;
        }
      },
      setLabel(text) { button.textContent = text; },
      show: show
    };
  }

  A.ui = { el, append, clear, icon, toast, confirmDialog, showSecret, fmtInt, fmtDateTime, fmtDate, saveButton };
})();
