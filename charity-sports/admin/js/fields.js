/* What every screen's form looks like.
 *
 * These mirror server/lib/schema.js. The server is still the authority: it
 * re-validates everything and any message it sends back is painted onto the
 * matching field. These specs exist so problems are caught before a round
 * trip, and so the labels read like English rather than like key names. */
(function () {
  'use strict';
  var A = (window.ADMIN = window.ADMIN || {});

  var ICONS = ['target', 'racket', 'heart', 'star', 'camera', 'calendar', 'pin', 'clock', 'whatsapp', 'mail', 'arrow'];

  var HREF_HINT = 'A full https:// link, a #section of the page, or one of: ' +
    'donate, whatsapp, whatsapp:register, whatsapp:sponsor, whatsapp:golf, email, tel.';

  function cta(key, label) {
    return {
      key: key, type: 'group', label: label,
      fields: [
        { key: 'label', type: 'text', label: 'Button text', max: 40 },
        { key: 'href', type: 'text', label: 'Button goes to', max: 300, hint: HREF_HINT }
      ]
    };
  }

  function image(key, label, kind, hint) {
    return { key: key, type: 'image', label: label, kind: kind, hint: hint };
  }

  /* A picture stored as top-level keys on the item rather than as a nested
     object: sponsor logos and gallery media both work this way. */
  function flatImage(key, label, kind, keys, hint) {
    return { key: key, type: 'image', label: label, kind: kind, hint: hint, flat: true, keys: keys };
  }

  var SECTIONS = {
    impact: {
      title: 'Impact numbers',
      intro: 'The big counter on the front page. This number is kept by hand: ' +
             'move it when a cause has actually been delivered, not when money is pledged.',
      fields: [
        { key: 'livesHelped', type: 'number', label: 'Lives helped so far', min: 0, max: 1000000000,
          hint: 'People who received food, shelter, aid or treatment paid for by money you raised.',
          stepper: [1, 5, 20] },
        { key: 'goal', type: 'number', label: 'Goal', min: 1, max: 1000000000,
          hint: 'The number the bar fills towards. Currently one million.' },
        { key: 'raisedTotalUsd', type: 'number', label: 'Total raised so far, in US dollars', min: 0, max: 1000000000,
          hint: 'Appears beside the counter. Leave empty and nothing is shown. ' +
                'People give more readily when they can see that others already have.' },
        { key: 'raisedLabel', type: 'text', label: 'Wording beside that figure', max: 60 },
        { key: 'lastUpdated', type: 'date', label: 'Last updated',
          hint: 'Shown under the counter. Set to today when you change the number.', today: true },
        { key: 'milestones', type: 'repeat', itemType: 'number', label: 'Milestones',
          hint: 'Markers along the way. They must get bigger as you go down the list.' },
        { key: 'heading', type: 'text', label: 'Heading', max: 120 },
        { key: 'blurb', type: 'textarea', label: 'Paragraph under the counter', max: 600 },
        { key: 'note', type: 'textarea', label: 'Small print', max: 600,
          hint: 'Explains what the number counts, so nobody mistakes it for something automatic.' },
        { key: 'stats', type: 'repeatObj', label: 'Three small figures beside the counter', max: 6,
          fields: [
            { key: 'value', type: 'text', label: 'Figure', max: 20 },
            { key: 'suffix', type: 'text', label: 'After it', max: 6 },
            { key: 'label', type: 'text', label: 'What it means', max: 60 }
          ] },
        { key: 'remoteUrl', type: 'text', label: 'Live number feed (advanced)', max: 300, advanced: true,
          hint: 'Optional. A web address returning {"livesHelped": 1234}. Leave empty unless a developer set one up.' }
      ]
    },

    org: {
      title: 'Organisation',
      intro: 'Your name, your trust and the sentence that explains what you do.',
      fields: [
        { key: 'name', type: 'text', label: 'Name', max: 120, required: true },
        { key: 'trustName', type: 'text', label: 'Registered trust name', max: 120 },
        { key: 'tagline', type: 'text', label: 'Tagline', max: 140 },
        { key: 'slogans', type: 'repeat', itemType: 'text', label: 'Slogans', max: 5 },
        { key: 'location', type: 'text', label: 'Where you are', max: 120 },
        { key: 'siteUrl', type: 'text', label: 'Website address', max: 300,
          hint: 'Used for sharing links and search engines. Must start with https://' },
        { key: 'mission', type: 'textarea', label: 'What Charity Sports does', max: 2000, rows: 5 }
      ]
    },

    hero: {
      title: 'Front page top',
      intro: 'The first thing anyone sees. Keep the headline short.',
      fields: [
        { key: 'eyebrow', type: 'text', label: 'Small line above the headline', max: 120 },
        { key: 'headline', type: 'text', label: 'Headline', max: 120, required: true },
        { key: 'subheadline', type: 'textarea', label: 'Paragraph under the headline', max: 400 },
        cta('primaryCta', 'Main button'),
        cta('secondaryCta', 'Second button'),
        image('image', 'Main photograph', 'hero', 'Wide photo. Anything up to 3 MB is fine; it gets resized for you.')
      ]
    },

    about: {
      title: 'About',
      intro: 'Who you are and how the whole thing works.',
      fields: [
        { key: 'heading', type: 'text', label: 'Heading', max: 120 },
        { key: 'paragraphs', type: 'repeat', itemType: 'textarea', label: 'Paragraphs', max: 8 },
        { key: 'howItWorks', type: 'repeatObj', label: 'The three steps', max: 6,
          fields: [
            { key: 'icon', type: 'select', label: 'Icon', options: ICONS },
            { key: 'title', type: 'text', label: 'Step', max: 40 },
            { key: 'text', type: 'textarea', label: 'Explanation', max: 240 }
          ] }
      ]
    },

    accountability: {
      title: 'Accountability',
      intro: 'What a donor can check you against. This whole section stays hidden on the ' +
             'website until at least one thing here is filled in, so nothing unverified is ever shown. ' +
             'It is the single most useful thing you can complete: people give to organisations ' +
             'they can hold to account.',
      fields: [
        { key: 'heading', type: 'text', label: 'Heading', max: 120 },
        { key: 'intro', type: 'textarea', label: 'Opening paragraph', max: 600 },
        { key: 'registrationLabel', type: 'text', label: 'What the number is called', max: 60,
          hint: 'For example "Trust registration number".' },
        { key: 'registrationNumber', type: 'text', label: 'The number itself', max: 60,
          hint: 'Leave empty until you have it in front of you. Do not guess.' },
        { key: 'bankedWith', type: 'text', label: 'Where the money is held', max: 120,
          hint: 'For example "Held in the Charity Sport Trust account". No account numbers.' },
        { key: 'financeContactName', type: 'text', label: 'Who answers questions about money', max: 80 },
        { key: 'financeContactRole', type: 'text', label: 'Their role', max: 60 },
        { key: 'financeContactEmail', type: 'text', label: 'Their email', max: 254 },
        { key: 'receiptsPolicy', type: 'textarea', label: 'Receipts', max: 400,
          hint: 'Say plainly whether a donor gets one, and how.' },
        { key: 'statements', type: 'repeat', itemType: 'textarea', label: 'Promises you can keep', max: 6,
          hint: 'Short, checkable statements. Only put things here that are true today.' }
      ]
    },

    share: {
      title: 'Sharing',
      intro: 'What gets written into the message when somebody taps the share button.',
      fields: [
        { key: 'label', type: 'text', label: 'Button text', max: 40 },
        { key: 'message', type: 'textarea', label: 'The message', max: 300,
          hint: 'The web address is added to the end automatically.' }
      ]
    },

    signup: {
      title: 'Sign-up form',
      intro: 'The short form asking people to leave a name so you can tell them about the next ' +
             'event. It only appears on the website when this server is reachable; otherwise the ' +
             'website shows a WhatsApp link instead, so nothing is ever lost.',
      fields: [
        { key: 'heading', type: 'text', label: 'Heading', max: 120 },
        { key: 'text', type: 'textarea', label: 'Paragraph', max: 600 },
        { key: 'nameLabel', type: 'text', label: 'Label on the name box', max: 60 },
        { key: 'contactLabel', type: 'text', label: 'Label on the contact box', max: 60 },
        { key: 'buttonLabel', type: 'text', label: 'Button text', max: 40 },
        { key: 'successText', type: 'text', label: 'What they see afterwards', max: 300 },
        { key: 'fallbackLabel', type: 'text', label: 'WhatsApp link text, used when this server is not reachable', max: 60 },
        { key: 'privacyNote', type: 'textarea', label: 'What you do with their details', max: 400,
          hint: 'Say plainly what you will and will not do. People read this one.' }
      ]
    },

    donate: {
      title: 'Donations',
      intro: 'Where the money goes and, more importantly, where the Donate button sends people.',
      fields: [
        { key: 'url', type: 'text', label: 'Donation page address', max: 300, required: true, testLink: true,
          hint: 'This is where every Donate button on the site sends people. Check it after you change it.' },
        { key: 'provider', type: 'text', label: 'Who handles the payment', max: 40 },
        { key: 'label', type: 'text', label: 'Button text', max: 40 },
        { key: 'heading', type: 'text', label: 'Heading', max: 120 },
        { key: 'text', type: 'textarea', label: 'Paragraph', max: 600 },
        { key: 'whereMoneyGoes', type: 'repeat', itemType: 'text', label: 'Where your money goes', max: 8 },
        { key: 'paymentNote', type: 'textarea', label: 'Warning note', max: 400,
          hint: 'Shown in red. This is where the EcoCash warning lives.' }
      ]
    },

    contact: {
      title: 'Contact',
      intro: 'Your number, your email, and the message that gets pre-filled when somebody taps WhatsApp.',
      fields: [
        { key: 'whatsapp', type: 'text', label: 'WhatsApp number', max: 40,
          hint: 'Write it how you want it shown, for example +263 776 437 764. The link is built from the digits.' },
        { key: 'email', type: 'text', label: 'Email address', max: 254 },
        { key: 'emailSubject', type: 'text', label: 'Email subject line', max: 120 },
        { key: 'whatsappMessages', type: 'group', label: 'Pre-filled WhatsApp messages',
          fields: [
            { key: 'default', type: 'textarea', label: 'General', max: 300 },
            { key: 'register', type: 'textarea', label: 'Entering an event', max: 300 },
            { key: 'sponsor', type: 'textarea', label: 'Taking a sponsor slot', max: 300 },
            { key: 'portrait', type: 'textarea', label: 'Booking a portrait', max: 300 },
            { key: 'monthly', type: 'textarea', label: 'Becoming a Monthly Friend', max: 300 },
            { key: 'golf', type: 'textarea', label: 'Asking about the golf day', max: 300 }
          ] },
        { key: 'social', type: 'repeatObj', label: 'Social links', max: 8,
          fields: [
            { key: 'label', type: 'text', label: 'Name', max: 40 },
            { key: 'url', type: 'text', label: 'Address', max: 300 }
          ] }
      ]
    },

    sponsorsMeta: {
      title: 'Sponsors introduction',
      intro: 'The line above the sponsor grid and the empty slot that invites new ones.',
      fields: [
        { key: 'intro', type: 'text', label: 'Line above the grid', max: 240 },
        { key: 'ctaTile', type: 'group', label: 'The "slot available" tile',
          fields: [
            { key: 'title', type: 'text', label: 'Heading', max: 60 },
            { key: 'subtitle', type: 'text', label: 'Price line', max: 60 },
            { key: 'label', type: 'text', label: 'Link text', max: 40 },
            { key: 'href', type: 'text', label: 'Link goes to', max: 300, hint: HREF_HINT }
          ] }
      ]
    },

    api: {
      title: 'Advanced',
      intro: 'Leave this alone unless a developer asked you to change it.',
      advanced: true,
      fields: [
        { key: 'baseUrl', type: 'text', label: 'Live content address', max: 300,
          hint: 'Empty means "this same website", which is almost always right.' }
      ]
    }
  };

  var COLLECTIONS = {
    causes: {
      title: 'Causes',
      singular: 'cause',
      intro: 'What you are raising money for. Mark a cause as funded when it is done ' +
             'and it moves to the list of things you have already paid for.',
      summaryOf: function (item) {
        var bits = [];
        if (item.targetUsd) bits.push('US$' + A.ui.fmtInt(item.targetUsd));
        if (item.beneficiaries) bits.push(item.beneficiaries + ' people');
        if (item.period) bits.push(item.period);
        return bits.join(' · ');
      },
      badgeOf: function (item) {
        return { text: item.status || 'current', className: 'tag-' + (item.status || 'current') };
      },
      blank: { status: 'current', accent: 'green', active: true },
      fields: [
        { key: 'title', type: 'text', label: 'Title', max: 90, required: true },
        { key: 'status', type: 'select', label: 'Status', options: ['current', 'funded', 'closed'],
          hint: 'Current shows it on the front page. Funded and closed move it to the list underneath.' },
        { key: 'tag', type: 'text', label: 'Small label above the title', max: 40 },
        { key: 'summary', type: 'textarea', label: 'Summary', max: 600, rows: 4 },
        { key: 'details', type: 'repeat', itemType: 'text', label: 'Bullet points', max: 8 },
        { key: 'targetUsd', type: 'number', label: 'Target in US dollars', min: 0, max: 10000000,
          hint: 'Leave empty for an open-ended appeal with no fixed total.' },
        { key: 'raisedUsd', type: 'number', label: 'Raised so far in US dollars', min: 0, max: 10000000,
          hint: 'Leave empty to hide the progress bar. Needs a target to be set first.' },
        { key: 'beneficiaries', type: 'number', label: 'How many people this helps', min: 0, max: 10000000 },
        { key: 'period', type: 'text', label: 'Year or period', max: 40 },
        { key: 'accent', type: 'select', label: 'Colour', options: ['green', 'orange', 'gold'] },
        { key: 'stats', type: 'repeatObj', label: 'Figures on the card', max: 4,
          fields: [
            { key: 'value', type: 'text', label: 'Figure', max: 16 },
            { key: 'label', type: 'text', label: 'What it means', max: 32 }
          ] },
        image('image', 'Photograph', 'cause'),
        cta('cta', 'Button'),
        { key: 'active', type: 'checkbox', label: 'Show this on the website' }
      ]
    },

    events: {
      title: 'Events',
      singular: 'event',
      intro: 'Anything you are putting on. An event with no date yet still goes up: ' +
             'it shows "date to be announced" and a button so people can register interest.',
      summaryOf: function (item) {
        if (item.sessions && item.sessions.length) {
          return item.sessions.length + ' session' + (item.sessions.length === 1 ? '' : 's') +
            ' · from ' + A.ui.fmtDate(item.sessions[0].date);
        }
        if (item.startDate) return A.ui.fmtDate(item.startDate);
        return item.dateNote || 'No date yet';
      },
      badgeOf: function (item) {
        if (item.featured) return { text: 'featured', className: 'tag-featured' };
        if (!item.startDate && (!item.sessions || !item.sessions.length)) {
          return { text: 'no date', className: 'tag-closed' };
        }
        return null;
      },
      blank: { active: true, featured: false, sessions: [], ctas: [] },
      fields: [
        { key: 'title', type: 'text', label: 'Name', max: 110, required: true },
        { key: 'sport', type: 'text', label: 'Sport', max: 40, hint: 'Padel, Golf, Cricket, whatever it is.' },
        { key: 'kind', type: 'text', label: 'Kind of event', max: 40, hint: 'Tournament, golf day, fun run.' },
        { key: 'summary', type: 'textarea', label: 'Summary', max: 600, rows: 4 },
        { key: 'startDate', type: 'date', label: 'First day',
          hint: 'Leave both dates empty if it is not fixed yet.' },
        { key: 'endDate', type: 'date', label: 'Last day' },
        { key: 'startTime', type: 'time', label: 'Start time', hint: '24-hour Harare time, for example 19:00.' },
        { key: 'endTime', type: 'time', label: 'Finish time' },
        { key: 'dateNote', type: 'text', label: 'What to say when there is no date', max: 120 },
        { key: 'sessions', type: 'repeatObj', label: 'Individual days', max: 60,
          hint: 'For something that runs over several days. Each row becomes a line in the schedule.',
          fields: [
            { key: 'date', type: 'date', label: 'Date' },
            { key: 'startTime', type: 'time', label: 'Start' },
            { key: 'endTime', type: 'time', label: 'Finish' },
            { key: 'label', type: 'text', label: 'Label', max: 40 },
            { key: 'final', type: 'checkbox', label: 'This is the final' }
          ] },
        { key: 'highlights', type: 'repeat', itemType: 'text', label: 'Selling points', max: 8 },
        { key: 'venue', type: 'group', label: 'Venue',
          fields: [
            { key: 'name', type: 'text', label: 'Name', max: 90 },
            { key: 'address', type: 'text', label: 'Address', max: 200 },
            { key: 'mapsUrl', type: 'text', label: 'Map link', max: 500, testLink: true }
          ] },
        image('poster', 'Poster', 'poster'),
        { key: 'ctas', type: 'repeatObj', label: 'Buttons', max: 3,
          fields: [
            { key: 'label', type: 'text', label: 'Button text', max: 40 },
            { key: 'href', type: 'text', label: 'Goes to', max: 300, hint: HREF_HINT }
          ] },
        { key: 'note', type: 'text', label: 'Small print', max: 240 },
        { key: 'featured', type: 'checkbox', label: 'Show this one large at the top',
          hint: 'Only one event can be featured. Ticking this unticks the others.' },
        { key: 'active', type: 'checkbox', label: 'Show this on the website' }
      ]
    },

    sponsors: {
      title: 'Sponsors',
      singular: 'sponsor',
      intro: 'Everyone standing with you. A sponsor with no logo file shows as a name tile, ' +
             'which is perfectly fine until they send artwork.',
      summaryOf: function (item) { return [item.tier, item.tagline].filter(Boolean).join(' · '); },
      badgeOf: function (item) { return item.logo ? null : { text: 'no logo', className: 'tag-closed' }; },
      thumbOf: function (item) { return item.logo || null; },
      blank: { tier: 'supporter', logoBg: 'dark', active: true },
      fields: [
        { key: 'name', type: 'text', label: 'Name', max: 80, required: true },
        { key: 'tier', type: 'select', label: 'Tier', optionsFrom: 'sponsorTiers' },
        { key: 'tagline', type: 'text', label: 'Line under the name', max: 100 },
        { key: 'logoBg', type: 'select', label: 'Logo background', options: ['dark', 'light'],
          hint: 'Tiles are dark. Pick light only if the logo is dark on white and cannot be read.' },
        flatImage('logo', 'Logo', 'logo', ['logo', 'logoWebp'],
          'PNG, JPEG or WebP. It is resized and a WebP copy is made for you.'),
        { key: 'alt', type: 'text', label: 'Logo description', max: 140,
          hint: 'What a screen reader says. Usually just the company name.' },
        { key: 'link', type: 'text', label: 'Their website', max: 300, testLink: true },
        { key: 'active', type: 'checkbox', label: 'Show this on the website' }
      ]
    },

    gallery: {
      title: 'Gallery',
      singular: 'gallery item',
      intro: 'Photos, posters and videos. Every picture needs a description so people ' +
             'using a screen reader know what it shows.',
      summaryOf: function (item) { return item.caption || item.alt || ''; },
      badgeOf: function (item) { return { text: item.type || 'photo', className: 'tag-current' }; },
      thumbOf: function (item) { return item.thumb || item.src || null; },
      blank: { type: 'photo', active: true },
      fields: [
        { key: 'title', type: 'text', label: 'Title', max: 90 },
        { key: 'type', type: 'select', label: 'Kind', options: ['photo', 'poster', 'video'] },
        { key: 'caption', type: 'textarea', label: 'Caption', max: 200 },
        flatImage('__media', 'Picture', 'gallery',
          ['src', 'webp', 'thumb', 'thumbWebp', 'width', 'height'],
          'Uploading fills in the picture, the small version and the sizes all at once.'),
        { key: 'alt', type: 'text', label: 'Description for screen readers', max: 300,
          hint: 'Say what is in the picture. Required for photos and posters.' },
        { key: 'provider', type: 'select', label: 'Video hosted on', options: ['youtube', 'file'], allowEmpty: true },
        { key: 'youtubeId', type: 'text', label: 'YouTube link or id', max: 300,
          hint: 'Paste the whole YouTube address. Only needed for videos.' },
        { key: 'active', type: 'checkbox', label: 'Show this on the website' }
      ]
    },

    waysToSupport: {
      title: 'Ways to support',
      singular: 'card',
      intro: 'The cards that tell people how they can help.',
      summaryOf: function (item) { return item.price || ''; },
      blank: { icon: 'heart', active: true },
      fields: [
        { key: 'title', type: 'text', label: 'Title', max: 40, required: true },
        { key: 'icon', type: 'select', label: 'Icon', options: ICONS },
        { key: 'price', type: 'text', label: 'Price line', max: 40 },
        { key: 'text', type: 'textarea', label: 'Explanation', max: 300 },
        cta('cta', 'Button'),
        { key: 'active', type: 'checkbox', label: 'Show this on the website' }
      ]
    }
  };

  A.fields = { SECTIONS, COLLECTIONS, ICONS, HREF_HINT };
})();
