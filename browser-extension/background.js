/**
 * Keeps the toolbar badge in sync with whether a recording session is
 * currently armed, so the user can tell at a glance without opening a tab.
 */
function refreshBadge() {
    chrome.storage.local.get('activeRecording', function (data) {
        var active = data.activeRecording;
        var isLive = active && active.expiresAt && active.expiresAt > Date.now();

        chrome.action.setBadgeText({ text: isLive ? 'REC' : '' });
        chrome.action.setBadgeBackgroundColor({ color: '#ef4444' });
    });
}

chrome.storage.onChanged.addListener(function (changes, area) {
    if (area === 'local' && changes.activeRecording) {
        refreshBadge();
    }
});

chrome.runtime.onStartup.addListener(refreshBadge);
chrome.runtime.onInstalled.addListener(refreshBadge);
refreshBadge();
