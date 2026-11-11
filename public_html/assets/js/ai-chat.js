/* LOKA — AI assistant chat panel (Plan #40, experimental)
 *
 * Progressive enhancement: a launcher bubble that opens a fixed panel. All
 * calls go to ?page=api&action=ai_chat with the session cookie + CSRF token.
 * The page only renders this when the assistant is ready, so this file can
 * assume it is allowed to talk to the endpoint.
 */
(function () {
    'use strict';

    var API = (window.LOKA_APP_URL || '') + '/?page=api&action=ai_chat';
    var CSRF = window.LOKA_CSRF_TOKEN || '';
    var MAX = parseInt(window.LOKA_AI_MAX_PROMPT || 2000, 10);

    var panel, log, input, sendBtn, statusEl, bubble;
    var busy = false;
    var history = [];

    function el(id) { return document.getElementById(id); }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function addRow(who, html) {
        var row = document.createElement('div');
        row.className = 'd-flex mb-2 ' + (who === 'me' ? 'justify-content-end' : 'justify-content-start');
        var bubbleEl = document.createElement('div');
        bubbleEl.className = 'p-2 rounded-3 border small lh-sm';
        bubbleEl.style.maxWidth = '85%';
        bubbleEl.style.whiteSpace = 'pre-wrap';
        bubbleEl.style.background = who === 'me' ? '#0d6efd' : '#f8f9fa';
        bubbleEl.style.color = who === 'me' ? '#fff' : 'inherit';
        bubbleEl.innerHTML = html;
        row.appendChild(bubbleEl);
        log.appendChild(row);
        log.scrollTop = log.scrollHeight;
    }

    function addError(text) {
        addRow('bot', '<span class="text-danger">' + esc(text) + '</span>');
    }

    function setBusy(on) {
        busy = on;
        sendBtn.disabled = on;
        input.disabled = on;
        statusEl.textContent = on ? 'Thinking…' : '';
    }

    function post(body) {
        return fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
            body: JSON.stringify(Object.assign({ csrf_token: CSRF }, body))
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, error: 'Unexpected response from the server.' }; });
        });
    }

    /* ---------- action trace ---------- */

    /**
     * A live "what is the assistant doing right now" row. Every tool call gets
     * one — reads and writes alike — so nothing happens invisibly.
     */
    function addTrace(text) {
        var row = document.createElement('div');
        row.className = 'd-flex mb-2 justify-content-start';
        row.innerHTML =
            '<div class="p-2 rounded-3 border small lh-sm d-flex align-items-center gap-2"'
            + ' style="max-width:85%;background:#f1f3f5;">'
            + '<span class="spinner-border spinner-border-sm flex-shrink-0" role="status" aria-hidden="true"></span>'
            + '<span data-ai-trace-text>' + esc(text) + '</span></div>';
        log.appendChild(row);
        log.scrollTop = log.scrollHeight;
        return {
            el: row,
            finish: function (finalText) {
                var sp = row.querySelector('.spinner-border');
                if (sp) { sp.remove(); }
                var t = row.querySelector('[data-ai-trace-text]');
                if (t && finalText) {
                    t.innerHTML = '<i class="bi bi-check2 me-1"></i>' + esc(finalText);
                }
                log.scrollTop = log.scrollHeight;
            },
            fail: function (msg) {
                var sp = row.querySelector('.spinner-border');
                if (sp) { sp.remove(); }
                var t = row.querySelector('[data-ai-trace-text]');
                if (t) {
                    t.innerHTML = '<i class="bi bi-x-circle text-danger me-1"></i>' + esc(msg || 'Failed');
                }
                log.scrollTop = log.scrollHeight;
            }
        };
    }

    /* ---------- action cards: before/after diff + type-to-confirm ---------- */

    function renderActionCard(p) {
        if (!p.ok) {
            addError(p.error || 'That action is not possible right now.');
            return Promise.resolve();
        }

        var rows = '';
        var before = p.before || {};
        var after = p.after || {};
        Object.keys(after).forEach(function (k) {
            var b = before[k] === undefined ? '—' : String(before[k]);
            var a = after[k] === undefined ? '—' : String(after[k]);
            var changed = b !== a;
            rows += '<tr>'
                + '<td class="small text-muted pe-2">' + esc(k.replace(/_/g, ' ')) + '</td>'
                + '<td class="small pe-2 text-muted"><s>' + esc(b) + '</s></td>'
                + '<td class="small"><strong>' + esc(a) + '</strong>'
                + (changed ? ' <span class="badge bg-warning text-dark">changes</span>' : '') + '</td>'
                + '</tr>';
        });

        var card = document.createElement('div');
        card.className = 'card border-warning mb-2';
        card.innerHTML =
            '<div class="card-body p-2">'
            + '<div class="small fw-semibold mb-1"><i class="bi bi-shield-exclamation me-1"></i>This changes data</div>'
            + '<div class="small mb-2">' + esc(p.summary || '') + '</div>'
            + '<table class="table table-sm mb-2" style="font-size:.78rem">' + rows + '</table>'
            + '<div class="small text-muted mb-2">Nothing has run yet. To apply it, type '
            + '<code class="user-select-all">' + esc(p.phrase) + '</code> below.</div>'
            + '<div class="input-group input-group-sm mb-2">'
            + '<input type="text" class="form-control" data-ai-phrase placeholder="' + esc(p.phrase) + '"'
            + ' aria-label="Confirmation phrase">'
            + '<button class="btn btn-success" data-ai-run>Apply</button>'
            + '</div>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-ai-cancel>Cancel</button>'
            + '</div>';
        log.appendChild(card);
        log.scrollTop = log.scrollHeight;

        card.querySelector('[data-ai-cancel]').addEventListener('click', function () {
            card.remove();
            addRow('bot', 'Cancelled — nothing was changed.');
        });

        var run = function () {
            var typed = card.querySelector('[data-ai-phrase]').value;
            var trace = addTrace('Applying: ' + (p.trace || p.label || ''));
            setBusy(true);
            post({
                op: 'action', tool: p.tool, args: p.args,
                phrase: typed, prompt_ref: p.prompt_ref || 0
            }).then(function (r) {
                setBusy(false);
                if (!r.ok) {
                    trace.fail(r.error || 'The action was refused.');
                    return;
                }
                trace.finish('Done: ' + (r.executed.trace || p.trace));
                card.remove();
                if (r.executed.summary) addRow('bot', esc(r.executed.summary));
                if (r.executed.link) addLink(r.executed.link);
            }).catch(function () {
                setBusy(false);
                trace.fail('Could not reach the server.');
            });
        };
        card.querySelector('[data-ai-run]').addEventListener('click', run);
        card.querySelector('[data-ai-phrase]').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); run(); }
        });

        return Promise.resolve();
    }

    /* ---------- proposal cards (mutating only) ---------- */

    function renderProposal(p) {
        if (!p) return Promise.resolve();

        // Plan #40 executing action: diff + typed confirmation.
        if (p.kind === 'action') {
            return renderActionCard(p);
        }

        if (!p.mutating) {
            // Read tool: show the action, then run it immediately. The trace row
            // is already on screen by the time this fires, so the user watches
            // the lookup happen instead of seeing an unexplained answer.
            var readTrace = addTrace(p.trace || p.label || 'Looking something up');
            return post({ op: 'confirm', confirm_token: p.confirm_token })
                .then(function (r) {
                    if (!r.ok) {
                        readTrace.fail(r.error || 'That lookup was not permitted.');
                        return;
                    }
                    readTrace.finish(r.executed.trace || p.trace);
                    if (r.executed.summary) addRow('bot', esc(r.executed.summary));
                    if (r.executed.link) addLink(r.executed.link);
                })
                .catch(function () {
                    readTrace.fail('Could not reach the server.');
                });
        }

        var card = document.createElement('div');
        card.className = 'card border-warning mb-2';
        card.innerHTML =
            '<div class="card-body p-2">' +
            '<div class="small fw-semibold mb-1"><i class="bi bi-shield-exclamation me-1"></i>Confirm this action</div>' +
            '<div class="small mb-1">' + esc(p.trace || p.label || '') + '</div>' +
            '<div class="small text-muted mb-2">' + esc(p.description || '') + '</div>' +
            '<pre class="small bg-light border rounded p-2 mb-2" style="max-height:9rem;overflow:auto;white-space:pre-wrap;margin:0;">'
                + esc(JSON.stringify(p.args, null, 2)) + '</pre>' +
            '<div class="d-flex gap-2">' +
            '<button type="button" class="btn btn-sm btn-success" data-ai-confirm="' + esc(p.confirm_token) + '">Run it</button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" data-ai-cancel>Cancel</button>' +
            '</div></div>';
        log.appendChild(card);
        log.scrollTop = log.scrollHeight;

        card.querySelector('[data-ai-cancel]').addEventListener('click', function () {
            card.remove();
            addRow('bot', 'Cancelled — nothing was changed.');
        });
        card.querySelector('[data-ai-confirm]').addEventListener('click', function (ev) {
            var btn = ev.currentTarget;
            var token = btn.getAttribute('data-ai-confirm');
            var trace = addTrace('Running: ' + (p.trace || p.label || ''));
            btn.disabled = true;
            setBusy(true);
            post({ op: 'confirm', confirm_token: token }).then(function (r) {
                setBusy(false);
                btn.disabled = false;
                if (!r.ok) {
                    trace.fail(r.error || 'The action was refused.');
                    return;
                }
                trace.finish('Done: ' + (r.executed.trace || p.trace));
                if (r.executed.summary) addRow('bot', esc(r.executed.summary));
                if (r.executed.link) addLink(r.executed.link);
            }).catch(function () {
                setBusy(false);
                trace.fail('Could not reach the server.');
            });
        });
        return Promise.resolve();
    }

    function addLink(href) {
        var row = document.createElement('div');
        row.className = 'd-flex mb-2';
        row.innerHTML = '<a class="btn btn-sm btn-outline-primary" href="' + esc(href) + '" target="_blank" rel="noopener">Open in LOKA</a>';
        log.appendChild(row);
        log.scrollTop = log.scrollHeight;
    }

    /* ---------- send ---------- */

    function send() {
        if (busy) return;
        var text = input.value.trim();
        if (!text) return;
        if (text.length > MAX) { addError('That message is longer than the ' + MAX + ' character limit.'); return; }

        addRow('me', esc(text));
        history.push({ role: 'user', content: text });
        input.value = '';
        setBusy(true);

        post({ op: 'ask', prompt: text }).then(function (r) {
            setBusy(false);
            if (!r.ok) { addError(r.error || 'Something went wrong.'); return; }
            if (r.reply) { addRow('bot', esc(r.reply)); history.push({ role: 'assistant', content: r.reply }); }
            if (r.remaining != null) {
                statusEl.textContent = r.remaining + ' prompt(s) left this hour';
            }
            // renderProposal returns a promise for read tools (which now run in a
            // second, separately-audited call so the trace can be seen first).
            return renderProposal(r.proposal);
        }).then(function () {
            setBusy(false);
        }).catch(function () {
            setBusy(false);
            addError('Could not reach the server.');
        });
    }

    function open() {
        panel.classList.remove('d-none');
        bubble.classList.add('d-none');
        input.focus();
    }

    function close() {
        panel.classList.add('d-none');
        bubble.classList.remove('d-none');
    }

    document.addEventListener('DOMContentLoaded', function () {
        panel = el('lokaAiPanel');
        if (!panel) return;
        log = el('lokaAiLog');
        input = el('lokaAiInput');
        sendBtn = el('lokaAiSend');
        statusEl = el('lokaAiStatus');
        bubble = el('lokaAiBubble');

        el('lokaAiBubble').addEventListener('click', open);
        el('lokaAiClose').addEventListener('click', close);
        sendBtn.addEventListener('click', send);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
        });

        if (!log.childElementCount) {
            addRow('bot', esc(window.LOKA_AI_GREETING || 'Ask about your trips, approvals, pass slips, gas vouchers or vehicle care.'));
        }
    });
})();