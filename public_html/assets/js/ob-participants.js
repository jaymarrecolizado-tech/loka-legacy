/**
 * LOKA - OB Pass Slip participant picker (Plans #22 + #26)
 * Searchable user list. Filer stays selected. Live preview shows BOTH lines:
 *   Personnel: System Admin and Jaymar Recolizado   (full, Oxford-and)
 *   Prints as S.Admin / J.Recolizado                (initials, slash)
 * The JS joins match the PHP helpers exactly (obJoinNames / printed line).
 *
 * ObParticipants.init({
 *   select: '#obParticipants',
 *   line: '#obPrintedLine',
 *   fullLine: '#obFullLine',        // optional (Plan #26)
 *   requesterId: '12',
 *   requesterShort: 'J.Recolizado',
 *   requesterFull: 'Jaymar Recolizado'
 * });
 */
(function () {
    'use strict';

    // Oxford-and: 1 -> A; 2 -> A and B; 3+ -> A, B, and C (mirrors obJoinNames)
    function joinOxford(list) {
        var n = list.length;
        if (n === 0) return '';
        if (n === 1) return list[0];
        if (n === 2) return list[0] + ' and ' + list[1];
        return list.slice(0, -1).join(', ') + ', and ' + list[n - 1];
    }

    window.ObParticipants = {
        init: function (opts) {
            var selectEl = document.querySelector(opts.select || '#obParticipants');
            var lineEl = document.querySelector(opts.line || '#obPrintedLine');
            var fullLineEl = opts.fullLine ? document.querySelector(opts.fullLine) : null;
            var requesterId = String(opts.requesterId || '');
            var requesterShort = opts.requesterShort || '';
            var requesterFull = opts.requesterFull || '';
            if (!selectEl) return;

            function selectedValues() {
                if (selectEl.tomselect) {
                    return selectEl.tomselect.items.slice();
                }
                return Array.from(selectEl.selectedOptions).map(function (o) { return o.value; });
            }

            function selectedNames(attr) {
                var names = [];
                if (attr === 'full' && requesterFull) names.push(requesterFull);
                if (attr === 'short' && requesterShort) names.push(requesterShort);
                selectedValues().forEach(function (val) {
                    if (String(val) === requesterId) return;
                    var opt = selectEl.querySelector('option[value="' + String(val) + '"]');
                    if (opt && opt.dataset[attr]) names.push(opt.dataset[attr]);
                });
                return names.filter(Boolean);
            }

            function updateLine() {
                var shorts = selectedNames('short');
                if (lineEl) {
                    lineEl.textContent = shorts.join(' / ') || '—';
                }
                if (fullLineEl) {
                    fullLineEl.textContent = joinOxford(selectedNames('full')) || '—';
                }
            }

            if (window.TomSelect) {
                var ts = new TomSelect(selectEl, {
                    plugins: ['remove_button'],
                    maxItems: 20,
                    placeholder: 'Search employees to add...',
                    closeAfterSelect: false,
                    hideSelected: true,
                    render: {
                        option: function (data, escape) {
                            var dept = (data.$option && data.$option.dataset && data.$option.dataset.dept) || data.dept || '';
                            var html = '<div>' + escape(data.text) + '</div>';
                            if (dept) {
                                html += '<div class="small text-muted">' + escape(dept) + '</div>';
                            }
                            return '<div class="py-1">' + html + '</div>';
                        },
                        item: function (data, escape) {
                            return '<div>' + escape(data.text) + '</div>';
                        }
                    },
                    onChange: updateLine,
                    onItemRemove: function (value) {
                        if (String(value) === requesterId) {
                            var self = this;
                            setTimeout(function () { self.addItem(requesterId, true); updateLine(); }, 0);
                        }
                    }
                });
                if (requesterId && !ts.items.includes(requesterId)) {
                    ts.addItem(requesterId, true);
                }
                var locked = ts.control.querySelector('[data-value="' + requesterId + '"]');
                if (locked) {
                    var rm = locked.querySelector('.remove');
                    if (rm) rm.remove();
                    locked.classList.add('disabled');
                }
            }

            updateLine();
        }
    };
})();
