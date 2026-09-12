/* Causes: the ones open now as cards, the finished ones as an archive strip. */
(function () {
  'use strict';
  var CS = (window.CS = window.CS || {});

  function progressBlock(cause) {
    if (typeof cause.targetUsd !== 'number') {
      return CS.el('p', {
        class: 'cause-open',
        text: 'Open appeal — we send what we raise as we raise it, because the need is now.'
      });
    }

    var raised = typeof cause.raisedUsd === 'number' ? cause.raisedUsd : null;
    var wrap = CS.el('div', { class: 'cause-progress' });

    if (raised === null) {
      wrap.appendChild(CS.el('p', { class: 'cause-progress-meta' }, [
        CS.el('span', { text: 'Target' }),
        CS.el('b', { text: CS.fmtUsd(cause.targetUsd) })
      ]));
      return wrap;
    }

    var pct = cause.targetUsd > 0 ? (raised / cause.targetUsd) * 100 : 0;
    var bar = CS.el('div', {
      class: 'bar',
      role: 'progressbar',
      'aria-valuemin': '0',
      'aria-valuemax': String(cause.targetUsd),
      'aria-valuenow': String(raised),
      'aria-valuetext': CS.fmtUsd(raised) + ' raised of ' + CS.fmtUsd(cause.targetUsd)
    });
    var fill = CS.el('span', { class: 'bar-fill' });
    fill.style.setProperty('width', Math.max(1.5, Math.min(pct, 100)) + '%');
    bar.appendChild(fill);
    wrap.appendChild(bar);
    wrap.appendChild(CS.el('p', { class: 'cause-progress-meta' }, [
      CS.el('span', null, [CS.el('b', { text: CS.fmtUsd(raised) }), ' raised']),
      CS.el('span', null, ['of ', CS.el('b', { text: CS.fmtUsd(cause.targetUsd) })])
    ]));
    return wrap;
  }

  function card(cause, links) {
    var node = CS.el('article', {
      class: 'cause-card' + (cause.accent ? ' accent-' + cause.accent : ''),
      dataset: { causeId: cause.id || '', causeStatus: cause.status || 'current' }
    });

    if (cause.image && cause.image.src) {
      var media = CS.el('div', { class: 'cause-media' });
      var pic = CS.picture(cause.image, { sizes: '(min-width: 900px) 33vw, 100vw' });
      if (pic) media.appendChild(pic);
      node.appendChild(media);
    }

    var body = CS.el('div', { class: 'cause-body' });
    if (cause.tag) body.appendChild(CS.el('p', { class: 'cause-tag', text: cause.tag }));
    body.appendChild(CS.el('h3', { text: cause.title || '' }));
    if (cause.summary) body.appendChild(CS.el('p', { class: 'cause-summary', text: cause.summary }));

    if (Array.isArray(cause.details) && cause.details.length) {
      body.appendChild(CS.el('ul', { class: 'cause-details' }, cause.details.map(function (line) {
        return CS.el('li', { text: line });
      })));
    }

    if (Array.isArray(cause.stats) && cause.stats.length) {
      body.appendChild(CS.el('div', { class: 'cause-stats' }, cause.stats.map(function (stat) {
        return CS.el('div', null, [
          CS.el('b', { text: stat.value || '' }),
          CS.el('span', { text: stat.label || '' })
        ]);
      })));
    }

    body.appendChild(progressBlock(cause));

    var href = cause.cta && CS.resolveHref(cause.cta.href, links);
    if (href) {
      body.appendChild(CS.el('p', { class: 'cause-actions' }, [
        CS.link(href, { class: 'btn btn-green' }, cause.cta.label || 'Support this')
      ]));
    }

    node.appendChild(body);
    return node;
  }

  CS.causes = {
    render: function (data) {
      var grid = document.getElementById('causeGrid');
      var strip = document.getElementById('fundedStrip');
      var fundedList = document.getElementById('fundedList');
      if (!grid) return;

      var links = CS.buildLinks(data);
      var all = CS.activeOnly(CS.get(data, 'causes', []));
      var current = all.filter(function (c) { return (c.status || 'current') === 'current'; });
      var finished = all.filter(function (c) { return (c.status || 'current') !== 'current'; });

      CS.clear(grid);
      if (!current.length) {
        grid.appendChild(CS.el('p', {
          class: 'empty-note',
          text: 'No cause is open at the moment. The next one goes up as soon as it is chosen.'
        }));
      } else {
        current.forEach(function (cause) { grid.appendChild(card(cause, links)); });
      }

      if (strip && fundedList) {
        CS.clear(fundedList);
        finished.forEach(function (cause) {
          fundedList.appendChild(CS.el('li', null, [
            CS.el('b', { text: cause.title || '' }),
            CS.el('span', {
              text: [cause.period, typeof cause.targetUsd === 'number' ? CS.fmtUsd(cause.targetUsd) : null,
                     cause.status === 'funded' ? 'fully funded' : 'closed']
                .filter(Boolean).join(' · ')
            })
          ]));
        });
        strip.hidden = finished.length === 0;
      }
    },
    init: function (data) { CS.causes.render(data); }
  };
})();
