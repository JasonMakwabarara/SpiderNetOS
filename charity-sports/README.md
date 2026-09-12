# Charity Sports

The website for Charity Sports, Harare. We raise money through sport for
whatever causes need it at the time, so nothing on the site is tied to one
sport or one cause: padel is running now, a golf day is next, and the causes
change as they are funded.

There are two halves, and they are deliberately independent.

| | What it is | Needs |
| --- | --- | --- |
| **The website** | Plain HTML, CSS and JavaScript. No build step. | Any web host, including free ones |
| **The admin panel** | A small server so staff can edit the site themselves | Somewhere that runs Node.js |

**The website never depends on the admin panel.** If the server is switched
off, unpaid or never set up, the site carries on exactly as before. Only the
ability to edit it from a browser goes away.

---

## Editing the site without the admin panel

Everything the site says lives in one file: **`data/site-data.js`**. Open it,
change the words, save, and the site changes.

You can edit it straight on GitHub: open the file, press the pencil icon, make
the change, and press **Commit changes**. The live site updates about a minute
later.

### The rules

- **Keep the quotes and the commas.** A missing comma stops the whole page
  rendering. If the page goes blank, press F12 in the browser and the console
  will name the line. On GitHub, the **History** tab undoes any change.
- Dates look like `"2026-09-17"`, which is year, then month, then day.
- Times look like `"19:00"`, on a 24-hour clock, in Harare time.
- Money is a plain number: `3200`, not `"US$3,200"`.
- `null` means "we do not have this yet" and hides that part of the page.
- `"active": false` hides something without deleting it.

### Changing the lives-helped number

Find `impact` near the top and change two lines:

```js
impact: {
  livesHelped: 20,               // ← the big number on the front page
  goal: 1000000,
  lastUpdated: "2026-09-12",     // ← set this to today
```

This number is kept by hand. It is not counted automatically by anything, so
move it when a cause has actually been delivered, not when money is promised.

### Adding an event

Copy an existing block inside `events` and change it. An event with no date
yet still belongs on the site: leave the dates as `null` and it shows
"date to be announced" with a button so people can register interest.

```js
{
  id: "charity-golf-day",
  title: "Charity Golf Day",
  sport: "Golf",
  startDate: null,                        // no date yet
  dateNote: "Date to be announced",
  summary: "A full day of golf raising money for whichever causes are open.",
  sessions: [],
  venue: null,
  ctas: [{ label: "Register interest", href: "whatsapp:golf" }],
  order: 1,
  active: true
}
```

For something that runs over several days, list each one under `sessions` and
the site builds the schedule, works out which nights have been played, and
counts down to the next.

### Adding a cause

Copy a block inside `causes`. Set `status` to `"current"` while you are raising
for it. When it is paid for, change it to `"funded"` and it moves to the
"what we have funded" list underneath instead of disappearing.

Leave `targetUsd` as `null` for an open-ended appeal, like Kariba, where you
send money as it comes in rather than waiting for a total.

### Adding a sponsor

Copy a block inside `sponsors`.

```js
{
  id: "new-sponsor",
  name: "Their Name",
  tier: "supporter",        // or "prize"
  tagline: "Their strapline",
  logo: null,               // null is fine: they get a name tile instead
  logoWebp: null,
  logoBg: "dark",
  link: null,
  order: 16,
  active: true
}
```

A sponsor with no logo file shows as a styled name tile, which looks
deliberate rather than broken. Add the logo whenever it arrives.

### Adding a photo or a video

Copy a block inside `gallery`. Photos and posters need `src` and `alt`. A
video needs `type: "video"`, `provider: "youtube"` and the YouTube address in
`youtubeId`.

`alt` is the sentence a blind visitor hears in place of the picture. It is not
optional and the site will tell you so.

### Telling people what you can be held to

`accountability` holds the facts a donor checks before giving: your trust
registration number, who answers questions about money, whether you issue a
receipt, and a few short promises.

**Every fact ships empty and the section hides itself until you fill something
in.** Nothing unverified is ever shown. Fill in one field and it appears.

```js
accountability: {
  registrationNumber: null,     // ← your trust number, once you have it in front of you
  financeContactName: null,     // ← who a donor asks about money
  receiptsPolicy: null,         // ← say plainly whether they get one
```

This is the single most valuable thing on the list still to complete. You are
asking strangers for money; this is what lets them check you.

### Showing how much has been raised

Two numbers, both kept by hand like the lives figure.

