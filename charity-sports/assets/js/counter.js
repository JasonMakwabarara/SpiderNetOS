/* The lives-helped counter: count-up, goal bar and milestone ladder. */
(function () {
  'use strict';
  var CS = (window.CS = window.CS || {});

  var state = { value: 0, goal: 1000000, started: false, observer: null };

  /** Count from `from` to `to` on requestAnimationFrame with an ease-out. */
  function animateNumber(node, from, to, onDone) {
    if (!node) return;
    var duration = Math.max(700, Math.min(350 + String(Math.round(to)).length * 250, 1800));

    if (CS.reducedMotion() || !window.requestAnimationFrame || from === to) {
      node.textContent = CS.fmtInt(to);
      if (onDone) onDone();
      return;
    }

    var start = null;
    function frame(timestamp) {
      if (start === null) start = timestamp;
      var progress = Math.min((timestamp - start) / duration, 1);
      var eased = 1 - Math.pow(1 - progress, 3);
      node.textContent = CS.fmtInt(from + (to - from) * eased);
      if (progress < 1) {
        window.requestAnimationFrame(frame);
      } else {
        node.textContent = CS.fmtInt(to);
        if (onDone) onDone();
      }
    }
    window.requestAnimationFrame(frame);
  }

  function paintBar(value, goal) {
    var bar = document.getElementById('counterBar');
    var fill = document.getElementById('counterFill');
    if (!bar || !fill) return;
    var pct = goal > 0 ? (value / goal) * 100 : 0;
    /* Keep a visible sliver: 20 out of a million is 0.002%, which would
       otherwise render as nothing at all. */
    var width = Math.max(1.5, Math.min(pct, 100));
    fill.style.setProperty('width', width + '%');
    bar.setAttribute('aria-valuenow', String(value));
    bar.setAttribute('aria-valuemax', String(goal));
    bar.setAttribute('aria-valuetext', CS.fmtInt(value) + ' of ' + CS.fmtInt(goal) + ' lives helped');
  }

  function paintMilestones(value, milestones) {
    var list = document.getElementById('milestones');
    if (!list) return;
    CS.clear(list);
    var nextMarked = false;
    (milestones || []).forEach(function (milestone) {
      var done = value >= milestone;
      var isNext = !done && !nextMarked;
      if (isNext) nextMarked = true;
      list.appendChild(CS.el('li', {
        class: done ? 'is-done' : (isNext ? 'is-next' : ''),
        text: CS.fmtInt(milestone) + (done ? ' reached' : (isNext ? ' — next' : ''))
      }));
    });
  }

  CS.counter = {
    /** Render everything except the animation, which waits for scroll. */
    render: function (data) {
      var impact = CS.get(data, 'impact', {}) || {};
      var goal = typeof impact.goal === 'number' ? impact.goal : 1000000;
      var value = typeof impact.livesHelped === 'number' ? impact.livesHelped : 0;
      var previous = state.value;
      state.goal = goal;

      var goalNode = document.getElementById('counterGoal');
      if (goalNode) goalNode.textContent = CS.fmtInt(goal);

      var updated = document.getElementById('counterUpdated');
      if (updated) {
        updated.textContent = impact.lastUpdated
          ? 'Last updated ' + CS.fmtDate(impact.lastUpdated, { weekday: undefined })
          : '';
      }

      var a11y = document.getElementById('counterA11y');
      if (a11y) a11y.textContent = CS.fmtInt(value) + ' lives helped so far, out of a goal of ' + CS.fmtInt(goal) + '.';

      paintMilestones(value, impact.milestones);

      var stats = document.getElementById('impactStats');
      if (stats) {
        CS.clear(stats);
        (impact.stats || []).forEach(function (stat) {
          stats.appendChild(CS.el('li', null, [
            CS.el('b', { text: String(stat.value || '') + (stat.suffix || '') }),
            CS.el('span', { text: stat.label || '' })
          ]));
        });
        stats.hidden = !(impact.stats && impact.stats.length);
      }

      /* A later update (live merge or remote override) animates on from
         whatever is currently on screen rather than snapping. */
      if (state.started && value !== previous) {
        state.value = value;
        animateNumber(document.getElementById('counterValue'), previous, value);
        animateNumber(document.getElementById('heroChipValue'), previous, value);
        paintBar(value, goal);
      } else if (!state.started) {
        state.value = value;
        paintBar(0, goal);
      }
    },

    init: function (data) {
      var impact = CS.get(data, 'impact', {}) || {};
      var value = typeof impact.livesHelped === 'number' ? impact.livesHelped : 0;
      var goal = typeof impact.goal === 'number' ? impact.goal : 1000000;
      state.value = value;
      state.goal = goal;

      var chip = document.getElementById('heroChip');
      var chipValue = document.getElementById('heroChipValue');
      if (chip && chipValue) {
        chip.hidden = false;
        animateNumber(chipValue, 0, value);
      }

      var target = document.getElementById('impact');
      var valueNode = document.getElementById('counterValue');
      if (!target || !valueNode) return;

      var run = function () {
        if (state.started) return;
        state.started = true;
        animateNumber(valueNode, 0, state.value);
        paintBar(state.value, state.goal);
      };

      if (!('IntersectionObserver' in window)) { run(); return; }

      state.observer = new IntersectionObserver(function (entries, observer) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            run();
            observer.disconnect();
          }
        });
      }, { threshold: 0.35 });
      state.observer.observe(target);

      /* Optional remote override, e.g. a JSON file the team updates by phone.
         Any failure at all leaves the built-in number showing. */
      if (impact.remoteUrl) {
        fetchRemote(impact.remoteUrl);
      }
    }
  };

  function fetchRemote(url) {
    if (!window.fetch || !window.AbortController) return;
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, 5000);
    fetch(url, { cache: 'no-store', signal: controller.signal })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (json) {
        var next = json && Number(json.livesHelped);
        if (!isFinite(next) || next < 0) return;
        var previous = state.value;
        state.value = next;
        if (state.started) {
          animateNumber(document.getElementById('counterValue'), previous, next);
          animateNumber(document.getElementById('heroChipValue'), previous, next);
        }
        paintBar(next, state.goal);
      })
      .catch(function () { /* built-in number stands */ })
      .then(function () { clearTimeout(timer); });
  }

  CS.animateNumber = animateNumber;
})();
