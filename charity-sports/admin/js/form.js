/* Turns a field spec into a form, and a form back into a value.
   Errors from the server are painted onto the field whose path they name. */
(function () {
  'use strict';
  var A = (window.ADMIN = window.ADMIN || {});
  var el, clear, icon;

  function init() { el = A.ui.el; clear = A.ui.clear; icon = A.ui.icon; }

  function labelFor(spec, id) {
    return el('label', { for: id, text: spec.label + (spec.required ? ' *' : '') });
  }

  function wrap(spec, id, control, extras) {
    var field = el('div', { class: 'field', dataset: { field: spec.key } });
    field.appendChild(labelFor(spec, id));
    field.appendChild(control);
    (extras || []).forEach(function (node) { field.appendChild(node); });
    if (spec.hint) field.appendChild(el('span', { class: 'hint', text: spec.hint }));
    field.appendChild(el('span', { class: 'error', hidden: true }));
    return field;
  }

  /** Live character count, so a limit is visible before the server enforces it. */
  function counter(input, max) {
    if (!max) return null;
    var node = el('span', { class: 'count' });
    function tick() {
      var length = (input.value || '').length;
      node.textContent = length + ' / ' + max;
      node.className = 'count' + (length > max ? ' over' : '');
    }
    input.addEventListener('input', tick);
    tick();
    return node;
  }

  function uid(prefix) { return prefix + '-' + Math.random().toString(36).slice(2, 9); }

  /* ------------------------------------------------------------ one field */

  function build(spec, value, ctx) {
    var id = uid('f');

    if (spec.type === 'checkbox') {
      var box = el('input', { type: 'checkbox', id: id });
      box.checked = value !== false;
      var row = el('div', { class: 'field-check', dataset: { field: spec.key } }, [
        box, el('label', { for: id, text: spec.label })
      ]);
      if (spec.hint) row.appendChild(el('span', { class: 'hint', text: spec.hint }));
      return { node: row, read: function () { return box.checked; }, focus: function () { box.focus(); } };
    }

    if (spec.type === 'select') {
      var options = spec.optionsFrom && ctx && ctx[spec.optionsFrom]
        ? ctx[spec.optionsFrom]
        : (spec.options || []);
      var select = el('select', { id: id });
      if (spec.allowEmpty) select.appendChild(el('option', { value: '', text: '—' }));
      options.forEach(function (option) {
        var val = typeof option === 'string' ? option : option.value;
        var text = typeof option === 'string' ? option : option.label;
        select.appendChild(el('option', { value: val, text: text }));
      });
      select.value = value === null || value === undefined ? (spec.allowEmpty ? '' : options[0] && (options[0].value || options[0])) : value;
      return {
        node: wrap(spec, id, select),
        read: function () { return select.value === '' ? null : select.value; },
        focus: function () { select.focus(); }
      };
    }

    if (spec.type === 'textarea') {
      var area = el('textarea', { id: id, rows: spec.rows || 3 });
      area.value = value === null || value === undefined ? '' : String(value);
      return {
        node: wrap(spec, id, area, [counter(area, spec.max)].filter(Boolean)),
        read: function () { return area.value.trim() || null; },
        focus: function () { area.focus(); }
      };
    }

    if (spec.type === 'number') {
      var number = el('input', { type: 'number', id: id, min: spec.min, max: spec.max, step: 1 });
      number.value = value === null || value === undefined ? '' : String(value);
      var extras = [];
      if (spec.stepper) {
        var stepper = el('span', { class: 'stepper' });
        spec.stepper.forEach(function (step) {
          stepper.appendChild(el('button', {
            class: 'btn btn-quiet btn-sm', type: 'button', text: '+' + step,
            on: { click: function () {
              number.value = String((Number(number.value) || 0) + step);
              number.dispatchEvent(new Event('input', { bubbles: true }));
            } }
          }));
        });
        extras.push(stepper);
      }
      return {
        node: wrap(spec, id, number, extras),
        read: function () { return number.value === '' ? null : Number(number.value); },
        focus: function () { number.focus(); },
        input: number
      };
    }

    if (spec.type === 'date' || spec.type === 'time') {
      var picker = el('input', { type: spec.type, id: id });
      picker.value = value || '';
      var buttons = [];
      if (spec.today) {
        buttons.push(el('button', {
          class: 'btn btn-quiet btn-sm', type: 'button', text: 'Today',
          on: { click: function () { picker.value = harareToday(); } }
        }));
      }
      return {
        node: wrap(spec, id, picker, buttons),
        read: function () { return picker.value || null; },
        focus: function () { picker.focus(); }
      };
    }

    if (spec.type === 'group') {
      var groupCtx = [];
      var box2 = el('div', { class: 'card', dataset: { field: spec.key } }, [el('h3', { text: spec.label })]);
      (spec.fields || []).forEach(function (child) {
        var built = build(child, (value || {})[child.key], ctx);
        built.key = child.key;
        groupCtx.push(built);
        box2.appendChild(built.node);
      });
      return {
        node: box2,
        children: groupCtx,
        read: function () {
          var out = {};
          var empty = true;
          groupCtx.forEach(function (child) {
            var v = child.read();
            out[child.key] = v;
            if (v !== null && v !== '' && v !== false) empty = false;
          });
          return empty ? null : out;
        },
        focusPath: function (rest) {
          var child = groupCtx.find(function (c) { return c.key === rest[0]; });
          if (child) (child.focusPath ? child.focusPath(rest.slice(1)) : child.focus());
        },
        focus: function () { if (groupCtx[0]) groupCtx[0].focus(); }
      };
    }

    if (spec.type === 'repeat') {
      return buildRepeat(spec, value, ctx, false);
    }
    if (spec.type === 'repeatObj') {
      return buildRepeat(spec, value, ctx, true);
    }
    if (spec.type === 'image') {
      return A.upload.field(spec, value, ctx);
    }

    /* plain text */
    var input = el('input', { type: 'text', id: id });
    input.value = value === null || value === undefined ? '' : String(value);
    var extra = [];
    var count = counter(input, spec.max);
    if (count) extra.push(count);
    if (spec.testLink) {
      extra.push(el('button', {
        class: 'btn btn-quiet btn-sm', type: 'button', text: 'Test this link',
        on: { click: function () {
          var target = input.value.trim();
          if (!/^https?:\/\//i.test(target)) { A.ui.toast('That is not a web address yet.', 'err'); return; }
          window.open(target, '_blank', 'noopener');
        } }
      }));
    }
    return {
      node: wrap(spec, id, input, extra),
      read: function () { return input.value.trim() || null; },
      focus: function () { input.focus(); },
      input: input
    };
  }

  function harareToday() {
    var now = new Date(Date.now() + 2 * 3600 * 1000);
    return now.toISOString().slice(0, 10);
  }

  /* --------------------------------------------------------------- repeats */

  function buildRepeat(spec, value, ctx, isObject) {
    var rows = [];
    var list = el('div', { class: 'repeat-list' });

    function addRow(rowValue) {
      var row = el('div', { class: 'repeat-row' });
      var entry;

      if (isObject) {
        var fieldsBox = el('div', { class: 'field-row' });
        var children = [];
        (spec.fields || []).forEach(function (child) {
          var built = build(child, (rowValue || {})[child.key], ctx);
          built.key = child.key;
          children.push(built);
          fieldsBox.appendChild(built.node);
        });
        entry = {
          node: fieldsBox,
          read: function () {
            var out = {};
            var empty = true;
            children.forEach(function (c) {
              var v = c.read();
              out[c.key] = v;
              if (v !== null && v !== '' && v !== false) empty = false;
            });
            return empty ? null : out;
          }
        };
      } else if (spec.itemType === 'number') {
        var numberInput = el('input', { type: 'number', step: 1 });
        numberInput.value = rowValue === null || rowValue === undefined ? '' : String(rowValue);
        entry = { node: numberInput, read: function () { return numberInput.value === '' ? null : Number(numberInput.value); } };
      } else if (spec.itemType === 'textarea') {
        var areaInput = el('textarea', { rows: 3 });
        areaInput.value = rowValue || '';
        entry = { node: areaInput, read: function () { return areaInput.value.trim() || null; } };
      } else {
        var textInput = el('input', { type: 'text' });
        textInput.value = rowValue || '';
        entry = { node: textInput, read: function () { return textInput.value.trim() || null; } };
      }

      var record = { row: row, read: entry.read };
      row.appendChild(entry.node);
      row.appendChild(el('button', {
        class: 'btn btn-quiet btn-sm', type: 'button', 'aria-label': 'Remove this row', text: '×',
        on: { click: function () {
          rows = rows.filter(function (r) { return r !== record; });
          if (row.parentNode) row.parentNode.removeChild(row);
        } }
      }));
      rows.push(record);
      list.appendChild(row);
      return record;
    }

    (Array.isArray(value) ? value : []).forEach(addRow);

    var box = el('div', { class: 'field', dataset: { field: spec.key } }, [
      el('span', { class: 'field-label', text: spec.label }),
      list,
      el('button', {
        class: 'btn btn-quiet btn-sm', type: 'button',
        on: { click: function () {
          if (spec.max && rows.length >= spec.max) {
            A.ui.toast('That is the most you can add here (' + spec.max + ').', 'info');
            return;
          }
          var record = addRow(isObject ? {} : '');
          var first = record.row.querySelector('input, textarea, select');
          if (first) first.focus();
        } }
      }, [icon('plus'), ' Add'])
    ]);
    if (spec.hint) box.appendChild(el('span', { class: 'hint', text: spec.hint }));
    box.appendChild(el('span', { class: 'error', hidden: true }));

    return {
      node: box,
      read: function () {
        return rows.map(function (r) { return r.read(); })
          .filter(function (v) { return v !== null && v !== undefined && v !== ''; });
      },
      focus: function () {
        var first = list.querySelector('input, textarea, select');
        if (first) first.focus();
      }
    };
  }

  /* ------------------------------------------------------------------ form */

  /**
   * @param {object} spec  {fields: [...]} from fields.js
   * @param {object} value current values
   * @param {object} ctx   extras such as the list of sponsor tiers
   */
  function create(spec, value, ctx) {
    init();
    var form = el('form', { novalidate: true });
    var built = [];
    var advanced = [];

    (spec.fields || []).forEach(function (fieldSpec) {
      /* A flat picture field reads several keys off the item itself, so it
         needs the whole value rather than one property of it. */
      var fieldValue = (fieldSpec.type === 'image' && fieldSpec.flat)
        ? value
        : (value || {})[fieldSpec.key];
      var node = build(fieldSpec, fieldValue, ctx);
      node.key = fieldSpec.key;
      node.spec = fieldSpec;
      built.push(node);
      if (fieldSpec.advanced) advanced.push(node); else form.appendChild(node.node);
    });

    if (advanced.length) {
      var details = el('details', { class: 'card' }, [el('summary', { text: 'Advanced settings' })]);
      advanced.forEach(function (node) { details.appendChild(node.node); });
      form.appendChild(details);
    }

    function read() {
      var out = {};
      built.forEach(function (node) {
        var v = node.read();
        if (node.spec.type === 'image' && node.spec.flat) {
          Object.assign(out, v || {});
          return;
        }
        out[node.key] = v;
      });
      return out;
    }

    function clearErrors() {
      Array.prototype.forEach.call(form.querySelectorAll('.field'), function (field) {
        field.classList.remove('has-error');
        var slot = field.querySelector(':scope > .error');
        if (slot) { slot.textContent = ''; slot.hidden = true; }
      });
      var banner = form.querySelector('.form-error');
      if (banner) banner.remove();
    }

    /** Paint server errors onto their fields; anything unmatched goes on top. */
    function showErrors(errors) {
      clearErrors();
      var unmatched = [];
      (errors || []).forEach(function (error) {
        var root = String(error.path || '').replace(/^[^.]*\./, '').split(/[.[]/)[0];
        var target = built.find(function (node) { return node.key === root; });
        if (!target && root) {
          target = built.find(function (node) {
            return node.spec.fields && node.spec.fields.some(function (f) { return f.key === root; });
          });
        }
        var field = target && target.node.matches('.field') ? target.node
          : target && target.node.querySelector('.field');
        if (field) {
          field.classList.add('has-error');
          var slot = field.querySelector(':scope > .error') || field.querySelector('.error');
          if (slot) { slot.textContent = error.message; slot.hidden = false; }
        } else {
          unmatched.push(error.message);
        }
      });
      if (unmatched.length) {
        form.insertBefore(el('p', { class: 'form-error', text: unmatched.join(' ') }), form.firstChild);
      }
      var firstBad = form.querySelector('.has-error input, .has-error select, .has-error textarea');
      if (firstBad) firstBad.focus();
    }

    return { node: form, read, showErrors, clearErrors, fields: built };
  }

  A.form = { create, build };
})();