```js
impact: {
  raisedTotalUsd: null,   // ← total raised, shown beside the counter
```

```js
causes: [{
  targetUsd: 3200,
  raisedUsd: null,        // ← put a figure here and a progress bar appears
```

Both are empty on purpose. An invented number would be worse than none. Put
real figures in and people can see momentum, which is what moves a donation.

### Changing where the Donate button goes

One line, in `donate`:

```js
donate: {
  url: "https://donate.contipay.co.zw/",   // ← every Donate button uses this
```

Change it and check it. Every Donate button on the site points at this
one value.

### The share button and the sign-up form

`share.message` is what gets written into the message when somebody taps the
share button. The web address is added automatically. In Zimbabwe most people
find the site through a WhatsApp forward, so this is worth a sentence of care.

`signup` is the short form asking people to leave a name so you can tell them
when the next event is on. **It only appears when the admin server is
reachable.** On a plain static host there is nowhere to put a name, so the
website shows a WhatsApp link instead and nothing is lost.

### Adding your own pictures without the admin panel

Put the file in `assets/img/`, keep it under about 1400 pixels wide, and
reference it by its path from the site root, for example
`"assets/img/my-photo.jpg"`. Do not start the path with a slash.

The admin panel resizes pictures for you; doing it by hand means you have to
keep them small yourself, or the site gets slow on a phone.

---

## Editing the site with the admin panel

Once the server is running, staff go to **`/admin`** on that address, sign in,
make changes, and press **Publish**.

### The first sign-in

Whoever set up the server ran `npm run setup`, which printed a password once.
Sign in with it and the panel makes you choose your own before it will let you
do anything else. That is deliberate: the setup password was generated for the
handover and should not be kept.

### The screens

| Screen | What it is for |
| --- | --- |
| Dashboard | The lives number, a summary, and the jobs people do most |
| Impact numbers | The counter, the goal, the milestones, the small print |
| Causes | What you are raising for. Mark one funded when it is done |
| Events | Anything you are putting on, dated or not |
| Sponsors | Everyone standing with you, with logo uploads |
| Gallery | Photos, posters and videos |
| Ways to support | The cards telling people how they can help |
| Front page top, About, Donations, Contact, Organisation | The words |
| Accountability | Your registration number, who to ask about money, receipts |
| Sharing, Sign-up form | The wording people see when they share or sign up |
| Supporters | Everyone who left their details, with a spreadsheet download |
| People | Who can sign in |
| History | Every sign-in, edit, upload and publish, filtered by person or action |

### What Publish does

Nothing you save appears on the public site until you press **Publish**. The
badge at the top says whether the live site is behind. Publishing writes your
saved changes into `data/site-data.js`, which is the same file described
above, so both ways of editing stay in step.

If the server is set up to publish to GitHub, it also commits that file and
the live site rebuilds in about a minute.

### Taking a backup

**Dashboard → Backup → Download a backup** gives you a file holding all the
words, numbers, events and sponsors. Take one before any big change.

Restoring is on the same card. It replaces everything, asks you to confirm
first, and saves your current content before it does, so a restore can itself
be undone from the server. Nothing reaches the public site until you press
Publish afterwards.

The file does not contain the pictures themselves, only the references to
them, and it never contains accounts or passwords.

### Adding more people

Start with the one shared account, but move off it as soon as more than one
person is editing. **People → Add someone** creates an account and shows a
password once. Send it through something other than the message carrying the
web address, and they will be asked to pick their own on first sign-in.

The History screen then shows who changed what, which is the real reason for
individual accounts.

---

## Running it

### Just the website, free, no server

Push to GitHub, then **Settings → Pages → Source → GitHub Actions**. The
workflow in `.github/workflows/deploy-pages.yml` does the rest on every push
to `main`.

The admin panel does not work in this mode. Editing means changing
`data/site-data.js` as described above.

### The website and the admin panel

Any host that runs Node.js: Render, Railway, Fly, or a plain server.

```bash
npm ci --omit=dev
node server/scripts/setup-admin.js     # once, prints the first password
npm start
```

Set these before starting:

| Setting | Why |
| --- | --- |
| `SESSION_SECRET` | Signs the login cookie. At least 32 random characters. Required. |
| `CONTENT_DIR`, `UPLOAD_DIR` | Must be on a disk that survives a restart |
| `TRUST_PROXY=1`, `COOKIE_SECURE=true` | When something else handles HTTPS in front |

