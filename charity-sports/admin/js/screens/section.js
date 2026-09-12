/* A screen that edits one section of the content: org, hero, impact and so on.
   The whole thing is driven by the spec in fields.js. */
(function () {
  'use strict';
  var A = (window.ADMIN = window.ADMIN || {});

  A.screens = A.screens || {};

  A.screens.section = function (name) {
    return {
      title: A.fields.SECTIONS[name].title,
      async render(root, app) {
        var el = A.ui.el;
        var spec = A.fields.SECTIONS[name];
        var content = app.content.data;

        var ctx = {
          sponsorTiers: (content.sponsorTiers || []).map(function (tier) {
            return { value: tier.id, label: tier.title };
          })
        };

        var form = A.form.create(spec, content[name], ctx);
        var save = A.ui.saveButton('Save ' + spec.title.toLowerCase());

        form.node.addEventListener('submit', async function (event) {
          event.preventDefault();
          form.clearErrors();
          try {
            await save.run(function () { return A.api.saveSection(name, form.read()); });
            await app.reload();
            app.refreshPublishState();
          } catch (err) {
            if (err.errors) form.showErrors(err.errors);
            else A.ui.toast(err.message, 'err');
            if (err.status === 409) app.offerReload();
          }
        });

        form.node.appendChild(save.node);
        root.appendChild(el('p', { class: 'screen-intro', text: spec.intro }));
        root.appendChild(el('div', { class: 'card' }, [form.node]));
      }
    };
  };
})();
