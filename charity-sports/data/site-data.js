/* ---------------------------------------------------------------------------
 * Charity Sports — website content.
 *
 * This one file holds every piece of text, number, date and image path on the
 * public site. Edit it and the website changes. If you are using the admin
 * panel instead, DO NOT hand-edit this file: the next "Publish" overwrites it.
 *
 * Quick rules
 *   - Keep the quotes and the commas. A missing comma stops the whole page
 *     rendering. If the page goes blank, open the browser console (F12) and it
 *     will name the line, or use GitHub's History to undo.
 *   - Dates look like "2026-09-17" (year-month-day).
 *   - Times look like "19:00" (24-hour, Harare time). Use null for "to be
 *     confirmed".
 *   - Money is a plain number: 3200, not "US$3,200".
 *   - null means "we do not have this yet" and hides that bit of the page.
 *   - active: false hides an item without deleting it.
 * ------------------------------------------------------------------------- */

window.CHARITY_DATA = {
  schemaVersion: 1,
  updatedAt: "2026-09-12T08:00:00.000Z",

  /* Where the live content API lives. Leave "" unless a developer tells you
     otherwise: "" means "same website", which is correct for a static host. */
  api: { baseUrl: "" },

  org: {
    name: "Charity Sports",
    trustName: "Charity Sport Trust",
    tagline: "Play • Connect • Make an Impact",
    slogans: ["Your support is our drive.", "Thank you for standing with us"],
    location: "Harare, Zimbabwe",
    siteUrl: "https://jasonmakwabarara.github.io/charity-sports/",
    mission:
      "Charity Sports raises money through sport for the causes that need it most. " +
      "We pick a cause, put on an event, and send what we raise straight to the people it was raised for. " +
      "Right now that means disaster relief in Kariba and surgery for children in Mhangura."
  },

  hero: {
    eyebrow: "Charity Sport Trust • Harare, Zimbabwe",
    headline: "Sport that changes lives.",
    subheadline:
      "We turn a game into food, shelter and surgery. Padel courts now, a golf day next, " +
      "and whatever it takes after that. Come play, enjoy, and lend your support.",
    primaryCta: { label: "Donate now", href: "donate" },
    secondaryCta: { label: "See what’s on", href: "#events" },
    image: {
      src: "assets/img/hero-doctors-1280.jpg",
      webp: "assets/img/hero-doctors-1280.webp",
      srcSmall: "assets/img/hero-doctors-800.jpg",
      webpSmall: "assets/img/hero-doctors-800.webp",
      width: 1280,
      height: 853,
      alt: "Doctors and nurses in scrubs carry medical cases towards a rural clinic where families are waiting outside."
    }
  },

  impact: {
    livesHelped: 20,
    goal: 1000000,
    milestones: [1000, 10000, 100000, 1000000],

    /* Total raised to date, in US dollars. Left empty on purpose: nobody has
       supplied a real figure, and an invented one would be worse than none.
       Put the real number here and it appears beside the counter. Donors give
       more readily when they can see that other people already have. */
    raisedTotalUsd: null,
    raisedLabel: "Raised so far",

    lastUpdated: "2026-09-12",
    heading: "Lives helped so far",
    blurb:
      "It starts with twenty children in Mhangura who have been assessed and selected for surgery. " +
      "Every match played, every sponsor slot taken and every donation adds to this number. " +
      "We are going for one million.",
    note:
      "Counted as people who received food, shelter, aid or medical treatment paid for by funds Charity Sports raised. " +
      "Updated by hand as each cause is delivered.",
    /* Optional: a URL returning {"livesHelped": 1234}. Leave "" if unused. */
    remoteUrl: "",
    stats: [
      { label: "Causes being funded", value: "2", suffix: "" },
      { label: "Children booked for surgery", value: "20", suffix: "+" },
      { label: "Sponsors standing with us", value: "16", suffix: "" }
    ]
  },

  about: {
    heading: "What Charity Sports is",
    paragraphs: [
      "Charity Sports is a Harare fundraising effort run under the Charity Sport Trust. We are not tied to one sport or one cause. " +
        "When something needs funding, we build an event around it and bring people together to play for it.",
      "The format changes on purpose. A padel tournament is running through September. A golf day comes next. " +
        "What stays the same is where the money goes: straight to the cause it was raised for, with the people doing the work on the ground.",
      "Every cause we take on is one we can see the end of. Twenty children with a surgery date. Families in Kariba who need food and shelter now. " +
        "Small enough to finish, real enough to count."
    ],
    howItWorks: [
      { icon: "target", title: "Pick a cause", text: "Something specific, urgent and finishable. We publish the target so you can see it close." },
      { icon: "racket", title: "Put on an event", text: "Padel, golf, whatever fits. Players pay to enter, sponsors back the slots, everyone turns up." },
      { icon: "heart", title: "Deliver it", text: "Funds go to the people doing the work, and the lives-helped counter moves once it has actually landed." }
    ]
  },

  /* -------------------------------------------------------------------------
     What a donor can check you against.
     Every field here is empty until Charity Sports supplies the real details.
     While they are all empty the whole section stays hidden, so the site never
     shows an unverified claim. Fill any of them in and it appears.
     ---------------------------------------------------------------------- */
  accountability: {
    heading: "Where the money goes, and who to ask",
    intro:
      "We are asking you for money, so here is what you can hold us to. " +
      "If anything here is unclear, ask us before you give, not after.",

    registrationLabel: "Trust registration number",
    registrationNumber: null,        // ← the Charity Sport Trust number

    bankedWith: null,                // ← e.g. "Held in the trust account at X Bank"

    financeContactName: null,        // ← who answers questions about money
    financeContactRole: null,        // ← e.g. "Treasurer"
    financeContactEmail: null,

    receiptsPolicy: null,            // ← e.g. "We issue a receipt for every donation over US$20."

    /* Statements that are true today and need no confirmation. */
    statements: [
      "The doctors operate for free. No donation pays a surgeon.",
      "Money raised for a cause goes to that cause. When one is fully funded we close it and say so.",
      "Donations are handled by Contipay, not by us. We never ask for card details over WhatsApp."
    ]
  },

  /* status: "current" shows in the main grid. "funded" and "closed" move the
     cause into the "What we have funded" strip underneath. */
  causes: [
    {
      id: "mhangura-surgeries",
      status: "current",
      order: 0,
      active: true,
      tag: "Anonymous Doctors",
      title: "Surgery for the children of Mhangura",
      summary:
        "More than twenty children in Mhangura have been medically assessed and selected for operations to treat hernias and undescended testes. " +
        "The surgeons work pro bono. The money covers everything around them.",
      details: [
        "The Anonymous Doctors give their time and skill for free.",
        "US$3,200 covers theatre time, consumables, anaesthetic, transport and aftercare for the whole group.",
        "Left untreated, these conditions get more dangerous and more expensive every year a child waits."
      ],
      stats: [
        { value: "20+", label: "Children assessed" },
        { value: "US$3,200", label: "Needed in full" },
        { value: "$0", label: "Charged by the doctors" }
      ],
      targetUsd: 3200,
      /* Put the real amount raised here and a progress bar appears on the
         card. Left empty because no figure has been supplied. */
      raisedUsd: null,
      beneficiaries: 20,
      period: "2026",
      accent: "green",
      image: {
        src: "assets/img/gallery/doctors-outreach.jpg",
        webp: "assets/img/gallery/doctors-outreach.webp",
        width: 1280,
        height: 853,
        alt: "A medical outreach team walking towards a rural clinic with equipment cases."
      },
      cta: { label: "Fund a surgery", href: "donate" }
    },
    {
      id: "kariba-disaster-relief",
      status: "current",
      order: 1,
      active: true,
      tag: "Disaster relief",
      title: "Kariba disaster relief",
      summary:
        "Food, shelter and aid for families hit by the disaster in Kariba. This one is open-ended: " +
        "we send what we raise as we raise it, because people need it now rather than at the end of a campaign.",
      details: [
        "Food parcels and clean water for displaced households.",
        "Shelter materials and basic supplies for families who lost their homes.",
        "Delivered through partners already working on the ground in Kariba."
      ],
      stats: [
        { value: "Ongoing", label: "Appeal status" },
        { value: "Food", label: "Shelter • Aid" }
      ],
      targetUsd: null,
      raisedUsd: null,
      beneficiaries: null,
      period: "2026",
      accent: "orange",
      image: null,
      cta: { label: "Give to Kariba", href: "donate" }
    }
  ],

  /* An event with startDate: null is announced but not yet dated. It shows a
     "date to be announced" note and a register-interest button instead of a
     countdown. That is how you put the next thing up before you have a date. */
  events: [
    {
      id: "padel-tournament-2026",
      order: 0,
      active: true,
      featured: true,
      sport: "Padel",
      kind: "Tournament",
      title: "Charity Padel Tournament 2026",
      summary:
        "Three weeks of Thursday-night padel at Stable Sports, finishing with a Saturday final. " +
        "Prizes, awards and a trophy. Entry money and sponsor slots go to the causes we are funding.",
      startDate: "2026-09-03",
      endDate: "2026-09-26",
      startTime: "19:00",
      endTime: "21:00",
      dateNote: null,
      sessions: [
        { date: "2026-09-03", startTime: "19:00", endTime: "21:00", label: "Night 1", final: false },
        { date: "2026-09-10", startTime: "19:00", endTime: "21:00", label: "Night 2", final: false },
        { date: "2026-09-17", startTime: "19:00", endTime: "21:00", label: "Night 3", final: false },
        { date: "2026-09-24", startTime: "19:00", endTime: "21:00", label: "Night 4", final: false },
        { date: "2026-09-26", startTime: null, endTime: null, label: "The Final", final: true }
      ],
      highlights: ["Prizes, awards and a trophy", "7–9 PM sharp", "Runs for three weeks", "Teams and singles welcome"],
      venue: {
        name: "Stable Sports",
        address: "33A Kingsmead Road West, Borrowdale, Harare",
        mapsUrl: "https://www.google.com/maps/search/?api=1&query=Stable+Sports+33A+Kingsmead+Road+West+Borrowdale+Harare"
      },
      poster: {
        src: "assets/img/gallery/poster-padel-escape.jpg",
        webp: "assets/img/gallery/poster-padel-escape.webp",
        width: 733,
        height: 1100,
        alt: "Comic-style poster for the Charity Padel Tournament 2026 titled The Great Padel Escape."
      },
      ctas: [
        { label: "Enter a team", href: "whatsapp:register" },
        { label: "Sponsor a slot", href: "whatsapp:sponsor" }
      ],
      note: "All times are Harare time (CAT, UTC+2)."
    },
    {
      id: "charity-golf-day",
      order: 1,
      active: true,
      featured: false,
      sport: "Golf",
      kind: "Golf day",
      title: "Charity Golf Day",
      summary:
        "The next event after the padel tournament. A full day of golf raising money for whichever causes are open at the time. " +
        "Date, course and format are being confirmed now.",
      startDate: null,
      endDate: null,
      startTime: null,
      endTime: null,
      dateNote: "Date to be announced",
      sessions: [],
      highlights: ["Teams and individual entries", "Sponsor a hole", "Prizes and a trophy"],
      venue: null,
      poster: null,
      ctas: [
        { label: "Register interest", href: "whatsapp:golf" },
        { label: "Sponsor the day", href: "whatsapp:sponsor" }
      ],
      note: "Tell us you want in and we will send the date the moment it is set."
    }
  ],

  waysToSupport: [
    {
      id: "play",
      order: 0,
      active: true,
      icon: "racket",
      title: "Play",
      price: "Entry fee",
      text: "Enter a team or come on your own and we will find you one. Every entry goes to the causes.",
      cta: { label: "Enter an event", href: "whatsapp:register" }
    },
    {
      id: "sponsor-slot",
      order: 1,
      active: true,
      icon: "star",
      title: "Sponsor a slot",
      price: "Silver — US$100",
      text: "Your name and logo on the flyers, the posters and this website, next to everyone else standing with us.",
      cta: { label: "Take a slot", href: "whatsapp:sponsor" }
    },
    {
      id: "portrait",
      order: 2,
      active: true,
      icon: "camera",
      title: "Your portrait",
      price: "US$50+ — Promoter",
      text: "A professional photo session, with what you pay going straight into the fund.",
      cta: { label: "Book a session", href: "whatsapp:portrait" }
    },
    {
      id: "monthly-friends",
      order: 3,
      active: true,
      icon: "calendar",
      title: "Monthly Friends",
      price: "Commit for 3 months",
      text: "A small amount every month keeps giving going year-round, between events, when causes still need funding.",
      cta: { label: "Become a Friend", href: "whatsapp:monthly" }
    },
    {
      id: "donate-direct",
      order: 4,
      active: true,
      icon: "heart",
      title: "Just donate",
      price: "Any amount",
      text: "No event, no sign-up. Give what you can and it goes to the causes that are open right now.",
      cta: { label: "Donate now", href: "donate" }
    }
  ],

  sponsorTiers: [
    { id: "prize", title: "Prize sponsors", subtitle: "Thank you for standing with us", size: "large", order: 0 },
    { id: "supporter", title: "Our proud sponsors and supporters", subtitle: null, size: "normal", order: 1 }
  ],

  sponsorsMeta: {
    intro: "Every name here paid for something real. Prizes, courts, printing, a surgery.",
    ctaTile: {
      title: "Sponsor slot available",
      subtitle: "Silver — US$100",
      label: "Become a sponsor",
      href: "whatsapp:sponsor"
    }
  },

  /* tier must be one of the sponsorTiers ids above: "prize" or "supporter".
     logo: null gives a typographic tile, which is the fallback until a real
     logo file arrives. Upload one in the admin panel and it swaps itself. */
  sponsors: [
    { id: "anonymous-doctor", name: "Anonymous Doctor", tier: "prize", tagline: "Play • Connect • Make an Impact — Do not sleep", logo: null, logoWebp: null, logoBg: "dark", alt: null, link: null, order: 0, active: true },
    { id: "universal-support", name: "Universal Support", tier: "prize", tagline: "Pro Bono Doctors of Africa — Compassion • Service • Humanity", logo: null, logoWebp: null, logoBg: "dark", alt: null, link: null, order: 1, active: true },

    { id: "bhola", name: "Bhola", tier: "supporter", tagline: "The Mega Mart", logo: null, logoWebp: null, logoBg: "dark", alt: null, link: null, order: 0, active: true },
    { id: "trauma-centre", name: "Trauma Centre", tier: "supporter", tagline: "Critical Care", logo: null, logoWebp: null, logoBg: "dark", alt: null, link: null, order: 1, active: true },
    { id: "crystal", name: "Crystal", tier: "supporter", tagline: null, logo: null, logoWebp: null, logoBg: "dark", alt: null, link: null, order: 2, active: true },
    { id: "mcm-estates", name: "MCM Estates", tier: "supporter", tagline: "Land banking, smart investment", logo: null, logoWebp: null, logoBg: "dark", alt: null, link: null, order: 3, active: true },
    { id: "screen-speed", name: "Screen Speed", tier: "supporter", tagline: null, logo: null, logoWebp: null, logoBg: "dark", alt: null, link: null, order: 4, active: true },
    { id: "contrive", name: "Contrive", tier: "supporter", tagline: null, logo: null, logoWebp: null, logoBg: "dark", alt: null, link: null, order: 5, active: true },
    {
      id: "hannah-ai",
      name: "Hannah AI",
      tier: "supporter",
      tagline: "Marketing automation system",
      logo: "assets/logos/hannah-ai-tile.png",
      logoWebp: "assets/logos/hannah-ai-tile.webp",
      logoBg: "dark",
      alt: "Hannah AI",
      link: null,
      order: 6,
      active: true
    },
    { id: "mikes-radiators", name: "Mike’s Radiators", tier: "supporter", tagline: "Cooling since 1979", logo: null, logoWebp: null, logoBg: "dark", alt: null, link: null, order: 7, active: true },
    { id: "stable-sports", name: "Stable Sports", tier: "supporter", tagline: "Tournament venue", logo: null, logoWebp: null, logoBg: "dark", alt: null, link: null, order: 8, active: true },
    { id: "pantic-architects", name: "Pantić Architects", tier: "supporter", tagline: null, logo: null, logoWebp: null, logoBg: "dark", alt: null, link: null, order: 9, active: true },
    { id: "bucane-41", name: "Bucane 41", tier: "supporter", tagline: null, logo: null, logoWebp: null, logoBg: "dark", alt: null, link: null, order: 10, active: true },
    { id: "focal-point-photographic", name: "Focal Point Photographic", tier: "supporter", tagline: null, logo: null, logoWebp: null, logoBg: "dark", alt: null, link: null, order: 11, active: true },
    { id: "if-media", name: "IF Media", tier: "supporter", tagline: null, logo: null, logoWebp: null, logoBg: "dark", alt: null, link: null, order: 12, active: true },
    { id: "charity-sport-trust", name: "Charity Sport Trust", tier: "supporter", tagline: "Organiser", logo: null, logoWebp: null, logoBg: "dark", alt: null, link: null, order: 13, active: true },

    /* Name unreadable on the poster photograph, something ending "ship Hub".
       Set active: true and fix the name once it is confirmed. */
    { id: "unconfirmed-hub", name: "Leadership Hub", tier: "supporter", tagline: "Name to be confirmed", logo: null, logoWebp: null, logoBg: "dark", alt: null, link: null, order: 14, active: false }
  ],

  gallery: [
    {
      id: "doctors-outreach",
      order: 0,
      active: true,
      type: "photo",
      title: "The team that does the operating",
      caption: "An Anonymous Doctors outreach arriving at a rural clinic. The surgeons give their time for free.",
      src: "assets/img/gallery/doctors-outreach.jpg",
      webp: "assets/img/gallery/doctors-outreach.webp",
      thumb: "assets/img/gallery/doctors-outreach-480.jpg",
      thumbWebp: "assets/img/gallery/doctors-outreach-480.webp",
      width: 1280,
      height: 853,
      alt: "Six medical staff in green and blue scrubs walk towards a clinic building carrying cases and bags, with families waiting under the veranda.",
      provider: null,
      youtubeId: null,
      videoSrc: null,
      poster: null
    },
    {
      id: "poster-padel-escape",
      order: 1,
      active: true,
      type: "poster",
      title: "The Great Padel Escape",
      caption: "One of the comic posters for the 2026 tournament. Fictional, affectionate and very Harare.",
      src: "assets/img/gallery/poster-padel-escape.jpg",
      webp: "assets/img/gallery/poster-padel-escape.webp",
      thumb: "assets/img/gallery/poster-padel-escape-480.jpg",
      thumbWebp: "assets/img/gallery/poster-padel-escape-480.webp",
      width: 733,
      height: 1100,
      alt: "Comic-book style poster showing two characters running from a prison towards padel courts, with the tournament dates and venue listed beside them.",
      provider: null,
      youtubeId: null,
      videoSrc: null,
      poster: null
    },
    {
      id: "poster-padel-bargain",
      order: 2,
      active: true,
      type: "poster",
      title: "The Padel Bargain",
      caption: "Love in the aisles at Bhola. The sponsor strip along the bottom is the real point.",
      src: "assets/img/gallery/poster-padel-bargain.jpg",
      webp: "assets/img/gallery/poster-padel-bargain.webp",
      thumb: "assets/img/gallery/poster-padel-bargain-480.jpg",
      thumbWebp: "assets/img/gallery/poster-padel-bargain-480.webp",
      width: 681,
      height: 1100,
      alt: "Comic-book style poster of a couple in a supermarket aisle negotiating over a padel racket, with tournament details listed beneath.",
      provider: null,
      youtubeId: null,
      videoSrc: null,
      poster: null
    },
    {
      id: "prize-sponsors-flyer",
      order: 3,
      active: true,
      type: "poster",
      title: "Prize sponsors flyer",
      caption: "The printed thank-you that goes out at every night of the tournament.",
      src: "assets/img/gallery/prize-sponsors-flyer.jpg",
      webp: "assets/img/gallery/prize-sponsors-flyer.webp",
      thumb: "assets/img/gallery/prize-sponsors-flyer-480.jpg",
      thumbWebp: "assets/img/gallery/prize-sponsors-flyer-480.webp",
      width: 1400,
      height: 1995,
      alt: "A printed flyer headed Prize Sponsors, showing tiles for Anonymous Doctor and Universal Support above a grid of nine supporter logos.",
      provider: null,
      youtubeId: null,
      videoSrc: null,
      poster: null
    },
    {
      id: "flyer-and-tickets",
      order: 4,
      active: true,
      type: "photo",
      title: "Flyers and ticket stubs",
      caption: "Sponsor flyers and the support-tier stubs, ready for a Thursday night.",
      src: "assets/img/gallery/flyer-and-tickets.jpg",
      webp: "assets/img/gallery/flyer-and-tickets.webp",
      thumb: "assets/img/gallery/flyer-and-tickets-480.jpg",
      thumbWebp: "assets/img/gallery/flyer-and-tickets-480.webp",
      width: 1400,
      height: 1859,
      alt: "The prize sponsors flyer lying on a bench beside printed ticket stubs listing support tiers.",
      provider: null,
      youtubeId: null,
      videoSrc: null,
      poster: null
    }
  ],

  /* In Zimbabwe a link travels by WhatsApp forward more than by anything else,
     so sharing gets a button rather than being left to the browser menu. */
  share: {
    label: "Share this",
    message:
      "Charity Sports is raising money for children's surgery in Mhangura and disaster relief in Kariba. " +
      "Have a look, and come and play."
  },

  /* The sign-up form only appears when the admin server is reachable, because
     there is nowhere to put a name on a static host. Without it, the block
     falls back to the WhatsApp link, which always works. */
  signup: {
    heading: "Hear when the next one is on",
    text:
      "Events are announced a couple of weeks ahead. Leave a name and a number " +
      "and we will tell you when the golf day has a date.",
    nameLabel: "Your name",
    contactLabel: "WhatsApp number or email",
    buttonLabel: "Keep me posted",
    successText: "Thank you. We will be in touch when the next event is set.",
    fallbackLabel: "Message us on WhatsApp instead",
    privacyNote:
      "We keep this to tell you about events and nothing else. No lists are sold or shared. " +
      "Ask us any time and we will delete it."
  },

  donate: {
    /* THE MONEY LINK. Replace this with the exact Charity Sports donation page
       as soon as Contipay provides it. Nothing else needs changing. */
    url: "https://donate.contipay.co.zw/",
    provider: "Contipay",
    label: "Donate now",
    heading: "Make it count",
    text:
      "Donations go through Contipay. Give once or set something up monthly. " +
      "If you would rather arrange it with a person, message us on WhatsApp and we will sort it out.",
    whereMoneyGoes: [
      "Theatre time, consumables and aftercare for the Mhangura surgeries.",
      "Food, shelter and supplies for families in Kariba.",
      "Court hire, printing and prizes, so the events keep paying for themselves.",
      "Nothing to the doctors. They work pro bono and always have."
    ],
    paymentNote:
      "No EcoCash to this number. Payments sent by mistake will be refunded."
  },

  contact: {
    whatsapp: "+263 776 437 764",
    email: "charitysport@yahoo.com",
    emailSubject: "Charity Sports",
    social: [],
    whatsappMessages: {
      default: "Hello Charity Sports, I found you through your website.",
      register: "Hello Charity Sports, I would like to enter an event. Please send me the details.",
      sponsor: "Hello Charity Sports, I am interested in taking a sponsor slot.",
      portrait: "Hello Charity Sports, I would like to book the portrait photo session.",
      monthly: "Hello Charity Sports, I would like to become a Monthly Friend.",
      golf: "Hello Charity Sports, please let me know when the Charity Golf Day is confirmed."
    }
  }
};
