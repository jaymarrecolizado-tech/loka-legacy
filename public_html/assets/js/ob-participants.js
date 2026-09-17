/**
 * LOKA - OB Pass Slip participant picker (Plan #22)
 * Searchable user list. Filer stays selected. Live "J.Recolizado/D.Abad" preview.
 *
 * ObParticipants.init({
 *   select: '#obParticipants',
 *   line: '#obPrintedLine',
 *   requesterId: '12',
 *   requesterShort: 'J.Recolizado'
 * });
 */
(function () {
    'use strict';

    window.ObParticipants = {
        init: function (opts) {
            var selectEl = document.querySelector(opts.select || '#obParticipants');
            var lineEl = document.querySelector(opts.line || '#obPrintedLine');
            var requesterId = String(opts.requesterId || '');
            var requesterShort = opts.requesterShort || '';
            if (!selectEl) return;

            function selectedValues() {
                if (selectEl.tomselect) {
                    return selectEl.tomselect.items.slice();
                }
                return Array.from(selectEl.selectedOptions).map(function (o) { return o.value; });
            }

            function updateLine() {
                if (!lineEl) return;
                var shorts = requesterShort ? [requesterShort] : [];
                selectedValues().forEach(function (val) {
                    if (String(val) === requesterId) return;
                    var opt = selectEl.querySelector('option[value="' + String(val) + '"]');
                    if (opt && opt.dataset.short) shorts.push(opt.dataset.short);
                });
                lineEl.textContent = shorts.filter(Boolean).join('/') || '—';
            }

            if (window.TomSelect) {
                var ts = new TomSelect(selectEl, {
                    plugins: ['remove_button'],
                    maxItems: 20,
                    placeholder: 'Search employees to add...',
                    closeAfterSelect: false,
                    hideSelected: true,
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
