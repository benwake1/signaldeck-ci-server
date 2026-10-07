(function () {
    var dot = document.getElementById('dot');
    var statusEl = document.getElementById('status');
    var hint = document.getElementById('hint');
    var stopBtn = document.getElementById('stop');

    function render() {
        chrome.storage.local.get('activeRecording', function (data) {
            var active = data.activeRecording;
            var isLive = active && active.expiresAt && active.expiresAt > Date.now();

            if (!isLive) {
                dot.className = 'dot';
                statusEl.textContent = 'No active recording';
                hint.textContent = 'Start a recording from the AI Test Builder to begin.';
                stopBtn.hidden = true;
                return;
            }

            dot.className = 'dot live';
            statusEl.textContent = 'Recording in progress';
            hint.textContent = 'Navigate freely — every page is captured automatically.';
            stopBtn.hidden = false;
            stopBtn.onclick = function () {
                stopBtn.disabled = true;
                stopBtn.textContent = 'Saving…';
                fetch(active.apiBase + '/api/v1/recordings/' + active.token + '/complete', { method: 'POST' })
                    .catch(function () {})
                    .finally(function () {
                        chrome.storage.local.remove('activeRecording', render);
                    });
            };
        });
    }

    chrome.storage.onChanged.addListener(function (changes, area) {
        if (area === 'local' && changes.activeRecording) render();
    });

    render();
})();
