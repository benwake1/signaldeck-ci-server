/**
 * Extension port of resources/js/test-recorder.js. Runs on every page
 * (see manifest.json) so a recording session survives full page navigation
 * with no re-click required — the one thing the bookmarklet couldn't do.
 *
 * Reads the active session from chrome.storage.local (written by
 * content-bridge.js when the builder starts a recording) instead of a
 * token embedded in a <script src>. Everything else — selector computation,
 * sensitive-field detection, the overlay, per-action POSTs — mirrors the
 * bookmarklet script exactly so both stay behaviorally identical.
 */
(function () {
    var DASHBOARD_ORIGINS = ['https://cypress-dashboard-new.test'];
    if (DASHBOARD_ORIGINS.indexOf(location.origin) !== -1) {
        return;
    }

    if (window.__signaldeckRecorderActive) {
        return;
    }
    window.__signaldeckRecorderActive = true;

    var state = null; // { token, apiBase, expiresAt }
    var stepCount = 0;
    var finished = false;
    var listenersAttached = false;
    var host, shadow, statusEl, finishBtn;

    // Priority chain for a single element: data-testid -> id -> name ->
    // aria-label -> text. Returns null (not a bare tag name) when none of
    // these apply, so the caller can try a better ancestor before falling
    // back to something as weak as a tag name.
    function computeSelectorFor(el) {
        var tag = el.tagName ? el.tagName.toLowerCase() : '';

        var testId = el.getAttribute && el.getAttribute('data-testid');
        if (testId) {
            return '[data-testid="' + testId + '"]';
        }

        if (el.id) {
            return '#' + CSS.escape(el.id);
        }

        var name = el.getAttribute && el.getAttribute('name');
        if (name && (tag === 'input' || tag === 'select' || tag === 'textarea')) {
            return tag + '[name="' + CSS.escape(name) + '"]';
        }

        var ariaLabel = el.getAttribute && el.getAttribute('aria-label');
        if (ariaLabel) {
            return tag + '[aria-label="' + ariaLabel.replace(/"/g, '\\"') + '"]';
        }

        var text = (el.textContent || '').trim().slice(0, 40);
        if (text) {
            return tag + ':has-text("' + text.replace(/"/g, '\\"') + '")';
        }

        return null;
    }

    // A click landing on an icon-only element (an inline <svg><path>, for
    // example) has no text and no attributes of its own — computeSelectorFor
    // returns null and, unhandled, the recorder would fall back to a bare
    // tag name like "path", which is both meaningless and non-unique.
    // Instead walk up to the nearest real interactive ancestor (a link,
    // button, or role="button"/"link") and describe that instead, since
    // that's almost always what the user actually meant to click.
    function nearestInteractive(el) {
        var current = el.parentElement;
        for (var i = 0; i < 6 && current; i++) {
            var tag = current.tagName ? current.tagName.toLowerCase() : '';
            var role = current.getAttribute && current.getAttribute('role');
            if (tag === 'a' || tag === 'button' || role === 'button' || role === 'link') {
                return current;
            }
            current = current.parentElement;
        }
        return null;
    }

    function isVisible(el) {
        if (!el || !el.getBoundingClientRect) return false;
        var rect = el.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) return false;
        var cs = getComputedStyle(el);
        return cs.visibility !== 'hidden' && cs.display !== 'none' && parseFloat(cs.opacity) !== 0;
    }

    // Best-effort disambiguation for the bare-tag-name fallback case (e.g.
    // "img", "path") — records which occurrence of that tag on the page this
    // was, so generation can target it precisely (`.nth(i)`) instead of
    // guessing `.first()`, which is exactly what grabbed a hidden decoy
    // image ahead of the real one in a past generation.
    function indexAmongSameTag(el) {
        try {
            var tag = el.tagName.toLowerCase();
            var all = document.getElementsByTagName(tag);
            for (var i = 0; i < all.length; i++) {
                if (all[i] === el) {
                    return { index: i, count: all.length };
                }
            }
        } catch (e) {}
        return null;
    }

    // Resolves the real click target and its selector together — walking up
    // to an interactive ancestor when needed — so the caller can also pull
    // href/visibility/index metadata from the *same* element the selector
    // actually describes.
    function resolveClickTarget(el) {
        var direct = computeSelectorFor(el);
        if (direct) {
            return { selector: direct, target: el, lowConfidence: false };
        }

        var ancestor = nearestInteractive(el);
        if (ancestor) {
            var ancestorSelector = computeSelectorFor(ancestor);
            if (ancestorSelector) {
                return { selector: ancestorSelector, target: ancestor, lowConfidence: false };
            }
            var ancestorTag = ancestor.tagName.toLowerCase();
            return { selector: ancestorTag, target: ancestor, lowConfidence: true };
        }

        var tag = el.tagName ? el.tagName.toLowerCase() : 'unknown';
        return { selector: tag, target: el, lowConfidence: true };
    }

    function isSensitive(el) {
        if ((el.type || '').toLowerCase() === 'password') {
            return true;
        }
        var probe = [el.name, el.autocomplete, el.id].filter(Boolean).join(' ').toLowerCase();
        return /card|cvv|cvc|ccv|ssn|social.?security|passport|secret/.test(probe);
    }

    function send(action) {
        if (!state) return;
        action.url = location.href;
        action.timestamp = Date.now();
        stepCount++;
        updateOverlay();

        fetch(state.apiBase + '/api/v1/recordings/' + state.token + '/actions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(action),
        }).catch(function () {});
    }

    function ensureOverlay() {
        if (host) return;

        host = document.createElement('div');
        host.style.cssText = 'all:initial;position:fixed;bottom:16px;right:16px;z-index:2147483647;';
        document.documentElement.appendChild(host);

        shadow = host.attachShadow({ mode: 'open' });
        shadow.innerHTML =
            '<style>' +
            '.panel{font-family:system-ui,sans-serif;font-size:13px;background:#111827;color:#fff;' +
            'padding:10px 14px;border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.3);display:flex;' +
            'align-items:center;gap:10px;}' +
            '.dot{width:8px;height:8px;border-radius:50%;background:#ef4444;animation:pulse 1.2s infinite;}' +
            '@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}' +
            'button{font:inherit;background:#2563eb;color:#fff;border:none;padding:5px 10px;' +
            'border-radius:6px;cursor:pointer;}' +
            'button:hover{background:#1d4ed8;}' +
            '</style>' +
            '<div class="panel">' +
            '<span class="dot"></span>' +
            '<span id="sd-status">Connecting…</span>' +
            '<button id="sd-finish" type="button" hidden>Finish</button>' +
            '</div>';

        statusEl = shadow.getElementById('sd-status');
        finishBtn = shadow.getElementById('sd-finish');

        finishBtn.addEventListener('click', function () {
            finishRecording();
        });
    }

    function teardownOverlay() {
        if (host) {
            host.remove();
        }
        host = shadow = statusEl = finishBtn = null;
    }

    function updateOverlay() {
        if (!statusEl) return;
        statusEl.innerHTML = 'Recording — <span id="sd-count">' + stepCount + '</span> step(s)';
    }

    function finishRecording() {
        if (!state || finished) return;
        finished = true;
        if (finishBtn) finishBtn.hidden = true;
        if (statusEl) statusEl.textContent = 'Saving…';

        fetch(state.apiBase + '/api/v1/recordings/' + state.token + '/complete', { method: 'POST' })
            .catch(function () {})
            .finally(function () {
                chrome.storage.local.remove('activeRecording');
                if (statusEl) statusEl.textContent = 'Saved — return to the builder.';
            });
    }

    function attachListeners() {
        if (listenersAttached) return;
        listenersAttached = true;

        document.addEventListener(
            'click',
            function (e) {
                if (finished || (host && host.contains(e.target))) return;

                var resolved = resolveClickTarget(e.target);
                var action = { type: 'click', selector: resolved.selector };

                if (resolved.target.tagName === 'A') {
                    var href = resolved.target.getAttribute('href');
                    if (href && href !== '#' && href.indexOf('javascript:') !== 0) {
                        try {
                            action.href = new URL(href, location.href).href;
                        } catch (err) {}
                    }
                }

                action.visible = isVisible(resolved.target);

                if (resolved.lowConfidence) {
                    var match = indexAmongSameTag(resolved.target);
                    if (match) {
                        action.matchIndex = match.index;
                        action.matchCount = match.count;
                    }
                }

                send(action);
            },
            true
        );

        document.addEventListener(
            'change',
            function (e) {
                var el = e.target;
                if (finished || (host && host.contains(el))) return;
                var tag = (el.tagName || '').toLowerCase();
                if (tag !== 'input' && tag !== 'select' && tag !== 'textarea') return;

                var sensitive = isSensitive(el);
                var selector = computeSelectorFor(el) || tag;
                var action = { type: 'change', selector: selector, sensitive: sensitive };
                if (!sensitive) {
                    action.value = String(el.value == null ? '' : el.value).slice(0, 500);
                }
                send(action);
            },
            true
        );

        document.addEventListener(
            'submit',
            function (e) {
                if (finished) return;
                var tag = e.target.tagName ? e.target.tagName.toLowerCase() : 'unknown';
                send({ type: 'submit', selector: computeSelectorFor(e.target) || tag });
            },
            true
        );
    }

    function startFor(session) {
        state = session;
        finished = false;
        listenersAttached = false;
        stepCount = 0;
        ensureOverlay();
        statusEl.textContent = 'Connecting…';
        finishBtn.hidden = true;

        fetch(state.apiBase + '/api/v1/recordings/' + state.token)
            .then(function (res) {
                if (!res.ok) throw new Error('status ' + res.status);
                return res.json();
            })
            .then(function (data) {
                if (data.status === 'recording') {
                    stepCount = data.step_count || 0;
                    updateOverlay();
                    finishBtn.hidden = false;
                    attachListeners();
                } else if (data.status === 'completed') {
                    statusEl.textContent = '✅ Already finished — ' + data.step_count + ' step(s) saved.';
                    chrome.storage.local.remove('activeRecording');
                } else {
                    statusEl.textContent = '⚠️ Recording session has expired.';
                    chrome.storage.local.remove('activeRecording');
                }
            })
            .catch(function () {
                statusEl.textContent = "Couldn't verify session — recording anyway";
                finishBtn.hidden = false;
                attachListeners();
            });
    }

    function checkActiveSession() {
        chrome.storage.local.get('activeRecording', function (data) {
            var active = data.activeRecording;
            var isLive = active && active.token && active.expiresAt && active.expiresAt > Date.now();

            if (isLive && (!state || state.token !== active.token)) {
                startFor(active);
            } else if (!isLive && state) {
                state = null;
                teardownOverlay();
            }
        });
    }

    chrome.storage.onChanged.addListener(function (changes, area) {
        if (area === 'local' && changes.activeRecording) {
            checkActiveSession();
        }
    });

    checkActiveSession();
})();