`.env.example` lists every setting with an explanation.

**The persistent disk is not optional.** On a host with a temporary
filesystem, which is the default on most free tiers, every edit, uploaded
logo, account and history entry is lost the next time the container restarts.
The only thing that survives is whatever Publish already pushed to GitHub. If
you cannot have a disk, set `PUBLISH_TARGET=github` and publish after every
session, and understand that you are relying on it.

A `Dockerfile` is included for Fly or a plain server.

### Moving this into its own repository

```bash
./tools/move-to-own-repo.sh https://github.com/YOUR-NAME/charity-sports.git
```

The site is self-contained, so this is the whole job. Afterwards, change
`org.siteUrl` in `data/site-data.js` to the address you end up with. That one
value now feeds the sharing card, the canonical link, `robots.txt` and
`sitemap.xml`, which the server rewrites when you publish. There is nothing to
edit in the HTML by hand.

---

## When something goes wrong

**The page is blank.** `data/site-data.js` has a syntax error, almost always a
missing comma or quote. Press F12 and read the console; it names the line. On
GitHub, use the History tab to undo.

**Nobody can sign in.** On the server:

```bash
npm run reset-password -- admin
```

That prints a new password once and clears any lockout. Five wrong passwords
lock an account for fifteen minutes, doubling each time, and the lock survives
a restart, so waiting it out is not quick.

**An upload is refused.** The server checks what a file actually is rather than
trusting its name. Export it again as a PNG or JPEG. Uploaded SVGs are turned
into PNGs on purpose: an SVG is a document that can carry scripts, and a logo
does not need to be one.

**Publish says it saved but did not commit.** The GitHub token is missing or
expired. The change is safe on the server; fix the token and publish again.

**Edits vanished after a restart.** The host has no persistent disk. See above.

---

## Security, in plain terms

What is in place: passwords are hashed with argon2id and never stored or
logged in readable form; sessions live on the server, so signing out really
ends them; repeated wrong passwords lock the account and the lock survives a
restart; a wrong password and an unknown username give exactly the same
answer, so the login cannot be used to discover who has an account; every
change is recorded with who made it; uploads are checked by their contents
rather than their filename.

What is not: there is no two-factor authentication in this version. Anyone who
learns an admin password can sign in. Do not reuse a password from elsewhere,
and do not send it in the same message as the web address. If the server
itself is compromised, everything on it is compromised, including the history.

Turn on HSTS only once the site genuinely serves HTTPS everywhere. Setting it
on a server without a certificate locks people out of their own site.

---

## For developers

```
index.html              the whole public page
data/site-data.js       all content; committed; also the fallback
assets/css, assets/js   no build step, no dependencies in the browser
admin/                  the panel: vanilla JS, no framework
server/                 Express, JSON on disk, argon2id, no database
content/                the live store at runtime (gitignored, seed committed)
tools/                  image pipeline and browser verification
```

```bash
npm test                             # server tests, node:test
node tools/check-contrast.cjs        # every colour pair against WCAG AA
npm run seed                         # rebuild the seed from data/site-data.js
python3 -m http.server 8080          # serve the static site on its own
node tools/verify-public.cjs --url http://127.0.0.1:8080/
node tools/verify-admin.cjs          # full sign-in to publish round trip
```

The page renders from `window.CHARITY_DATA` immediately, then quietly asks the
API for anything newer. Every failure of that request, including the 404 you
get on a static host, leaves the built-in content exactly as it is. That is
what makes the server optional rather than load-bearing.

`sw.js` sits at the site root on purpose: a service worker can only control
pages at or below its own directory, so one served from `assets/` would cover
nothing. It never touches `/admin` or `/api`, and the content file and the page
itself are fetched network-first so a published edit is never hidden behind a
cached copy.

The head tags between the `META` markers in `index.html` are generated by the
publisher from `org.siteUrl`. Do not hand-edit them; they are overwritten.

---

## Still to confirm

- The exact Contipay donation page. `donate.url` holds a placeholder.
- The accountability details: trust registration number, who answers questions
  about money, and your receipt policy. The section stays hidden until these
  arrive, so the site is not claiming anything it cannot back up.
- A real fundraising total, so visitors can see that other people have given.
- The golf day's date, course and format.
- One sponsor on the comic posters is illegible in the photograph, something
  ending "ship Hub". It is in the data as inactive until somebody names it.
- The Hannah AI logo was cropped from a phone screenshot, which is the weakest
  image on the page. An original file would be better.
