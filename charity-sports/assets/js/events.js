/* Events: featured card, countdown, upcoming list and a collapsed past list.
   All dates carry Harare time explicitly, so nothing depends on the visitor's
   own clock zone and no timezone library is needed. */
(function () {
  'use strict';
  var CS = (window.CS = window.CS || {});

  var timer = null;

  /* ------------------------------------------------------------ normalising */

  /** Every date an event actually happens on, as {date, startTime, endTime,
      label, final, start, end}. An event with no dates returns []. */
  function occurrences(event) {
    var rows = [];
    if (Array.isArray(event.sessions) && event.sessions.length) {
      event.sessions.forEach(function (session) {
        if (!session || !session.date) return;
        rows.push(buildOccurrence(
          session.date,
          session.startTime !== undefined ? session.startTime : event.startTime,
          session.endTime !== undefined ? session.endTime : event.endTime,
          session.label,
          session.final
        ));
      });
    } else if (event.startDate) {
      rows.push(buildOccurrence(event.startDate, event.startTime, event.endTime, null, false));
    }
    return rows.filter(Boolean).sort(function (a, b) { return a.start - b.start; });
  }

  function buildOccurrence(date, startTime, endTime, label, final) {
    var start = CS.harareInstant(date, startTime);
    if (!start) return null;
    var end = endTime ? CS.harareInstant(date, endTime) : CS.endOfHarareDay(date);
    return {
      date: date,
      startTime: startTime || null,
      endTime: endTime || null,
      label: label || null,
      final: !!final,
      start: start,
      end: end || start
    };
  }

  function occurrenceStatus(row, now) {
    if (row.end < now) return 'past';
    if (row.start <= now && now <= row.end) return 'live';
    return 'upcoming';
  }

  /** "tbc" (announced, no date), "past", "live" or "upcoming". */
  function eventStatus(event, rows, now) {
    if (!rows.length) return 'tbc';
    if (rows.some(function (r) { return occurrenceStatus(r, now) === 'live'; })) return 'live';
    if (rows.every(function (r) { return occurrenceStatus(r, now) === 'past'; })) return 'past';
    return 'upcoming';
  }

  function nextOccurrence(rows, now) {
    return rows.filter(function (r) { return r.end >= now; })[0] || null;
  }

  /* ---------------------------------------------------------------- pieces */

  function badges(event, status) {
    var row = CS.el('div', { class: 'event-badges' });
    if (event.sport) row.appendChild(CS.el('span', { class: 'badge badge-sport', text: event.sport }));
    if (status === 'live') row.appendChild(CS.el('span', { class: 'badge badge-live', text: 'On now' }));
    if (status === 'upcoming') row.appendChild(CS.el('span', { class: 'badge badge-next', text: 'Upcoming' }));
    if (status === 'tbc') row.appendChild(CS.el('span', { class: 'badge badge-tbc', text: event.dateNote || 'Date to be announced' }));
    if (status === 'past') row.appendChild(CS.el('span', { class: 'badge badge-past', text: 'Finished' }));
    return row;
  }

  function metaList(event, rows, status) {
    var list = CS.el('ul', { class: 'event-meta' });

    if (rows.length) {
      var first = rows[0], last = rows[rows.length - 1];
      var whenText = first.date === last.date
        ? CS.fmtDate(first.date)
        : CS.fmtDate(first.date, { weekday: undefined }) + ' – ' + CS.fmtDate(last.date, { weekday: undefined });
      list.appendChild(CS.el('li', null, [CS.icon('calendar'), CS.el('span', { text: whenText })]));

      var timeText = CS.fmtTimeRange(event.startTime, event.endTime);
      if (timeText) list.appendChild(CS.el('li', null, [CS.icon('clock'), CS.el('span', { text: timeText })]));
    } else {
      list.appendChild(CS.el('li', null, [
        CS.icon('calendar'),
        CS.el('span', { text: event.dateNote || 'Date to be announced' })
      ]));
    }

    if (event.venue && event.venue.name) {
      var venueText = event.venue.name + (event.venue.address ? ', ' + event.venue.address : '');
      var venueNode = event.venue.mapsUrl
        ? CS.link(event.venue.mapsUrl, null, venueText)
        : CS.el('span', { text: venueText });
      list.appendChild(CS.el('li', null, [CS.icon('pin'), venueNode]));
    }

    return list;
  }

  function actions(event, links) {
    var list = (event.ctas || []).map(function (cta, index) {
      var href = CS.resolveHref(cta.href, links);
      if (!href) return null;
      return CS.link(href, { class: 'btn ' + (index === 0 ? 'btn-primary' : 'btn-ghost') }, cta.label || 'More');
    }).filter(Boolean);
    if (!list.length) return null;
    return CS.el('div', { class: 'event-actions' }, list);
  }

  function schedule(rows, now) {
    if (rows.length < 2) return null;
    var next = nextOccurrence(rows, now);
    return CS.el('ol', { class: 'schedule' }, rows.map(function (row) {
      var status = occurrenceStatus(row, now);
      var isNext = next && row.start === next.start;
      var item = CS.el('li', {
        class: status === 'past' ? 'is-past' : (isNext ? 'is-next' : ''),
        dataset: { status: status }
      });
      item.appendChild(CS.el('span', { class: 'schedule-date', text: CS.fmtDate(row.date, { year: undefined }) }));
      item.appendChild(CS.el('span', {
        class: 'schedule-time',
        text: row.startTime ? CS.fmtTimeRange(row.startTime, row.endTime) : 'Time to be confirmed'
      }));
      if (row.label) {
        item.appendChild(CS.el('span', {
          class: 'badge ' + (row.final ? 'badge-final' : (status === 'past' ? 'badge-past' : 'badge-next')),
          text: row.label
        }));
      }
      if (status === 'past') item.appendChild(CS.el('span', { class: 'badge badge-past', text: 'Played' }));
      return item;
    }));
  }

  function countdownBlock(target) {
    var wrap = CS.el('div', { class: 'countdown', dataset: { target: String(target.getTime()) } });
    wrap.appendChild(CS.el('p', { class: 'countdown-label', text: 'Next session starts in' }));
    var units = CS.el('div', { class: 'countdown-units', 'aria-hidden': 'true' });
    ['days', 'hours', 'minutes', 'seconds'].forEach(function (unit) {
      units.appendChild(CS.el('div', null, [
        CS.el('b', { dataset: { unit: unit }, text: '0' }),
        CS.el('span', { text: unit })
      ]));
    });
    wrap.appendChild(units);
    wrap.appendChild(CS.el('p', { class: 'visually-hidden', text: 'The next session starts on ' + target.toUTCString() + '.' }));
    return wrap;
  }

  function tickCountdowns() {
    var nodes = document.querySelectorAll('.countdown[data-target]');
    if (!nodes.length) return;
    var now = CS.now().getTime();
    Array.prototype.forEach.call(nodes, function (node) {
      var diff = Number(node.dataset.target) - now;
      if (diff < 0) diff = 0;
      var seconds = Math.floor(diff / 1000);
      var values = {
        days: Math.floor(seconds / 86400),
        hours: Math.floor((seconds % 86400) / 3600),
        minutes: Math.floor((seconds % 3600) / 60),
        seconds: seconds % 60
      };
      Object.keys(values).forEach(function (unit) {
        var slot = node.querySelector('[data-unit="' + unit + '"]');
        if (slot) slot.textContent = String(values[unit]);
      });
    });
  }

  /* ----------------------------------------------------------------- cards */

  function featuredCard(event, rows, status, now, links) {
    var node = CS.el('article', { class: 'event-featured', dataset: { eventId: event.id || '', status: status } });

    if (event.poster && event.poster.src) {
      var media = CS.el('div', { class: 'event-poster' });
      var pic = CS.picture(event.poster, { sizes: '(min-width: 900px) 40vw, 100vw' });
      if (pic) media.appendChild(pic);
      node.appendChild(media);
    }

    var body = CS.el('div', { class: 'event-featured-body' });
    body.appendChild(badges(event, status));
    body.appendChild(CS.el('h3', { text: event.title || '' }));
    if (event.summary) body.appendChild(CS.el('p', { text: event.summary }));
    body.appendChild(metaList(event, rows, status));

    var next = nextOccurrence(rows, now);
    if (next && occurrenceStatus(next, now) === 'upcoming') body.appendChild(countdownBlock(next.start));

    var sched = schedule(rows, now);
    if (sched) body.appendChild(sched);

    if (Array.isArray(event.highlights) && event.highlights.length) {
      body.appendChild(CS.el('ul', { class: 'event-highlights' }, event.highlights.map(function (h) {
        return CS.el('li', { text: h });
      })));
    }

    var acts = actions(event, links);
    if (acts) body.appendChild(acts);
    if (event.note) body.appendChild(CS.el('p', { class: 'event-note', text: event.note }));

    node.appendChild(body);
    return node;
  }

  function listCard(event, rows, status, links) {
    var node = CS.el('article', {
      class: 'event-card' + (status === 'tbc' ? ' is-tbc' : ''),
      dataset: { eventId: event.id || '', status: status }
    });
    node.appendChild(badges(event, status));
    node.appendChild(CS.el('h3', { text: event.title || '' }));
    if (event.summary) node.appendChild(CS.el('p', { text: event.summary }));
    node.appendChild(metaList(event, rows, status));
    if (Array.isArray(event.highlights) && event.highlights.length) {
      node.appendChild(CS.el('ul', { class: 'event-highlights' }, event.highlights.map(function (h) {
        return CS.el('li', { text: h });
      })));
    }
    var acts = actions(event, links);
    if (acts) node.appendChild(acts);
    if (event.note) node.appendChild(CS.el('p', { class: 'event-note', text: event.note }));
    return node;
  }

  /* ---------------------------------------------------------------- render */

  CS.events = {
    render: function (data) {
      var featuredSlot = document.getElementById('featuredEvent');
      var listSlot = document.getElementById('eventList');
      var pastWrap = document.getElementById('pastEvents');
      var pastSlot = document.getElementById('pastEventList');
      var pastLabel = document.getElementById('pastEventsLabel');
      if (!featuredSlot || !listSlot) return;

      var links = CS.buildLinks(data);
      var now = CS.now();
      var all = CS.activeOnly(CS.get(data, 'events', [])).map(function (event) {
        var rows = occurrences(event);
        return { event: event, rows: rows, status: eventStatus(event, rows, now) };
      });

      var live = all.filter(function (e) { return e.status === 'live'; });
      var upcoming = all.filter(function (e) { return e.status === 'upcoming'; });
      var tbc = all.filter(function (e) { return e.status === 'tbc'; });
      var past = all.filter(function (e) { return e.status === 'past'; });

      /* Dated events first, in date order, then the announced-but-undated ones. */
      var active = live.concat(upcoming).sort(function (a, b) {
        var an = nextOccurrence(a.rows, now), bn = nextOccurrence(b.rows, now);
        return (an ? an.start : 0) - (bn ? bn.start : 0);
      }).concat(tbc);

      var featured = active.filter(function (e) { return e.event.featured; })[0] || active[0] || null;

      CS.clear(featuredSlot);
      CS.clear(listSlot);
      if (timer) { clearInterval(timer); timer = null; }

      if (featured) {
        featuredSlot.appendChild(featuredCard(featured.event, featured.rows, featured.status, now, links));
      }

      var rest = active.filter(function (e) { return e !== featured; });
      if (rest.length) {
        listSlot.appendChild(CS.el('div', { class: 'event-list' }, rest.map(function (e) {
          return listCard(e.event, e.rows, e.status, links);
        })));
      }

      if (!active.length) {
        listSlot.appendChild(CS.el('div', { class: 'empty-note' }, [
          CS.el('p', { text: 'Nothing is scheduled at the moment. The next event goes up here as soon as it is set.' }),
          CS.el('p', null, [
            CS.link(links.whatsapp('default'), { class: 'btn btn-primary btn-sm' }, 'Tell me when there is one')
          ])
        ]));
      }

      if (pastWrap && pastSlot) {
        CS.clear(pastSlot);
        past.forEach(function (e) {
          pastSlot.appendChild(listCard(e.event, e.rows, 'past', links));
        });
        pastWrap.hidden = past.length === 0;
        if (pastLabel) {
          pastLabel.textContent = past.length === 1 ? '1 past event' : past.length + ' past events';
        }
        /* If nothing has a date on it, open the archive rather than leave the
           page looking empty. An announced-but-undated event does not count as
           something to wait for. */
        var dated = live.length + upcoming.length;
        if (!dated && past.length) pastWrap.open = true;
      }

      tickCountdowns();
      if (document.querySelector('.countdown[data-target]')) {
        timer = setInterval(tickCountdowns, 1000);
      }
    },

    init: function (data) { CS.events.render(data); },

    /* exposed for the verification script */
    _occurrences: occurrences,
    _eventStatus: eventStatus
  };
})();
