/**
 * Typing Indicator plugin for GLPI (10, 11, 12)
 *
 * - Detects when the current user types in a followup / task / solution
 *   editor (plain <textarea> or TinyMCE) and sends a heartbeat.
 * - Polls the server and shows "Jane Doe is typing a comment…" right below
 *   the "Answer / Add a followup" area.
 *
 * @license GPLv3+
 */
(function () {
    'use strict';

    // ---------------------------------------------------------------
    // Resolve plugin base URL from this script's own URL. Works for
    // /plugins/... and /marketplace/..., GLPI 10 (public/js/...) and
    // GLPI 11+ (js/...).
    // ---------------------------------------------------------------
    var script = document.currentScript;
    var base = null;
    if (script && script.src) {
        base = script.src.replace(/\/(public\/)?js\/typingindicator(\.min)?\.js(\?.*)?$/, '');
    }
    if (!base || base === script.src) {
        var root = (window.CFG_GLPI && window.CFG_GLPI.root_doc) ? window.CFG_GLPI.root_doc : '';
        base = root + '/plugins/typingindicator';
    }
    var ENDPOINT = base + '/ajax/typing.php';

    // ---------------------------------------------------------------
    // Only run on Ticket / Problem / Change forms of an existing item
    // ---------------------------------------------------------------
    var match = window.location.pathname.match(/\/front\/(ticket|problem|change)\.form\.php$/i);
    if (!match) {
        return;
    }
    var itemsId = parseInt(new URLSearchParams(window.location.search).get('id'), 10);
    if (!itemsId || itemsId <= 0) {
        return;
    }
    var itemtype = { ticket: 'Ticket', problem: 'Problem', change: 'Change' }[match[1].toLowerCase()];

    var PING_EVERY = 2500;   // ms between heartbeats while typing
    var IDLE_AFTER = 4000;   // ms without keystroke => "stopped typing"

    var caps = { can_see: false, can_broadcast: false };
    var pollInterval = 3000;
    var lastPing = 0;
    var idleTimer = null;
    var typingState = null; // {kind, is_private} while typing
    var box = null;
    var stopped = false;

    // ---------------------------------------------------------------
    // HTTP helpers
    // ---------------------------------------------------------------
    // GLPI 10/11: token sent in the X-Glpi-Csrf-Token header.
    // GLPI 12+: no token at all; the browser's Sec-Fetch-Site/Origin headers
    // are validated by GLPI, so nothing must be added (returns '').
    function csrfToken() {
        if (typeof window.getAjaxCsrfToken === 'function') {
            try { return window.getAjaxCsrfToken(); } catch (e) { /* ignore */ }
        }
        var meta = document.querySelector('meta[property="glpi:csrf_token"]');
        return meta ? (meta.getAttribute('content') || '') : '';
    }

    function request(method, params) {
        var body = new URLSearchParams(params);
        body.set('itemtype', itemtype);
        body.set('items_id', String(itemsId));

        var url = ENDPOINT;
        var opts = {
            method: method,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        };
        if (method === 'GET') {
            url += '?' + body.toString();
        } else {
            opts.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
            var token = csrfToken();
            if (token) { opts.headers['X-Glpi-Csrf-Token'] = token; }
            opts.body = body.toString();
        }
        return fetch(url, opts).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.json();
        });
    }

    // ---------------------------------------------------------------
    // Detecting what the user is writing
    // ---------------------------------------------------------------
    var KIND_BY_ACTION = [
        [/itilfollowup\.form\.php/i, 'followup'],
        [/(ticket|problem|change)task\.form\.php/i, 'task'],
        [/itilsolution\.form\.php/i, 'solution']
    ];

    function formInfo(el) {
        var form = el && el.closest ? el.closest('form') : null;
        if (!form) { return null; }
        var action = form.getAttribute('action') || '';
        for (var i = 0; i < KIND_BY_ACTION.length; i++) {
            if (KIND_BY_ACTION[i][0].test(action)) {
                var priv = form.querySelector('input[type="checkbox"][name="is_private"]');
                return { kind: KIND_BY_ACTION[i][1], is_private: priv && priv.checked ? 1 : 0, form: form };
            }
        }
        return null;
    }

    function onTyping(el) {
        if (stopped || !caps.can_broadcast) { return; }
        var info = formInfo(el);
        if (!info) { return; }

        var now = Date.now();
        var changed = !typingState || typingState.kind !== info.kind || typingState.is_private !== info.is_private;
        typingState = { kind: info.kind, is_private: info.is_private };

        if (changed || now - lastPing >= PING_EVERY) {
            lastPing = now;
            request('POST', { action: 'ping', kind: info.kind, is_private: info.is_private }).catch(function () {});
        }

        clearTimeout(idleTimer);
        idleTimer = setTimeout(sendStop, IDLE_AFTER);
    }

    function sendStop() {
        clearTimeout(idleTimer);
        if (!typingState) { return; }
        typingState = null;
        lastPing = 0;
        request('POST', { action: 'stop' }).catch(function () {});
    }

    // Plain textareas / inputs (rich text disabled, or mobile)
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (t && (t.tagName === 'TEXTAREA' || t.isContentEditable)) {
            onTyping(t);
        }
    }, true);

    // Leaving the page / submitting the form ends the "typing" state
    document.addEventListener('submit', function (e) {
        if (formInfo(e.target)) {
            sendStop();
        }
    }, true);
    window.addEventListener('pagehide', sendStop);

    // TinyMCE editors (GLPI 10 = TinyMCE 6, GLPI 11+ = TinyMCE 7)
    function hookEditor(editor) {
        if (!editor || editor.__typingIndicatorHooked) { return; }
        editor.__typingIndicatorHooked = true;
        editor.on('input keydown paste', function (e) {
            if (e.type === 'keydown') {
                var k = e.key || '';
                if (k.length !== 1 && k !== 'Backspace' && k !== 'Delete' && k !== 'Enter') { return; }
            }
            onTyping(editor.getElement());
        });
    }

    var tinymceTries = 0;
    (function hookTinymce() {
        if (window.tinymce && typeof window.tinymce.on === 'function') {
            (window.tinymce.get() || []).forEach(hookEditor);
            window.tinymce.on('AddEditor', function (e) { hookEditor(e.editor); });
            return;
        }
        if (++tinymceTries < 60) { setTimeout(hookTinymce, 500); }
    })();

    // ---------------------------------------------------------------
    // Rendering: always directly UNDER the comment (followup) editor
    // ---------------------------------------------------------------

    // The textarea of the "new followup" form (not the edit form of an
    // existing followup). GLPI keeps it in the page even while collapsed.
    function findCommentTextarea() {
        var forms = document.querySelectorAll('form[action*="itilfollowup.form.php"]');
        var fallback = null;
        for (var i = 0; i < forms.length; i++) {
            var ta = forms[i].querySelector('textarea[name="content"]');
            if (!ta) { continue; }
            var idInput = forms[i].querySelector('input[name="id"]');
            if (!idInput || !parseInt(idInput.value, 10)) { return ta; } // new followup
            if (!fallback) { fallback = ta; }
        }
        return fallback;
    }

    // The visible editor box: TinyMCE's container when rich text is on,
    // otherwise the textarea itself.
    function editorBox(ta) {
        if (window.tinymce && ta.id && typeof window.tinymce.get === 'function') {
            var ed = window.tinymce.get(ta.id);
            if (ed && typeof ed.getContainer === 'function' && ed.getContainer()) { return ed.getContainer(); }
        }
        var next = ta.nextElementSibling;
        if (next && next.classList && next.classList.contains('tox')) { return next; }
        return ta;
    }

    function ensureBox() {
        if (!box) {
            box = document.createElement('div');
            box.className = 'typingindicator';
            box.setAttribute('role', 'status');
            box.setAttribute('aria-live', 'polite');
            box.innerHTML =
                '<span class="typingindicator-dots" aria-hidden="true"><span></span><span></span><span></span></span>' +
                '<span class="typingindicator-text"></span>';
        }
        var ta = findCommentTextarea();
        if (ta) {
            var anchor = editorBox(ta);
            box.classList.remove('typingindicator-floating');
            if (anchor.nextElementSibling !== box) {
                anchor.insertAdjacentElement('afterend', box);
            }
        } else if (box.parentNode !== document.body) {
            // No comment box on this page (e.g. no right to add followups)
            box.classList.add('typingindicator-floating');
            document.body.appendChild(box);
        }
        return box;
    }

    function render(data) {
        var el = ensureBox();
        var text = el.querySelector('.typingindicator-text');
        if (data && data.message) {
            text.textContent = data.message;
            el.title = (data.typers || []).map(function (t) { return t.name; }).join(', ');
            el.classList.add('is-visible');
        } else {
            el.classList.remove('is-visible');
        }
    }

    // ---------------------------------------------------------------
    // Polling loop
    // ---------------------------------------------------------------
    function poll() {
        if (stopped) { return; }
        request('GET', { action: 'poll' })
            .then(function (data) {
                caps.can_see = !!data.can_see;
                caps.can_broadcast = !!data.can_broadcast;
                if (data.interval) { pollInterval = Math.max(1000, parseInt(data.interval, 10)); }

                if (!caps.can_see && !caps.can_broadcast) {
                    stopped = true; // no rights at all: stay silent
                    return;
                }
                if (caps.can_see) { render(data); }
                schedule();
            })
            .catch(function () {
                // Network glitch / session expired: back off
                pollInterval = Math.min(pollInterval * 2, 30000);
                schedule();
            });
    }

    function schedule() {
        if (stopped) { return; }
        var delay = document.hidden ? Math.max(pollInterval, 15000) : pollInterval;
        setTimeout(poll, delay);
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && !stopped) { poll(); }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', poll);
    } else {
        poll();
    }
})();
