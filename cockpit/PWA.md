# SpiderNetOS Cockpit — PWA & Android APK

The cockpit is an installable Progressive Web App: full-screen standalone window,
home-screen/desktop icon, offline fallback, and web-push (the service worker also
powers `useWebPush.js`).

## What's in the box

| Piece | File | Notes |
|---|---|---|
| Manifest | `public/manifest.webmanifest` | Relative `start_url`/`scope` — works at `/` (prod) and `/cockpit/` (dev) |
| Service worker | `public/sw.js` | Push + notification click; network-first navigation with `offline.html` fallback; asset caching |
| Offline page | `public/offline.html` | Branded "you're offline" screen |
| Icons | `public/icons/*.png`, `public/icon.svg` | Regenerate: `npm i --no-save sharp && node scripts/generate-icons.mjs` |
| SW registration | `src/main.js` | Registered at `BASE_URL` so scope matches the deploy base |
| Install prompt | `src/components/InstallPrompt.vue` + `src/composables/usePwaInstall.js` | Custom banner, bottom-right |
| TWA config | `twa-manifest.json` | Bubblewrap input for the Android APK |
| Asset links | `public/.well-known/assetlinks.json` | Needs your signing-key fingerprint (see below) |

## Install prompt behavior

- **Chromium (desktop + Android):** we intercept `beforeinstallprompt` and show our own
  banner ~2.5 s later ("Install app" → native install dialog). Dismissing snoozes it for
  14 days (`localStorage: snos.installPrompt.dismissedAt`).
- **iOS Safari:** no install API exists, so after 5 s the banner shows Share → Add to
  Home Screen instructions instead.
- Never shows when already running standalone, or on public share pages (`/share/trace/…`).

## Prerequisites for install to work

1. **HTTPS** (or `localhost`). ⚠️ As of 2026-08-05 the prod cockpit nginx site
   (`sites-enabled/spidernetos-cockpit`) listens on **:80 only** — installability and the
   APK both require valid TLS on `cockpit.spidernetos.com` first (nginx cert or
   Cloudflare proxy once DNS is repointed).
2. The deploy already ships `manifest.webmanifest`, `sw.js`, `offline.html`, `icons/` and
   `.well-known/` because they live in `public/` and Vite copies them into `dist/`.

Quick check after deploying: Chrome DevTools → Application → Manifest (no errors,
"installable"), and Service Workers (activated).

## Building the Android APK

The APK is a **Trusted Web Activity** — a thin Android shell that renders
`https://cockpit.spidernetos.com` full-screen with no browser UI. The site must be
live over HTTPS before either route works.

### Route A — PWABuilder (no local toolchain)

1. Go to <https://www.pwabuilder.com>, enter `https://cockpit.spidernetos.com`.
2. Fix anything it flags, then **Package for stores → Android**.
   Package ID: `com.apexsynchronia.spidernetos`.
3. Download the zip: it contains a signed `.apk` (sideload/test) and `.aab` (Play Store),
   plus the exact `assetlinks.json` to publish — copy its fingerprint into
   `public/.well-known/assetlinks.json` and redeploy.

### Route B — Bubblewrap CLI (repeatable, config committed here)

```bash
npx @bubblewrap/cli init --manifest https://cockpit.spidernetos.com/manifest.webmanifest
# …or reuse the committed twa-manifest.json and skip straight to:
npx @bubblewrap/cli build
```

- First run offers to auto-install its own JDK + Android SDK (multi-GB download).
- `build` creates/uses `android.keystore` (keep it out of git; back it up — Play updates
  require the same key) and emits `app-release-signed.apk` + `app-release-bundle.aab`.

### Digital Asset Links (removes the URL bar)

The APK runs but shows a browser chrome strip until Android can verify you own the
domain. Get your signing key's fingerprint and publish it:

```bash
keytool -list -v -keystore android.keystore -alias spidernetos | grep SHA256
```

Paste it into `public/.well-known/assetlinks.json` (replacing the placeholder), deploy,
and verify at
`https://cockpit.spidernetos.com/.well-known/assetlinks.json`.
PWABuilder's zip includes the same file pre-filled if you used Route A.

### Direct-download "Get the app" link

To offer the APK from the site itself (sideload, outside Play), host the signed APK at
e.g. `/downloads/spidernetos.apk` and link it — Android will warn about unknown sources;
Play Store distribution avoids that.
