/* Two ways to help that are not money: passing the link on, and leaving a
   name so the next event reaches you. */
(function () {
  'use strict';
  var CS = (window.CS = window.CS || {});

  /* --------------------------------------------------------------- sharing */

  function shareUrl() {
    /* The canonical address when there is one, so a shared link does not carry
       whatever query string the sender happened to have. */
    var canonical = document.querySelector('link[rel="canonical"]');
    if (canonical && canonical.href) return canonical.href;
    return window.location.href.split('#')[0].split('?')[0];
  }

  function initShare(data) {
    var block = CS.get(data, 'share', {}) || {};
    var message = block.message || '';
    var label = block.label || 'Share this';
    var buttons = document.querySelectorAll('[data-share]');
    if (!buttons.length) return;

    var url = shareUrl();
    var text = message ? message + ' ' + url : url;
    var whatsapp = 'https://wa.me/?text=' + encodeURIComponent(text);

    Array.prototype.forEach.call(buttons, function (button) {
      var labelNode = button.querySelector('[data-share-label]');
      if (labelNode) labelNode.textContent = label;
      button.hidden = false;

      button.addEventListener('click', function () {
        /* Where the operating system offers a share sheet, use it: the sender
           picks WhatsApp, or anything else they actually use. */
        if (navigator.share) {
          navigator.share({
            title: CS.get(data, 'org.name', 'Charity Sports'),
            text: message,
            url: url
          }).catch(function () { /* dismissed, which is not an error */ });
          return;
        }
        window.open(whatsapp, '_blank', 'noopener');
      });
    });
  }

  /* -------------------------------------------------------------- sign-ups */

  function initSignup(data) {
    var section = document.getElementById('signup');
    var form = document.getElementById('signupForm');
    var fallback = document.getElementById('signupFallback');
    var state = document.getElementById('signupState');
    var submit = document.getElementById('signupSubmit');
    if (!section || !form) return;

    var block = CS.get(data, 'signup', {}) || {};
    var links = CS.buildLinks(data);

    /* On a static host there is nowhere to put a name, so the form is replaced
       by the WhatsApp link, which always works. The check is cheap: ask the
       API whether it is there. */
    function showFallback() {
      form.hidden = true;
      if (!fallback) { section.hidden = true; return; }
      CS.clear(fallback);
      var href = links.whatsapp('default');
      if (!href) { section.hidden = true; return; }
      fallback.appendChild(CS.link(href, { class: 'btn btn-primary' },
        block.fallbackLabel || 'Message us on WhatsApp'));
      fallback.hidden = false;
      section.hidden = false;
    }

    function showForm() {
      form.hidden = false;
      if (fallback) fallback.hidden = true;
      section.hidden = false;
    }

    function clearErrors() {
      Array.prototype.forEach.call(form.querySelectorAll('[data-error-for]'), function (node) {
        node.textContent = '';
        node.hidden = true;
        var field = node.closest('.field');
        if (field) field.classList.remove('has-error');
      });
    }

    function showError(name, message) {
      var node = form.querySelector('[data-error-for="' + name + '"]');
      if (!node) { if (state) state.textContent = message; return; }
      node.textContent = message;
      node.hidden = false;
      var field = node.closest('.field');
      if (field) field.classList.add('has-error');
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      clearErrors();
      if (state) { state.textContent = ''; state.className = 'signup-state'; }
      submit.disabled = true;

      var body = {
        name: form.elements.name.value,
        contact: form.elements.contact.value,
        website: form.elements.website.value,
        source: 'website'
      };

      fetch('api/signup', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body)
      })
        .then(function (res) {
          return res.json().catch(function () { return {}; })
            .then(function (payload) { return { status: res.status, payload: payload }; });
        })
        .then(function (result) {
          if (result.status === 201 || result.status === 200) {
            form.hidden = true;
            if (state) {
              state.textContent = block.successText || 'Thank you. We will be in touch.';
              state.className = 'signup-state is-ok';
            }
            return;
          }
          if (result.status === 422 && result.payload.errors) {
            result.payload.errors.forEach(function (error) { showError(error.path, error.message); });
            return;
          }
          if (state) {
            state.textContent = result.payload.message || 'That did not go through. Try WhatsApp instead.';
            state.className = 'signup-state is-err';
          }
        })
        .catch(function () {
          if (state) {
            state.textContent = 'Could not reach us just now. Try WhatsApp instead.';
            state.className = 'signup-state is-err';
          }
        })
        .then(function () { submit.disabled = false; });
    });

    /* file:// has no server to ask, so do not try. */
    if (window.location.protocol === 'file:' || !window.fetch) { showFallback(); return; }

    var options = { method: 'GET', cache: 'no-store' };
    if (window.AbortSignal && AbortSignal.timeout) {
      try { options.signal = AbortSignal.timeout(2500); } catch (err) { /* older browser */ }
    }
    fetch('api/health', options)
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (payload) {
        if (payload && payload.ok) showForm(); else showFallback();
      })
      .catch(showFallback);
  }

  CS.engage = {
    init: function (data) {
      initShare(data);
      initSignup(data);
    },
    render: function (data) { initShare(data); },
    _shareUrl: shareUrl
  };
})();
