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

`manifest.json`'s `content_scripts[0].matches` hardcodes the dashboard's own
origin(s) so `content-bridge.js` only runs there:

```json
"matches": [
    "https://cypress-dashboard-new.test/*",
    "https://*.signaldeck.app/*"
]
```

Replace `*.signaldeck.app` with your real production domain before shipping
this to anyone other than local dev.
