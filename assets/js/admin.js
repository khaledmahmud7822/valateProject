/* Metro Valet Parking — admin UI helpers (progressive enhancement only;
   every action works without JS because the server does the real work). */
(function () {
    'use strict';

    // Desktop: collapse sidebar to give content more room.
    var shell = document.getElementById('adminShell');
    var collapseBtn = document.getElementById('collapseBtn');
    if (shell && collapseBtn) {
        try {
            if (localStorage.getItem('mv_admin_collapsed') === '1') {
                shell.classList.add('collapsed');
            }
        } catch (e) { /* storage unavailable */ }
        collapseBtn.addEventListener('click', function () {
            shell.classList.toggle('collapsed');
            try {
                localStorage.setItem('mv_admin_collapsed', shell.classList.contains('collapsed') ? '1' : '0');
            } catch (e) { /* ignore */ }
        });
    }

    // Mobile: drawer navigation.
    var drawerBtn = document.getElementById('drawerBtn');
    var scrim = document.getElementById('sidebarScrim');
    function closeDrawer() {
        document.body.classList.remove('nav-open');
        if (scrim) scrim.hidden = true;
    }
    if (drawerBtn) {
        drawerBtn.addEventListener('click', function () {
            document.body.classList.add('nav-open');
            if (scrim) scrim.hidden = false;
        });
    }
    if (scrim) scrim.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') closeDrawer();
    });

    // Confirm dialogs for destructive actions: any element with data-confirm.
    document.addEventListener('click', function (ev) {
        var el = ev.target.closest('[data-confirm]');
        if (!el) return;
        var msg = el.getAttribute('data-confirm') || 'Are you sure?';
        if (!window.confirm(msg)) {
            ev.preventDefault();
            ev.stopPropagation();
        }
    }, true);
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form || !form.hasAttribute('data-confirm')) return;
        var msg = form.getAttribute('data-confirm') || 'Are you sure?';
        if (!window.confirm(msg)) ev.preventDefault();
    }, true);

    // Client-side table search nicety: <input data-table-search data-table-target="tableId">
    // filters visible rows only; the server still performs the authoritative filtering.
    document.querySelectorAll('input[data-table-search]').forEach(function (input) {
        var targetId = input.getAttribute('data-table-target');
        var table = targetId ? document.getElementById(targetId) : null;
        if (!table) return;
        var tbody = table.querySelector('tbody');
        if (!tbody) return;
        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            tbody.querySelectorAll('tr').forEach(function (row) {
                row.style.display = (!q || row.textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
            });
        });
    });

    // "Copy pay link" buttons.
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-copy') || '';
            function done() {
                var orig = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(function () { btn.textContent = orig; }, 1500);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () {
                    window.prompt('Copy this link:', text);
                });
            } else {
                window.prompt('Copy this link:', text);
            }
        });
    });

})();
