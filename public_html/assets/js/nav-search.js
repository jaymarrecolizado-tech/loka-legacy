/**
 * LOKA - Navigation Search++ (top bar + Ctrl+K palette)
 * Best-in-class: fuzzy + highlight + pin/favorite + badges + quick-jump + recent queries + LIVE actual items
 * LIVE: fetches /?page=api&action=global_search&q=XXX to point to actual Request/Vehicle/Driver/User view pages, not just nav labels.
 */
(function() {
    const userId = window.LOKA_USER_ID || '0';
    const LS_RECENT = 'loka_nav_recent_' + userId;
    const LS_FREQUENT = 'loka_nav_frequent_' + userId;
    const LS_PINNED = 'loka_nav_pinned_' + userId;
    const LS_QUERIES = 'loka_nav_queries_' + userId;
    const MAX_RECENT = 5, MAX_FREQUENT = 5, MAX_QUERIES = 5, MAX_PINNED = 6;

    function load(k, def) { try { const v = localStorage.getItem(k); return v ? JSON.parse(v) : def; } catch(e){ return def; } }
    function save(k, v) { localStorage.setItem(k, JSON.stringify(v)); }
    function loadRecent(){ return load(LS_RECENT, []); }
    function saveRecent(a){ save(LS_RECENT, a.slice(0, MAX_RECENT)); }
    function loadFrequent(){ return load(LS_FREQUENT, {}); }
    function saveFrequent(m){ save(LS_FREQUENT, m); }
    function loadPinned(){ return load(LS_PINNED, []); }
    function savePinned(a){ save(LS_PINNED, a.slice(0, MAX_PINNED)); }
    function loadQueries(){ return load(LS_QUERIES, []); }
    function saveQueries(a){ save(LS_QUERIES, a.slice(0, MAX_QUERIES)); }

    function pushRecent(item){
        item.href = normalizeHref(item.href);
        const r = loadRecent().filter(x=>normalizeHref(x.href)!==item.href);
        r.unshift({ label:item.label, href:item.href, icon:item.icon, section:item.section });
        saveRecent(r);
    }
    function pushQuery(q){
        q = q.trim(); if(!q || q.length<2) return;
        const qs = loadQueries().filter(x=>x.toLowerCase()!==q.toLowerCase());
        qs.unshift(q); saveQueries(qs);
    }
    function bumpFrequent(item){
        item.href = normalizeHref(item.href);
        const m = loadFrequent(); m[item.href]=(m[item.href]||0)+1; saveFrequent(m);
    }
    const APP_BASE = (window.LOKA_APP_URL || '').replace(/\/$/, '');
    function normalizeHref(href){
        if(!href) return href;
        if(href.startsWith('http')) return href;
        if(href.startsWith('/?page=')) return APP_BASE + href;
        return href;
    }
    function getFrequentItems(all){
        const m = loadFrequent();
        return Object.entries(m).map(([href,cnt])=>{
            const norm = normalizeHref(href);
            const f=all.find(a=>a.href===href || a.href===norm || normalizeHref(a.href)===href);
            return f?{...f,count:cnt, href: f.href}:null;
        }).filter(Boolean).sort((a,b)=>b.count-a.count).slice(0,MAX_FREQUENT);
    }
    function getPinnedItems(all){
        const pins = loadPinned().map(normalizeHref);
        return pins.map(href=> all.find(a=>a.href===href || normalizeHref(a.href)===href)).filter(Boolean).map(it=> ({...it, pinned:true }));
    }
    function isPinned(href){ return loadPinned().map(normalizeHref).includes(normalizeHref(href)); }
    function togglePin(href){
        href = normalizeHref(href);
        let pins = loadPinned().map(normalizeHref);
        if(pins.includes(href)) pins = pins.filter(h=>h!==href);
        else { if(pins.length>=MAX_PINNED) pins.pop(); pins.unshift(href); }
        savePinned(pins);
    }

    function scoreItem(item, query){
        if(!query) return 0;
        const q=query.toLowerCase().trim(), toks=q.split(/\s+/);
        const label=item.label.toLowerCase(), kw=(item.keywords||'').toLowerCase(), sec=item.section.toLowerCase();
        let s=0;
        toks.forEach(tok=>{
            if(label.includes(tok)) s+=10;
            if(kw.includes(tok)) s+=6;
            if(sec.includes(tok)) s+=2;
            if(label.startsWith(tok)) s+=5;
            if(tok.length>=2 && label.split(/\s+/).some(w=>w.startsWith(tok))) s+=3;
        });
        if(label.startsWith(q)) s+=8;
        if(isPinned(item.href)) s+=3;
        return s;
    }
    function filterItems(items, query){
        if(!query) return [];
        return items.map(it=>({...it,_score:scoreItem(it,query)})).filter(it=>it._score>0).sort((a,b)=>b._score-a._score).slice(0,8);
    }
    function quickJumpItems(query){
        const q=(query||'').trim();
        if(!q) return [];
        const out=[];
        const mNum = q.match(/^#?(\d{1,6})$/);
        if(mNum){
            const id=mNum[1];
            out.push({ label:`Go to Request #${id}`, href:APP_BASE + `/?page=requests&action=view&id=${id}`, icon:'bi-hash', section:'Quick Jump', keywords:`request ${id}`, quick:true });
        }
        if(/^[A-Z0-9\- ]{3,10}$/i.test(q) && !/^\d+$/.test(q) && q.length>=3){
            const plate=q.toUpperCase().replace(/\s+/g,' ').trim();
            out.push({ label:`Search Vehicle: ${plate}`, href:APP_BASE + `/?page=vehicles&search=${encodeURIComponent(plate)}`, icon:'bi-car-front', section:'Quick Jump', keywords:`vehicle plate ${plate}`, quick:true });
        }
        return out;
    }
    function highlight(text, query){
        if(!query) return escapeHtml(text);
        const toks=query.trim().split(/\s+/).filter(Boolean).map(t=>t.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'));
        if(!toks.length) return escapeHtml(text);
        const re=new RegExp('('+toks.join('|')+')','ig');
        return escapeHtml(text).replace(re,'<mark class="p-0 bg-warning bg-opacity-25">$1</mark>');
    }
    function escapeHtml(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

    // Live actual items via API (debounced)
    let liveAbort = null, liveSeq = 0;
    async function fetchLive(q){
        if(!q || q.trim().length<2) return [];
        const seq = ++liveSeq;
        if(liveAbort) liveAbort.abort();
        liveAbort = new AbortController();
        try {
            const url = window.location.pathname + '?page=api&action=global_search&q=' + encodeURIComponent(q);
            const res = await fetch(url, { signal: liveAbort.signal, headers: { 'X-Requested-With':'XMLHttpRequest' } });
            const data = await res.json();
            if(seq !== liveSeq) return [];
            if(data && data.success && Array.isArray(data.items)) return data.items.slice(0, 6);
            return [];
        } catch(e){ return []; }
    }

    function renderItem(a, item, query, showPinBtn){
        // Always normalize to absolute to avoid XAMPP root redirect (/dashboard/ bug when subfolder install)
        item.href = normalizeHref(item.href);
        const pinned=isPinned(item.href);
        const hlLabel=highlight(item.label, query);
        const hlSection=highlight(item.section, query);
        const badge=item.badge && item.badge>0 ? `<span class="badge bg-warning text-dark ms-1">${item.badge}</span>` : '';
        const quickBadge=item.quick ? `<span class="badge bg-info ms-1">Jump</span>` : '';
        const liveBadge=item.section && ['Request','Vehicle','Driver','User'].includes(item.section) && !item.quick ? `<span class="badge bg-success bg-opacity-10 text-success border ms-1">Actual</span>` : '';
        const pinBtn=showPinBtn && !item.quick && item.section!=='Request' && item.section!=='Vehicle' && item.section!=='Driver' && item.section!=='User' ? `<button type="button" class="btn btn-sm btn-link p-0 ms-1 pin-btn ${pinned?'text-warning':'text-muted'}" title="${pinned?'Unpin':'Pin to top'}" data-href="${escapeHtml(item.href)}"><i class="bi ${pinned?'bi-star-fill':'bi-star'}"></i></button>` : (showPinBtn && isPinned(item.href) ? `<button type="button" class="btn btn-sm btn-link p-0 ms-1 pin-btn text-warning" title="Unpin" data-href="${escapeHtml(item.href)}"><i class="bi bi-star-fill"></i></button>` : '');
        a.className='nav-search-item d-flex align-items-center gap-2 px-3 py-2 text-decoration-none';
        a.href=item.href;
        a.innerHTML=`<i class="bi ${item.icon} ${item.quick?'text-info':'text-primary'}"></i>
            <div class="flex-grow-1"><div class="fw-medium small">${hlLabel}${badge}${quickBadge}${liveBadge}</div><small class="text-muted">${hlSection}${item.count?` • ${item.count}×`:''}</small></div>
            ${pinBtn}`;
        const btn=a.querySelector('.pin-btn');
        if(btn){ btn.addEventListener('click', e=>{ e.preventDefault(); e.stopPropagation(); togglePin(item.href); refreshAll(); }); }
        a.addEventListener('click', ()=>{ pushRecent(item); bumpFrequent(item); if(query) pushQuery(query); });
    }
    function refreshAll(){
        const topInput=document.getElementById('navSearchInput');
        if(topInput && document.activeElement===topInput) showTopDropdown(topInput.value);
        const pal=document.getElementById('navSearchPalette');
        if(pal && !pal.classList.contains('d-none')){
            const pi=document.getElementById('paletteInput');
            refreshPalette(pi?pi.value:'');
        }
    }

    let activeIdx=-1;
    function updateActive(listEl){
        const els=listEl.querySelectorAll('.nav-search-item');
        els.forEach((el,i)=> el.classList.toggle('active', i===activeIdx));
        if(activeIdx>=0 && els[activeIdx]) els[activeIdx].scrollIntoView({ block:'nearest' });
    }
    function navigateActive(listEl){ const els=listEl.querySelectorAll('.nav-search-item'); if(activeIdx>=0 && els[activeIdx]) els[activeIdx].click(); }

    // Debounce helper
    function debounce(fn, ms){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; }

    async function showTopDropdown(query){
        const topList=document.getElementById('navSearchList');
        const topDropdown=document.getElementById('navSearchDropdown');
        if(!topList||!topDropdown) return;
        const items=window.LOKA_NAV_ITEMS||[];
        if(!query){
            const pinned=getPinnedItems(items);
            const frequent=getFrequentItems(items);
            const recent=loadRecent();
            const queries=loadQueries();
            if(!pinned.length && !frequent.length && !recent.length && !queries.length){ topDropdown.classList.add('d-none'); return; }
            topList.innerHTML='';
            if(pinned.length){
                const h=document.createElement('div'); h.className='px-3 py-1 text-uppercase small fw-bold text-muted'; h.textContent='Pinned ★'; topList.appendChild(h);
                pinned.forEach(it=>{ const a=document.createElement('a'); renderItem(a,it,'',true); topList.appendChild(a); });
            }
            if(frequent.length){
                const h=document.createElement('div'); h.className='px-3 py-1 text-uppercase small fw-bold text-muted mt-2'; h.textContent='Frequently visited'; topList.appendChild(h);
                frequent.forEach(it=>{ const a=document.createElement('a'); renderItem(a,it,'',true); topList.appendChild(a); });
            }
            if(recent.length){
                const h=document.createElement('div'); h.className='px-3 py-1 text-uppercase small fw-bold text-muted mt-2'; h.textContent='Recent'; topList.appendChild(h);
                recent.forEach(it=>{ const a=document.createElement('a'); renderItem(a,it,'',true); topList.appendChild(a); });
            }
            if(queries.length){
                const h=document.createElement('div'); h.className='px-3 py-1 text-uppercase small fw-bold text-muted mt-2'; h.textContent='Recent searches'; topList.appendChild(h);
                queries.forEach(q=>{
                    const a=document.createElement('a'); a.href='#'; a.className='nav-search-item d-flex align-items-center gap-2 px-3 py-2 text-decoration-none';
                    a.innerHTML=`<i class="bi bi-clock-history text-muted"></i><div class="flex-grow-1 small">${escapeHtml(q)}</div><small class="text-muted">search</small>`;
                    a.addEventListener('click', e=>{ e.preventDefault(); document.getElementById('navSearchInput').value=q; showTopDropdown(q); });
                    topList.appendChild(a);
                });
            }
            const clear=document.createElement('button'); clear.className='btn btn-link btn-sm w-100 text-muted mt-2'; clear.textContent='Clear history';
            clear.addEventListener('click', ()=>{ localStorage.removeItem(LS_RECENT); localStorage.removeItem(LS_FREQUENT); localStorage.removeItem(LS_QUERIES); topDropdown.classList.add('d-none'); });
            topList.appendChild(clear);
            topDropdown.classList.remove('d-none'); activeIdx=-1;
            return;
        }
        const quick=quickJumpItems(query);
        const filtered=filterItems(items, query);
        // Render immediately with nav results, then enrich with live actual items
        let combined=[...quick, ...filtered].slice(0,8);
        topList.innerHTML='';
        if(!combined.length){
            topList.innerHTML='<div class="text-muted small px-3 py-2">Searching…</div>';
        } else {
            combined.forEach(it=>{ const a=document.createElement('a'); renderItem(a,it,query,true); topList.appendChild(a); });
        }
        topDropdown.classList.remove('d-none'); activeIdx=-1; updateActive(topList);

        // Fetch live actual items and merge on top
        const live = await fetchLive(query);
        if(live.length){
            // Deduplicate by href
            const seen=new Set(combined.map(c=>c.href));
            const newLive = live.filter(l=> !seen.has(l.href));
            if(newLive.length){
                // Insert live section at top
                topList.innerHTML='';
                if(newLive.length){
                    const h=document.createElement('div'); h.className='px-3 py-1 text-uppercase small fw-bold text-success'; h.textContent='Matching items';
                    topList.appendChild(h);
                    newLive.forEach(it=>{ const a=document.createElement('a'); renderItem(a,it,query,false); topList.appendChild(a); });
                    const sep=document.createElement('hr'); sep.className='my-1'; topList.appendChild(sep);
                }
                combined.forEach(it=>{ const a=document.createElement('a'); renderItem(a,it,query,true); topList.appendChild(a); });
                updateActive(topList);
            }
        } else if(!combined.length){
            topList.innerHTML='<div class="text-muted small px-3 py-3 text-center">No matches. Try #123 for request or a plate like ABC1234.</div>';
        }
    }
    const debouncedTop = debounce(showTopDropdown, 220);

    async function refreshPalette(q){
        const items=window.LOKA_NAV_ITEMS||[];
        const paletteList=document.getElementById('paletteList');
        const paletteRecent=document.getElementById('paletteRecent');
        const paletteFrequent=document.getElementById('paletteFrequent');
        if(!paletteList) return;
        const pinned=getPinnedItems(items);
        const frequent=getFrequentItems(items);
        const recent=loadRecent();
        const queries=loadQueries();
        paletteRecent.innerHTML=''; paletteFrequent.innerHTML='';
        paletteRecent.classList.remove('d-none'); paletteFrequent.classList.remove('d-none');
        if(!q){
            if(pinned.length){
                const h=document.createElement('div'); h.className='small fw-bold text-muted text-uppercase px-3 py-1'; h.textContent='Pinned ★'; paletteFrequent.appendChild(h);
                pinned.forEach(it=>{ const a=document.createElement('a'); renderItem(a,it,'',true); paletteFrequent.appendChild(a); });
            }
            if(frequent.length){
                const h=document.createElement('div'); h.className='small fw-bold text-muted text-uppercase px-3 py-1'; h.textContent='Frequently visited'; paletteFrequent.appendChild(h);
                frequent.forEach(it=>{ const a=document.createElement('a'); renderItem(a,it,'',true); paletteFrequent.appendChild(a); });
            }
            if(recent.length){
                const h=document.createElement('div'); h.className='small fw-bold text-muted text-uppercase px-3 py-1 mt-2'; h.textContent='Recent'; paletteRecent.appendChild(h);
                recent.forEach(it=>{ const a=document.createElement('a'); renderItem(a,it,'',true); paletteRecent.appendChild(a); });
            }
            if(queries.length){
                const h=document.createElement('div'); h.className='small fw-bold text-muted text-uppercase px-3 py-1 mt-2'; h.textContent='Recent searches'; paletteRecent.appendChild(h);
                queries.forEach(qstr=>{
                    const a=document.createElement('a'); a.href='#'; a.className='nav-search-item d-flex align-items-center gap-2 px-3 py-2 text-decoration-none';
                    a.innerHTML=`<i class="bi bi-clock-history text-muted"></i><div class="flex-grow-1 small">${escapeHtml(qstr)}</div>`;
                    a.addEventListener('click', e=>{ e.preventDefault(); document.getElementById('paletteInput').value=qstr; refreshPalette(qstr); });
                    paletteRecent.appendChild(a);
                });
            }
            paletteList.innerHTML='<div class="text-muted small px-3 py-2">Type to search features… Try <code>#123</code> for a request or a plate like <code>ABC1234</code> — actual items appear first.</div>';
            return;
        }
        paletteRecent.classList.add('d-none'); paletteFrequent.classList.add('d-none');
        const quick=quickJumpItems(q);
        const res=filterItems(items, q);
        let combined=[...quick, ...res].slice(0,6);
        paletteList.innerHTML = combined.length ? '' : '<div class="text-muted small px-3 py-2">Searching actual items…</div>';
        combined.forEach(it=>{ const a=document.createElement('a'); renderItem(a,it,q,true); paletteList.appendChild(a); });

        const live = await fetchLive(q);
        if(live.length){
            const seen=new Set(combined.map(c=>c.href));
            const newLive=live.filter(l=>!seen.has(l.href));
            if(newLive.length){
                paletteList.innerHTML='';
                const h=document.createElement('div'); h.className='small fw-bold text-success text-uppercase px-3 py-1'; h.textContent='Matching items';
                paletteList.appendChild(h);
                newLive.forEach(it=>{ const a=document.createElement('a'); renderItem(a,it,q,false); paletteList.appendChild(a); });
                if(combined.length){
                    const sep=document.createElement('hr'); sep.className='my-1'; paletteList.appendChild(sep);
                    const h2=document.createElement('div'); h2.className='small fw-bold text-muted text-uppercase px-3 py-1'; h2.textContent='Features';
                    paletteList.appendChild(h2);
                    combined.forEach(it=>{ const a=document.createElement('a'); renderItem(a,it,q,true); paletteList.appendChild(a); });
                }
            }
        } else if(!combined.length){
            paletteList.innerHTML='<div class="text-muted small px-3 py-3 text-center">No matches.</div>';
        }
    }
    const debouncedPalette = debounce(refreshPalette, 220);

    function migrateLegacy(){
        [LS_RECENT, LS_PINNED].forEach(k=>{
            try {
                const raw=localStorage.getItem(k); if(!raw) return;
                const arr=JSON.parse(raw);
                if(!Array.isArray(arr)) return;
                const migrated=arr.map(it=>{
                    if(typeof it==='string') return normalizeHref(it);
                    if(it && it.href) it.href=normalizeHref(it.href);
                    return it;
                });
                localStorage.setItem(k, JSON.stringify(migrated));
            } catch(e){}
        });
        [LS_FREQUENT].forEach(k=>{
            try {
                const raw=localStorage.getItem(k); if(!raw) return;
                const obj=JSON.parse(raw); if(typeof obj!=='object' || Array.isArray(obj)) return;
                const out={}; Object.entries(obj).forEach(([hk,v])=>{ out[normalizeHref(hk)]=v; });
                localStorage.setItem(k, JSON.stringify(out));
            } catch(e){}
        });
    }
    function init(){
        migrateLegacy();
        const topInput=document.getElementById('navSearchInput');
        const topDropdown=document.getElementById('navSearchDropdown');
        const topList=document.getElementById('navSearchList');
        const palette=document.getElementById('navSearchPalette');
        const paletteInput=document.getElementById('paletteInput');
        const paletteList=document.getElementById('paletteList');
        const backdrop=document.getElementById('paletteBackdrop');
        if(!topInput||!topDropdown||!palette) return;

        topInput.addEventListener('input', e=> debouncedTop(e.target.value));
        topInput.addEventListener('focus', ()=> showTopDropdown(topInput.value));
        topInput.addEventListener('keydown', e=>{
            const els=topList.querySelectorAll('.nav-search-item');
            if(e.key==='ArrowDown'){ e.preventDefault(); activeIdx=Math.min(activeIdx+1, els.length-1); updateActive(topList); }
            else if(e.key==='ArrowUp'){ e.preventDefault(); activeIdx=Math.max(activeIdx-1,0); updateActive(topList); }
            else if(e.key==='Enter'){ if(activeIdx>=0){ e.preventDefault(); navigateActive(topList); } else if(topInput.value.trim()){ pushQuery(topInput.value); } }
            else if(e.key==='Escape'){ topDropdown.classList.add('d-none'); topInput.blur(); }
        });
        document.addEventListener('click', e=>{ if(!topInput.contains(e.target) && !topDropdown.contains(e.target)) topDropdown.classList.add('d-none'); });

        function openPalette(){ palette.classList.remove('d-none'); backdrop.classList.remove('d-none'); const pi=document.getElementById('paletteInput'); pi.value=''; pi.focus(); refreshPalette(''); activeIdx=-1; }
        function closePalette(){ palette.classList.add('d-none'); backdrop.classList.add('d-none'); }
        document.addEventListener('keydown', e=>{
            if((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='k'){ e.preventDefault(); openPalette(); }
            if(e.key==='Escape' && !palette.classList.contains('d-none')) closePalette();
            if(!palette.classList.contains('d-none') && paletteList){
                const els=paletteList.querySelectorAll('.nav-search-item');
                if(e.key==='ArrowDown'){ e.preventDefault(); activeIdx=Math.min(activeIdx+1, els.length-1); updateActive(paletteList); }
                else if(e.key==='ArrowUp'){ e.preventDefault(); activeIdx=Math.max(activeIdx-1,0); updateActive(paletteList); }
                else if(e.key==='Enter' && activeIdx>=0){ e.preventDefault(); navigateActive(paletteList); }
            }
        });
        backdrop.addEventListener('click', closePalette);
        if(paletteInput) paletteInput.addEventListener('input', e=> { activeIdx=-1; debouncedPalette(e.target.value); });

        document.querySelectorAll('.sidebar .nav-link').forEach(a=>{
            a.addEventListener('click', ()=>{
                const href=a.getAttribute('href'); const label=a.querySelector('span')?.textContent?.trim()||href;
                const icon=a.querySelector('i')?.className?.split(' ').find(c=>c.startsWith('bi-'))||'bi-link';
                const item={ label, href, icon, section:'Navigation' }; pushRecent(item); bumpFrequent(item);
            });
        });
    }
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
