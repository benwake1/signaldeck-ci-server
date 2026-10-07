# SignalDeck Flow Recorder (browser extension)

Replaces the old bookmarklet-based flow recorder. A content script is
auto-injected on every page, so a recording session survives full page
navigation with no re-click required — the one thing the bookmarklet
couldn't do.

## How it works

- `content-bridge.js` runs only on the dashboard itself. When the AI Test
  Builder starts a recording, it dispatches a `signaldeck-recording-started`
  browser event (token, API base URL, expiry) which this script mirrors into
  `chrome.storage.local`.
- `content-recorder.js` runs on every other page. On load (and on every
  `chrome.storage` change) it checks for an active, non-expired session and,
  if present, shows the recording overlay and captures clicks/changes/submits
  exactly like the old bookmarklet script did — same selector strategy, same
  password/sensitive-field exclusion.
- `background.js` just keeps the toolbar badge ("REC") in sync.
- `popup.html`/`popup.js` show current status and let you stop & save a
  recording without needing to be on the target page's overlay.

## Installing (development / unpacked)

Chrome does not allow extensions to install themselves — this is a one-time
manual step per machine:

1. Go to `chrome://extensions`.
2. Turn on **Developer mode** (top right).
3. Click **Load unpacked** and select this `browser-extension/` folder.

That's it — no per-recording setup after this.

## Before deploying to production

`manifest.json`'s `content_scripts[0].matches` lists the dashboard origin(s)
where `content-bridge.js` runs:

```json
"matches": [
    "https://*.signaldeck.app/*"
]
```

Replace `*.signaldeck.app` with your real dashboard domain (and add your local
dev domain, e.g. `https://signaldeck.test/*`, if you record locally). The
recorder itself needs no changes: it reads the dashboard origin from the
recording session and never records on the dashboard.
