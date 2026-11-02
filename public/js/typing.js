/**
 * Live typing indicator for GLPI tickets (plugin "livetyping").
 *
 * @copyright 2026 Aayush
 * @license   GPL-3.0-or-later
 *
 * Design rules:
 *  - Runs only on /front/ticket.form.php?id=<number>; anywhere else it does nothing at all.
 *  - Never builds HTML from server data: the label is shown with textContent only
 *    (no innerHTML / eval / document.write), so a hostile name cannot inject markup.
 *  - Talks only to the plugin's own endpoint (same origin), with GLPI's CSRF header.
 *  - Light on the server: one small poll per interval while the tab is visible (with a little random
 *    jitter so browsers do not all call at the same instant), at most one "typing" signal every few
 *    seconds, exponential back-off on errors.
 *  - Resilient: an error while drawing can never stall it; after repeated failures it pauses and
 *    resumes by itself when the tab is shown again, the network is back or the page is restored; it
 *    stops for good only when the server says "forbidden" or the login session is gone.
 */
(function () {
    'use strict';

    if (window.__livetyping) {
        return; // already loaded
    }
    window.__livetyping = true;

    if (typeof window.fetch !== 'function' || typeof window.Promise !== 'function' || typeof window.URL !== 'function') {
        return; // very old browser: no indicator, no errors
    }

    var PING_EVERY_MS   = 3000;   // at most one "I am typing" signal per 3 s
    var IDLE_STOP_MS    = 4000;   // no key for 4 s => "I stopped typing"
    var POLL_DEFAULT_MS = 5000;
    var POLL_MIN_MS     = 3000;
    var POLL_MAX_MS     = 60000;  // never slower than this, also the back-off ceiling
    var MAX_FAILURES    = 8;      // consecutive failures before pausing
    var MAX_THROTTLED   = 20;     // consecutive "slow down" answers before pausing
    var LABEL_MAX       = 200;
    var STALE_MS        = 15000;  // an old answer is not trusted any more (the server counts typing for 10 s)

    // ---- where are we? ---------------------------------------------------------------
    function ticketIdFromLocation() {
        var url;
        try {
            url = new URL(window.location.href);
        } catch (e) {
            return 0;
        }
        if (!/\/front\/ticket\.form\.php$/.test(url.pathname)) {
            return 0;
        }
        var id = url.searchParams.get('id');
        if (id === null || !/^[0-9]{1,9}$/.test(id)) {
            return 0;
        }
        var n = parseInt(id, 10);
        return n > 0 ? n : 0;
    }

    function findScript() {
        if (document.currentScript && document.currentScript.src) {
            return document.currentScript;
        }
        return document.querySelector('script[src*="/livetyping/js/typing.js"]');
    }

    var ticket = ticketIdFromLocation();
    var script = findScript();
    if (!ticket || !script || !script.src) {
        return;
    }

    // The endpoint lives next to the script, under the same plugin path (works for plugins/ and marketplace/).
    var endpoint = script.src.replace(/\/js\/typing\.js(?:\?.*)?$/, '/ajax/typing.php');
    try {
        var target = new URL(endpoint, window.location.href);
        if (endpoint === script.src || target.origin !== window.location.origin) {
            return; // unexpected script location or cross-origin: do nothing
        }
        endpoint = target.href;
    } catch (e) {
        return;
    }

    // ---- state -----------------------------------------------------------------------
    var pollInterval = POLL_DEFAULT_MS;
    var failures     = 0;
    var throttled    = 0;
    var inflight     = false;
    var refused      = false;   // the server said "not for you": never ask again
    var suspended    = false;   // temporary trouble: paused until something tells us to try again
    var pollTimer    = null;
    var idleTimer    = null;
    var typing       = false;
    var lastPing     = 0;
    var lastOk       = Date.now(); // last time the server answered properly
    var ui           = null;

    function csrfToken() {
        var meta = document.querySelector('meta[property="glpi:csrf_token"]');
        return meta ? (meta.getAttribute('content') || '') : '';
    }

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function jitter(ms) {
        return Math.round(ms * (0.9 + Math.random() * 0.2)); // +-10 %: spreads the load over time
    }

    // ---- display (text only) ---------------------------------------------------------
    function render(label) {
        if (typeof label !== 'string') {
            label = '';
        }
        label = label.slice(0, LABEL_MAX);

        if (ui === null) {
            if (label === '') {
                return;
            }
            var root = document.createElement('div');
            root.className = 'livetyping';
            root.setAttribute('role', 'status');
            root.setAttribute('aria-live', 'polite');
            root.hidden = true;

            var dots = document.createElement('span');
            dots.className = 'livetyping-dots';
            dots.setAttribute('aria-hidden', 'true');
            for (var i = 0; i < 3; i++) {
                dots.appendChild(document.createElement('i'));
            }

            var text = document.createElement('span');
            text.className = 'livetyping-text';

            root.appendChild(dots);
            root.appendChild(text);
            document.body.appendChild(root);
            ui = {root: root, text: text};
        }

        // Only touch the DOM when something changed: a screen reader must not re-announce
        // the same sentence at every refresh.
        if (ui.text.textContent !== label) {
            ui.text.textContent = label;
        }
        var hide = label === '';
        if (ui.root.hidden !== hide) {
            ui.root.hidden = hide;
        }
    }

    // ---- network ---------------------------------------------------------------------
    function request(method, body, keepalive) {
        var headers = {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };
        var options = {method: method, credentials: 'same-origin', cache: 'no-store', headers: headers};
        var url = endpoint;

        if (method === 'POST') {
            var token = csrfToken();
            if (token === '') {
                return Promise.reject(new Error('no csrf token'));
            }
            headers['Content-Type'] = 'application/x-www-form-urlencoded';
            headers['X-Glpi-Csrf-Token'] = token;
            options.body = body;
            if (keepalive) {
                options.keepalive = true;
            }
        } else {
            url += '?ticket=' + encodeURIComponent(String(ticket));
        }

        return fetch(url, options).then(function (response) {
            var retryAfter = 0;
            if (response.headers && typeof response.headers.get === 'function') {
                retryAfter = parseInt(response.headers.get('Retry-After'), 10) || 0;
            }
            var meta = {status: response.status, redirected: response.redirected === true, retryAfter: retryAfter};
            return response.json().then(
                function (data) { meta.data = data; return meta; },
                function () { meta.data = null; return meta; }
            );
        });
    }

    function giveUp() {
        refused = true;
        window.clearTimeout(pollTimer);
        window.clearTimeout(idleTimer);
        render('');
    }

    function suspend() {
        suspended = true;
        window.clearTimeout(pollTimer);
        render('');
    }

    // Something suggests the trouble may be over (tab shown, network back, page restored).
    function wake() {
        if (refused) {
            return;
        }
        if (suspended) {
            suspended = false;
            failures = 0;
            throttled = 0;
            pollInterval = POLL_DEFAULT_MS;
        }
        schedulePoll(500);
    }

    function handleResult(result) {
        var status = result.status;

        // Not allowed here, or the login session is gone (GLPI answers with a redirect to the login page)
        if (result.redirected || status === 401 || status === 403 || status === 404 || status === 405) {
            giveUp();
            return;
        }
        if (status === 429) {
            throttled++;
            expireStaleLabel();
            if (throttled >= MAX_THROTTLED) {
                suspend();
                return;
            }
            pollInterval = clamp(Math.max(pollInterval, result.retryAfter * 1000), POLL_MIN_MS, POLL_MAX_MS);
            return;
        }
        if (status >= 200 && status < 300 && result.data && typeof result.data === 'object') {
            failures = 0;
            throttled = 0;
            lastOk = Date.now();
            var poll = Number(result.data.poll);
            pollInterval = isFinite(poll) && poll > 0 ? clamp(poll * 1000, POLL_MIN_MS, POLL_MAX_MS) : POLL_DEFAULT_MS;
            if ('label' in result.data) {
                render(result.data.label);
            }
            if (suspended) { // a success proves the server is reachable again
                suspended = false;
                schedulePoll(pollInterval);
            }
            return;
        }
        failed();
    }

    // If the server stops answering, a "typing" label must not stay on screen for minutes.
    function expireStaleLabel() {
        if (Date.now() - lastOk > STALE_MS) {
            render('');
        }
    }

    function failed() {
        failures++;
        expireStaleLabel();
        if (failures >= MAX_FAILURES) {
            suspend();
            return;
        }
        pollInterval = clamp(pollInterval * 2, POLL_MIN_MS, POLL_MAX_MS); // exponential back-off
    }

    // ---- polling ---------------------------------------------------------------------
    function schedulePoll(delay) {
        window.clearTimeout(pollTimer);
        if (refused || suspended || document.visibilityState === 'hidden') {
            return;
        }
        pollTimer = window.setTimeout(poll, jitter(delay));
    }

    function poll() {
        if (refused || suspended || inflight || document.visibilityState === 'hidden') {
            return;
        }
        inflight = true;
        request('GET')
            .then(handleResult, failed)
            .catch(failed) // an error while handling or drawing counts as a failure, it never stalls the loop
            .then(function () {
                inflight = false;
                schedulePoll(pollInterval);
            });
    }

    // ---- my own typing ---------------------------------------------------------------
    function post(action, keepalive) {
        return request('POST', 'action=' + action + '&ticket=' + encodeURIComponent(String(ticket)), keepalive);
    }

    function onTyping() {
        if (refused) {
            return;
        }
        var now = Date.now();
        typing = true;

        window.clearTimeout(idleTimer);
        idleTimer = window.setTimeout(stopTyping, IDLE_STOP_MS);

        if (now - lastPing >= PING_EVERY_MS) {
            lastPing = now; // set before sending: a burst of keystrokes sends one request
            post('ping', false).then(handleResult, failed).catch(failed);
        }
    }

    function stopTyping(keepalive) {
        window.clearTimeout(idleTimer);
        if (!typing) {
            return;
        }
        typing = false;
        lastPing = 0;
        if (!refused) {
            post('stop', keepalive === true).then(function () {}, function () {});
        }
    }

    // ---- what counts as "typing" -----------------------------------------------------
    // Plain textareas named "content" (reply, task, solution) ...
    document.addEventListener('input', function (event) {
        var el = event.target;
        if (el && el.tagName === 'TEXTAREA' && el.getAttribute('name') === 'content') {
            onTyping();
        }
    }, true);

    // ... and the TinyMCE rich-text editors built on top of them.
    function hookEditor(editor) {
        if (!editor || editor.__livetyping || typeof editor.on !== 'function') {
            return;
        }
        var el = editor.targetElm;
        if (!el || typeof el.getAttribute !== 'function' || el.getAttribute('name') !== 'content') {
            return;
        }
        editor.__livetyping = true;
        editor.on('input', onTyping);
    }

    function watchTinyMce() {
        var tiny = window.tinymce;
        if (!tiny) {
            return false;
        }
        if (typeof tiny.on === 'function') {
            tiny.on('AddEditor', function (event) { hookEditor(event.editor); });
        }
        if (tiny.editors && typeof tiny.editors.forEach === 'function') {
            tiny.editors.forEach(hookEditor);
        }
        return true;
    }

    // TinyMCE may finish loading after this script: look for it a few times, then stop looking.
    var attempts = 0;
    (function waitForTinyMce() {
        if (!watchTinyMce() && ++attempts < 20) {
            window.setTimeout(waitForTinyMce, 500);
        }
    })();

    // Submitting any form (sending the reply) means "I am done typing".
    document.addEventListener('submit', function () { stopTyping(true); }, true);

    // Leaving the page: tell the server, and stop all timers.
    window.addEventListener('pagehide', function () {
        stopTyping(true);
        window.clearTimeout(pollTimer);
        window.clearTimeout(idleTimer);
    });

    // Back/forward cache: the page comes back alive but its timers are gone.
    window.addEventListener('pageshow', function (event) {
        if (event && event.persisted) {
            wake();
        }
    });

    // The network is back.
    window.addEventListener('online', wake);

    // Pause polling while the tab is hidden, catch up when it comes back.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            window.clearTimeout(pollTimer);
        } else {
            wake();
        }
    });

    schedulePoll(1000);
})();
