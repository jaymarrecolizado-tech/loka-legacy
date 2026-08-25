<?php
/**
 * LOKA - Shared Vehicle Trip Ticket template helpers (Plan #5)
 *
 * Provides the auto-field engine (no clipping, ever) and localStorage autosave
 * for the trip ticket print templates. Include INSIDE <body> (or before the
 * page's own <script>) after the form markup has rendered.
 *
 * Expects (optional): $ticketAutosaveKey — stable string identifying this ticket.
 *
 * Public API (global TicketKit):
 *   TicketKit.autosize(root?)          Grow textareas / size inputs to content
 *   TicketKit.initAutosave(key)        Snapshot defaults, restore saved, autosave on input
 *   TicketKit.reset()                  Restore PHP defaults, clear storage
 *   TicketKit.addRow(tbodyId, templateId)  Clone a <template> row into a tbody
 *   TicketKit.removeRow(tbodyId, min)  Remove last row (min default 1)
 */
if (!isset($ticketAutosaveKey)) {
    $ticketAutosaveKey = 'ticket-' . md5($_SERVER['REQUEST_URI'] ?? '');
}
$ticketAutosaveKeyJs = json_encode($ticketAutosaveKey);
?>
<style>
    /* Auto-field helpers (shared by both ticket variants) */
    .af-grow {
        width: 100%;
        resize: none;
        overflow: hidden;
        white-space: normal;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    .af-rowbtns {
        display: none; /* screen-only; forced visible below */
        gap: 6px;
        margin: 4px 0;
    }

    .af-rowbtns .af-btn {
        font-family: 'DM Sans', sans-serif;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        padding: 3px 10px;
        border: 1px solid #b6bfc9;
        background: #f4f6f8;
        color: #333;
        cursor: pointer;
        border-radius: 3px;
    }

    .af-rowbtns .af-btn:hover {
        background: #e8ecf0;
    }

    .af-rowbtns .af-btn.danger {
        color: #8a1f1f;
        border-color: #d8b4b4;
    }

    /* Screen-only helper so the buttons actually show outside print */
    @media screen {
        .af-rowbtns {
            display: flex;
        }
    }

    @media print {
        .af-rowbtns,
        .af-no-print {
            display: none !important;
        }

        .af-remarks-empty {
            display: none !important;
        }

        textarea,
        input {
            overflow: hidden !important;
        }
    }
</style>
<script>
    (function () {
        var AUTOSAVE_KEY = <?= $ticketAutosaveKeyJs ?>;
        var STORE_KEY = 'loka-ticket-' + AUTOSAVE_KEY;
        var defaults = {};
        var saveTimer = null;

        function fieldKey(el) {
            if (el.dataset.afKey) return el.dataset.afKey;
            var scope = el.closest('tbody');
            if (scope && scope.id) {
                var row = el.closest('tr');
                var ri = Array.prototype.indexOf.call(scope.rows, row);
                var cells = row ? Array.prototype.indexOf.call(row.cells, el.closest('td')) : -1;
                var fi = el.closest('td') ? Array.prototype.indexOf.call(el.closest('td').querySelectorAll('input,textarea,select'), el) : 0;
                el.dataset.afKey = scope.id + ':' + ri + ':' + cells + ':' + fi;
                return el.dataset.afKey;
            }
            var base = el.id || el.name || '';
            if (!base) {
                var all = document.querySelectorAll('input, textarea, select');
                base = 'el' + Array.prototype.indexOf.call(all, el);
            }
            el.dataset.afKey = base;
            return base;
        }

        function serialize() {
            var data = {};
            document.querySelectorAll('input, textarea, select').forEach(function (el) {
                if (el.type === 'hidden' || el.readOnly) return;
                data[fieldKey(el)] = el.value;
            });
            return data;
        }

        function save() {
            try {
                localStorage.setItem(STORE_KEY, JSON.stringify(serialize()));
            } catch (e) { /* storage unavailable */ }
        }

        function autosizeEl(el) {
            if (el.tagName === 'TEXTAREA') {
                el.style.height = 'auto';
                el.style.height = Math.max(el.scrollHeight, el.clientHeight) + 'px';
            } else if (el.type === 'text' && el.closest('.if')) {
                // Only auto-size info-strip inputs; table-cell inputs keep width:100%
                var len = Math.max(String(el.value || '').length, String(el.placeholder || '').length, 3);
                el.style.width = (len + 2) + 'ch';
            }
        }

        function autosize(root) {
            (root || document).querySelectorAll('textarea').forEach(autosizeEl);
            (root || document).querySelectorAll('.if input[type="text"]').forEach(autosizeEl);
        }

        function restore() {
            var raw = null;
            try { raw = localStorage.getItem(STORE_KEY); } catch (e) { }
            if (!raw) return;
            var data;
            try { data = JSON.parse(raw); } catch (e) { return; }
            document.querySelectorAll('input, textarea, select').forEach(function (el) {
                var k = fieldKey(el);
                if (Object.prototype.hasOwnProperty.call(data, k) && !el.readOnly) {
                    el.value = data[k];
                }
            });
        }

        var TicketKit = {
            autosize: autosize,

            initAutosave: function () {
                // Snapshot PHP-rendered defaults BEFORE restoring anything
                document.querySelectorAll('input, textarea, select').forEach(function (el) {
                    defaults[fieldKey(el)] = el.value;
                });
                restore();
                autosize();

                document.addEventListener('input', function (e) {
                    if (e.target.matches('input, textarea, select')) {
                        autosizeEl(e.target);
                        clearTimeout(saveTimer);
                        saveTimer = setTimeout(save, 300);
                    }
                });

                window.addEventListener('beforeprint', function () {
                    autosize();
                    // hide empty remarks section on print
                    var r = document.getElementById('remarks');
                    var sec = document.getElementById('remarksSection');
                    if (r && sec && !r.value.trim()) sec.classList.add('af-remarks-empty');
                });
                window.addEventListener('afterprint', function () {
                    var sec = document.getElementById('remarksSection');
                    if (sec) sec.classList.remove('af-remarks-empty');
                });
            },

            reset: function () {
                if (!confirm('Clear all entered data and restore the auto-filled values?')) return;
                document.querySelectorAll('input, textarea, select').forEach(function (el) {
                    if (el.readOnly) return;
                    var k = fieldKey(el);
                    if (Object.prototype.hasOwnProperty.call(defaults, k)) el.value = defaults[k];
                });
                try { localStorage.removeItem(STORE_KEY); } catch (e) { }
                autosize();
                if (typeof calcTotals === 'function') calcTotals();
            },

            addRow: function (tbodyId, templateId) {
                var tbody = document.getElementById(tbodyId);
                var tpl = document.getElementById(templateId);
                if (!tbody || !tpl) return null;
                var row = tpl.content.firstElementChild.cloneNode(true);
                tbody.appendChild(row);
                autosize(row);
                save();
                return row;
            },

            removeRow: function (tbodyId, minRows) {
                var tbody = document.getElementById(tbodyId);
                if (!tbody) return;
                minRows = minRows || 1;
                if (tbody.rows.length <= minRows) return;
                tbody.deleteRow(-1);
                save();
                if (typeof calcTotals === 'function') calcTotals();
            }
        };

        window.TicketKit = TicketKit;
        window.addEventListener('load', function () { TicketKit.initAutosave(); });
    })();
</script>
