        </main>
    </div>
    
    <!-- jQuery (required for DataTables) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    
    <!-- Bootstrap 5.3 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

    <!-- Moment + DataTables datetime sorting plugin -->
    <script src="https://cdn.jsdelivr.net/npm/moment@2.29.4/min/moment.min.js"></script>
    <script src="https://cdn.datatables.net/plug-ins/1.13.7/sorting/datetime-moment.js"></script>
    
    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <!-- Tom Select JS -->
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    
    <!-- Palette backdrop + modal -->
    <div id="paletteBackdrop" class="d-none"></div>
    <div id="navSearchPalette" class="card shadow d-none">
        <div class="p-2 border-bottom">
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" id="paletteInput" class="form-control" placeholder="Search features, pages, actions…" autocomplete="off">
                <span class="input-group-text bg-white small text-muted">Esc</span>
            </div>
            <small class="text-muted px-1">Tip: Press Ctrl+K to open anywhere • Arrows + Enter to navigate</small>
        </div>
        <div id="paletteFrequent" class="py-1"></div>
        <div id="paletteRecent" class="py-1 border-top"></div>
        <div id="paletteList" class="py-1 border-top"></div>
        <div class="p-2 text-center"><button class="btn btn-sm btn-link text-muted" onclick="localStorage.removeItem('loka_nav_recent_'+window.LOKA_USER_ID); localStorage.removeItem('loka_nav_frequent_'+window.LOKA_USER_ID); location.reload();">Clear history</button></div>
    </div>

    <!-- Custom JS -->
    <script src="<?= ASSETS_PATH ?>/js/app.js?v=<?= e(APP_VERSION) ?>&t=<?= time() ?>"></script>
    <script src="<?= ASSETS_PATH ?>/js/nav-search.js?v=<?= e(APP_VERSION) ?>&t=<?= time() ?>"></script>
    <script>try{ // one-time cleanup of old relative hrefs that caused XAMPP /dashboard/ redirect
        const uid=window.LOKA_USER_ID||'0';
        ['loka_nav_recent_','loka_nav_frequent_','loka_nav_pinned_'].forEach(p=>{
            const k=p+uid; const raw=localStorage.getItem(k); if(!raw) return;
            // Remove any entry that has a bare relative href (/?page= or /dashboard/ style) without a host
            if(raw.includes('"href":"/?page=') || raw.includes('"href":"/dashboard') || (raw.includes('"href":"/')&&!raw.includes('"href":"http'))){
                localStorage.removeItem(k);
            }
        });
        const qk='loka_nav_queries_'+uid;
        const qraw=localStorage.getItem(qk);
        if(qraw?.includes('/dashboard/') || qraw?.includes('/?page=')) localStorage.removeItem(qk);
    }catch(e){}</script>
    
    <?php if (isset($pageScripts)): ?>
    <?= $pageScripts ?>
    <?php endif; ?>
</body>
</html>
