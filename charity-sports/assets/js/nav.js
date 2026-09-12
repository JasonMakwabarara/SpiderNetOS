/* Sticky header: mobile menu, active-section highlighting, back-to-top. */
(function () {
  'use strict';
  var CS = (window.CS = window.CS || {});

  CS.nav = {
    init: function () {
      var toggle = document.getElementById('navToggle');
      var nav = document.getElementById('primaryNav');
      var header = document.getElementById('siteHeader');
      var toTop = document.getElementById('toTop');
      if (!toggle || !nav) return;

      function close() {
        nav.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
      }
      function open() {
        nav.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
      }

      toggle.addEventListener('click', function () {
        if (toggle.getAttribute('aria-expanded') === 'true') close(); else open();
      });

      nav.addEventListener('click', function (event) {
        if (event.target.closest('a')) close();
      });

      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && nav.classList.contains('is-open')) {
          close();
          toggle.focus();
        }
      });

      document.addEventListener('click', function (event) {
        if (!nav.classList.contains('is-open')) return;
        if (nav.contains(event.target) || toggle.contains(event.target)) return;
        close();
      });

      window.addEventListener('resize', function () {
        if (window.innerWidth >= 900) close();
      });

      /* Highlight the section currently in view. */
      var links = Array.prototype.slice.call(nav.querySelectorAll('a[href^="#"]'));
      var targets = links
        .map(function (a) {
          var id = a.getAttribute('href').slice(1);
          var section = id && document.getElementById(id);
          return section ? { link: a, section: section } : null;
        })
        .filter(Boolean);

      if (targets.length && 'IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            var match = targets.find(function (t) { return t.section === entry.target; });
            if (!match) return;
            if (entry.isIntersecting) {
              targets.forEach(function (t) { t.link.classList.remove('is-active'); });
              match.link.classList.add('is-active');
            }
          });
        }, { rootMargin: '-40% 0px -55% 0px', threshold: 0 });
        targets.forEach(function (t) { observer.observe(t.section); });
      }

      /* Back to top, throttled through rAF. */
      if (toTop) {
        var ticking = false;
        var onScroll = function () {
          if (ticking) return;
          ticking = true;
          window.requestAnimationFrame(function () {
            toTop.hidden = window.scrollY < 600;
            if (header) header.classList.toggle('is-scrolled', window.scrollY > 12);
            ticking = false;
          });
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
        toTop.addEventListener('click', function () {
          window.scrollTo({ top: 0, behavior: CS.reducedMotion() ? 'auto' : 'smooth' });
        });
      }
    }
  };
})();
