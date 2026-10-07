/**
 * Runs only on the SignalDeck dashboard itself (see manifest.json matches).
 * Bridges the AI Test Builder page to the extension: the builder dispatches
 * plain window CustomEvents when a recording session starts/stops, and this
 * script mirrors that into chrome.storage.local so content-recorder.js
 * (running on the arbitrary third-party site being recorded) can pick it up
 * on every page load with no per-navigation action from the user.
 */
(function () {
    window.addEventListener('signaldeck-recording-started', function (e) {
        var detail = e.detail || {};
        chrome.storage.local.set({
            activeRecording: {
                token: detail.token,
                apiBase: detail.apiBase,
                expiresAt: detail.expiresAt,
            },
        });
    });

    window.addEventListener('signaldeck-recording-stopped', function () {
        chrome.storage.local.remove('activeRecording');
    });
})();
