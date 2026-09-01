/**
 * LOKA - Navigation Search (top bar + Ctrl+K command palette)
 * - Fuzzy search over permission-aware nav index (window.LOKA_NAV_ITEMS)
 * - Recent searches (up to 5) + Frequent (top 5 by visit count) via localStorage per user
 * - Keyboard: Ctrl+K / Cmd+K opens palette, Arrows, Enter, Escape
 * - Tracks sidebar/top nav clicks to update frequent/recent
 */
(function() {
    const userId = window.LOKA_USER_ID || '0';
    const LS_RECENT = 'loka_nav_recent_' + userId;
    const LS_FREQUENT = 'loka_nav_frequent_' + userId;
    const MAX_RECENT = 5;
    const MAX_FREQUENT = 5;

    function loadRecent() { try { return JSON.parse(localStorage.getItem(LS_RECENT) || '[]'); } catch(e){ return []; } }
    function saveRecent(list) { localStorage.setItem(LS_RECENT, JSON.stringify(list.slice(0, MAX_RECENT))); }
    function loadFrequent() { try { return JSON.parse(localStorage.getItem(LS_FREQUENT) || '{}'); } catch(e){ return {}; } }
    function saveFrequent(map) { localStorage.setItem(LS_FREQUENT, JSON.stringify(map)); }

    function pushRecent(item) {
        const recent = loadRecent().filter(r => r.href !== item.href);
        recent.unshift({ label: item.label, href: item.href, icon: item.icon, section: item.section });
        saveRecent(recent);
    }
    function bumpFrequent(item) {
        const map = loadFrequent();
        const key = item.href;
        map[key] = (map[key] || 0) + 1;
        saveFrequent(map);
    }
    function getFrequentItems(all) {
        const map = loadFrequent();
        const scored = Object.entries(map).map(([href, cnt]) => {
            const found = all.find(a => a.href === href);
            return found ? { ...found, count: cnt } : null;
        }).filter(Boolean).sort((a,b) => b.count - a.count).slice(0, MAX_FREQUENT);
        return scored;
    }

    // Simple fuzzy: token match + substring boost
    function scoreItem(item, query) {
        if (!query) return 0;
        const q = query.toLowerCase().trim();
        const tokens = q.split(/\s+/);
        const label = item.label.toLowerCase();
        const kw = item.keywords.toLowerCase();
        const section = item.section.toLowerCase();
        let score = 0;
        tokens.forEach(tok => {
            if (label.includes(tok)) score += 10;
            if (kw.includes(tok)) score += 6;
            if (section.toLowerCase().includes(tok)) score += 2;
            if (label.toLowerCase().startsWith(tok)) score += 5;
        });
        // exact prefix bonus
        if (label.startsWith(q)) score += 8;
        return score;
    }

    function renderList(container, items, query, emptyText) {
        container.innerHTML = '';
        if (!items.length) {
            const div = document.createElement('div');
            div.className = 'nav-search-empty text-muted small px-3 py-2';
            div.textContent = emptyText || 'No matches.';
            container.appendChild(div);
            return;
        }
        items.forEach((item, idx) => {
            const a = document.createElement('a');
            a.href = item.href;
            a.className = 'nav-search-item d-flex align-items-center gap-2 px-3 py-2 text-decoration-none';
            a.dataset.index = idx;
            a.innerHTML = `<i class="bi ${item.icon} text-primary"></i>
                <div class="flex-grow-1"><div class="fw-medium small">${escapeHtml(item.label)}</div><small class="text-muted">${escapeHtml(item.section)}</small></div>
                ${item.count ? `<span class="badge bg-light text-dark border">${item.count}×</span>` : ''}`;
            a.addEventListener('click', () => {
                pushRecent(item);
                bumpFrequent(item);
            });
            container.appendChild(a);
        });
    }
    function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function init() {
        const items = window.LOKA_NAV_ITEMS || [];
        const topInput = document.getElementById('navSearchInput');
        const topDropdown = document.getElementById('navSearchDropdown');
        const topList = document.getElementById('navSearchList');
        const palette = document.getElementById('navSearchPalette');
        const paletteInput = document.getElementById('paletteInput');
        const paletteList = document.getElementById('paletteList');
        const paletteRecent = document.getElementById('paletteRecent');
        const paletteFrequent = document.getElementById('paletteFrequent');
        const backdrop = document.getElementById('paletteBackdrop');

        if (!topInput || !topDropdown || !palette) return;

        let activeIdx = -1;
        let currentResults = [];

        function filter(query) {
            if (!query) return [];
            const scored = items.map(it => ({ ...it, _score: scoreItem(it, query) }))
                                .filter(it => it._score > 0)
                                .sort((a,b) => b._score - a._score)
                                .slice(0, 8);
            return scored;
        }

        function showTopDropdown(query) {
            if (!query) {
                // show recent + frequent when empty/focused
                const recent = loadRecent();
                const frequent = getFrequentItems(items);
                if (!recent.length && !frequent.length) { topDropdown.classList.add('d-none'); return; }
                let html = '';
                topList.innerHTML = '';
                if (frequent.length) {
                    const h = document.createElement('div'); h.className = 'px-3 py-1 text-uppercase small fw-bold text-muted'; h.textContent = 'Frequently visited';
                    topList.appendChild(h);
                    frequent.forEach(it => {
                        const a = document.createElement('a');
                        a.href = it.href; a.className = 'nav-search-item d-flex align-items-center gap-2 px-3 py-2 text-decoration-none';
                        a.innerHTML = `<i class="bi ${it.icon} text-warning"></i><div class="flex-grow-1 small fw-medium">${escapeHtml(it.label)}</div><span class="badge bg-light text-dark border">${it.count}×</span>`;
                        a.addEventListener('click', () => { pushRecent(it); bumpFrequent(it); });
                        topList.appendChild(a);
                    });
                }
                if (recent.length) {
                    const h = document.createElement('div'); h.className = 'px-3 py-1 text-uppercase small fw-bold text-muted mt-2'; h.textContent = 'Recent';
                    topList.appendChild(h);
                    recent.forEach(it => {
                        const a = document.createElement('a');
                        a.href = it.href; a.className = 'nav-search-item d-flex align-items-center gap-2 px-3 py-2 text-decoration-none';
                        a.innerHTML = `<i class="bi ${it.icon} text-primary"></i><div class="flex-grow-1 small">${escapeHtml(it.label)}</div><small class="text-muted">${escapeHtml(it.section)}</small>`;
                        a.addEventListener('click', () => bumpFrequent(it));
                        topList.appendChild(a);
                    });
                }
                // clear button
                const clear = document.createElement('button');
                clear.className = 'btn btn-link btn-sm w-100 text-muted mt-2'; clear.textContent = 'Clear history';
                clear.addEventListener('click', () => { localStorage.removeItem(LS_RECENT); localStorage.removeItem(LS_FREQUENT); topDropdown.classList.add('d-none'); });
                topList.appendChild(clear);
                topDropdown.classList.remove('d-none');
                return;
            }
            currentResults = filter(query);
            renderList(topList, currentResults, query, 'No features found.');
            topDropdown.classList.remove('d-none');
            activeIdx = -1;
            updateActive(topList);
        }

        function updateActive(listEl) {
            const els = listEl.querySelectorAll('.nav-search-item');
            els.forEach((el,i) => el.classList.toggle('active', i===activeIdx));
        }

        function navigateActive(listEl) {
            const els = listEl.querySelectorAll('.nav-search-item');
            if (activeIdx >=0 && els[activeIdx]) els[activeIdx].click();
        }

        topInput.addEventListener('input', e => showTopDropdown(e.target.value));
        topInput.addEventListener('focus', () => showTopDropdown(topInput.value));
        topInput.addEventListener('keydown', e => {
            const els = topList.querySelectorAll('.nav-search-item');
            if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx+1, els.length-1); updateActive(topList); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = Math.max(activeIdx-1, 0); updateActive(topList); }
            else if (e.key === 'Enter') { if (activeIdx>=0) { e.preventDefault(); navigateActive(topList); } }
            else if (e.key === 'Escape') { topDropdown.classList.add('d-none'); topInput.blur(); }
        });
        document.addEventListener('click', e => {
            if (!topInput.contains(e.target) && !topDropdown.contains(e.target)) topDropdown.classList.add('d-none');
        });

        // Palette
        function openPalette() {
            palette.classList.remove('d-none');
            backdrop.classList.remove('d-none');
            paletteInput.value = '';
            paletteInput.focus();
            refreshPalette('');
        }
        function closePalette() { palette.classList.add('d-none'); backdrop.classList.add('d-none'); }
        function refreshPalette(q) {
            const recent = loadRecent();
            const frequent = getFrequentItems(items);
            // recent/frequent sections
            paletteRecent.innerHTML = ''; paletteFrequent.innerHTML = '';
            if (!q) {
                if (frequent.length) {
                    const h = document.createElement('div'); h.className = 'small fw-bold text-muted text-uppercase px-3 py-1'; h.textContent = 'Frequently visited';
                    paletteFrequent.appendChild(h);
                    frequent.forEach(it => {
                        const a = document.createElement('a'); a.href = it.href; a.className = 'nav-search-item d-flex align-items-center gap-2 px-3 py-2 text-decoration-none';
                        a.innerHTML = `<i class="bi ${it.icon} text-warning"></i><div class="flex-grow-1"><div class="small fw-medium">${escapeHtml(it.label)}</div><small class="text-muted">${escapeHtml(it.section)}</small></div><span class="badge bg-light text-dark border">${it.count}×</span>`;
                        a.addEventListener('click', () => { pushRecent(it); bumpFrequent(it); });
                        paletteFrequent.appendChild(a);
                    });
                }
                if (recent.length) {
                    const h = document.createElement('div'); h.className = 'small fw-bold text-muted text-uppercase px-3 py-1 mt-2'; h.textContent = 'Recent searches';
                    paletteRecent.appendChild(h);
                    recent.forEach(it => {
                        const a = document.createElement('a'); a.href = it.href; a.className = 'nav-search-item d-flex align-items-center gap-2 px-3 py-2 text-decoration-none';
                        a.innerHTML = `<i class="bi ${it.icon} text-primary"></i><div class="flex-grow-1 small">${escapeHtml(it.label)}</div><small class="text-muted">${escapeHtml(it.section)}</small>`;
                        a.addEventListener('click', () => bumpFrequent(it));
                        paletteRecent.appendChild(a);
                    });
                }
                paletteList.innerHTML = '<div class="text-muted small px-3 py-2">Type to search features…</div>';
                return;
            }
            const res = filter(q);
            paletteRecent.classList.add('d-none'); paletteFrequent.classList.add('d-none');
            if (!res.length) paletteList.innerHTML = '<div class="text-muted small px-3 py-3 text-center">No matches.</div>';
            else {
                paletteList.innerHTML = '';
                res.forEach(it => {
                    const a = document.createElement('a'); a.href = it.href; a.className = 'nav-search-item d-flex align-items-center gap-2 px-3 py-2 text-decoration-none';
                    a.innerHTML = `<i class="bi ${it.icon} text-primary"></i><div class="flex-grow-1"><div class="small fw-medium">${escapeHtml(it.label)}</div><small class="text-muted">${escapeHtml(it.section)} • ${escapeHtml(it.keywords)}</small></div>`;
                    a.addEventListener('click', () => { pushRecent(it); bumpFrequent(it); });
                    paletteList.appendChild(a);
                });
            }
        }

        document.addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openPalette(); }
            if (e.key === 'Escape' && !palette.classList.contains('d-none')) closePalette();
        });
        backdrop.addEventListener('click', closePalette);
        paletteInput.addEventListener('input', e => refreshPalette(e.target.value));

        // Track sidebar clicks for frequent
        document.querySelectorAll('.sidebar .nav-link').forEach(a => {
            a.addEventListener('click', () => {
                const href = a.getAttribute('href');
                const label = a.querySelector('span')?.textContent?.trim() || href;
                const icon = a.querySelector('i')?.className?.split(' ').find(c=>c.startsWith('bi-')) || 'bi-link';
                const item = { label, href, icon, section: a.closest('.nav-header')?.textContent?.trim() || 'Navigation' };
                pushRecent(item); bumpFrequent(item);
            });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
