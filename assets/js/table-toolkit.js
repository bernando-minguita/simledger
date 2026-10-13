/**
 * Table toolkit - vanilla JS, no dependencies.
 *
 * Every <table class="table"> gets:
 *   - a "#" row-number column (respects data-offset for paginated tables)
 *   - a "Columns" show/hide menu, persisted per page in localStorage
 *   - click-to-sort headers: server-side on data-tt-sort="server" tables
 *     (?sort=<key>&dir= in the URL - shareable/bookmarkable, restored from
 *     localStorage when the URL has no sort), client-side DOM sorting on all
 *     other tables; the preference persists across sessions
 *   - rows marked data-tt-total are TOTALS rows: never sorted, never row
 *     numbered, and always pinned to the bottom of the body
 *   - filter submits and pagination links refresh only their table REGION
 *     (fetch + history API): any container marked data-tt-partial, plus the
 *     legacy #table-partial id; forms declare data-tt-target / data-tt-page
 */
(function () {
    'use strict';

    var COLS_KEY = 'simledger:tt:cols:';
    var SORT_KEY = 'simledger:tt:sort:';

    function slugify(text) {
        return String(text || '').toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    function storeSet(key, val) {
        try { localStorage.setItem(key, val); } catch (e) { /* private mode */ }
    }

    function storeGet(key) {
        try { return localStorage.getItem(key); } catch (e) { return null; }
    }

    function isEmptyRow(tr) {
        return tr.cells.length === 1 && tr.cells[0].hasAttribute('colspan');
    }

    // A totals row ("All terminals", grand totals, ...) is a summary of the
    // rows above it, not data: it must never be sorted into them, and it does
    // not take a row number.
    function isTotalRow(tr) {
        return tr.hasAttribute('data-tt-total');
    }

    // ------------------------------------------------------------- row numbers
    function renumber(table) {
        var body = table.tBodies[0];
        if (!body) { return; }
        var offset = parseInt(table.getAttribute('data-offset') || '0', 10) || 0;
        var n = offset;
        Array.prototype.forEach.call(body.rows, function (tr) {
            if (isEmptyRow(tr) || isTotalRow(tr)) { return; }
            var first = tr.cells[0];
            if (first && first.classList.contains('tt-num')) {
                first.textContent = String(++n);
            }
        });
    }

    function addNumberColumn(table) {
        var headRow = table.tHead && table.tHead.rows[0];
        if (!headRow || (headRow.cells[0] && headRow.cells[0].classList.contains('tt-num'))) {
            return;
        }
        var th = document.createElement('th');
        th.className = 'tt-num';
        th.setAttribute('data-no-sort', '1');
        th.textContent = '#';
        headRow.insertBefore(th, headRow.firstChild);

        var body = table.tBodies[0];
        if (!body) { return; }
        var offset = parseInt(table.getAttribute('data-offset') || '0', 10) || 0;
        var n = offset;
        Array.prototype.forEach.call(body.rows, function (tr) {
            if (isEmptyRow(tr)) {
                tr.cells[0].colSpan = tr.cells[0].colSpan + 1;
                return;
            }
            var td = document.createElement('td');
            td.className = 'tt-num';
            // The cell is still added to a totals row so its columns stay
            // aligned with the data rows - it is just left unnumbered.
            td.textContent = isTotalRow(tr) ? '' : String(++n);
            tr.insertBefore(td, tr.firstChild);
        });
    }

    // -------------------------------------------------------- column visibility
    function applyHidden(table, hidden) {
        var headRow = table.tHead && table.tHead.rows[0];
        if (headRow) {
            Array.prototype.forEach.call(headRow.cells, function (th, i) {
                th.classList.toggle('tt-hidden-c', hidden.indexOf(i) !== -1);
            });
        }
        var body = table.tBodies[0];
        if (!body) { return; }
        Array.prototype.forEach.call(body.rows, function (tr) {
            if (isEmptyRow(tr)) { return; }
            Array.prototype.forEach.call(tr.cells, function (td, i) {
                td.classList.toggle('tt-hidden-c', hidden.indexOf(i) !== -1);
            });
        });
    }

    function buildColumnMenu(table, hidden, onChange) {
        var headRow = table.tHead && table.tHead.rows[0];
        if (!headRow) { return; }
        var host = document.createElement('div');
        host.className = 'dropdown tt-menu';
        host.innerHTML = '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" ' +
            'type="button" data-bs-toggle="dropdown">Columns</button>' +
            '<ul class="dropdown-menu dropdown-menu-end"></ul>';
        var ul = host.querySelector('ul');

        Array.prototype.forEach.call(headRow.cells, function (th, i) {
            var label = (th.textContent || '').replace(/[\s▲▼]+$/, '').trim()
                || ('Column ' + (i + 1));
            var li = document.createElement('li');
            var lab = document.createElement('label');
            lab.className = 'dropdown-item';
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'me-2';
            cb.checked = hidden.indexOf(i) === -1;
            lab.appendChild(cb);
            lab.appendChild(document.createTextNode(label));
            li.appendChild(lab);
            ul.appendChild(li);
            lab.addEventListener('click', function (e) { e.stopPropagation(); });
            cb.addEventListener('change', function () { onChange(i, cb.checked); });
        });

        var card = table.closest('.card');
        if (card) {
            var header = card.querySelector(':scope > .card-header');
            if (header) {
                host.classList.add('tt-menu-float');
                header.appendChild(host);
            } else {
                var bar = document.createElement('div');
                bar.className = 'tt-bar';
                bar.appendChild(host);
                card.insertBefore(bar, card.firstChild);
            }
        } else if (table.parentNode) {
            table.parentNode.insertBefore(host, table);
        }
    }

    // ------------------------------------------------------------------ sorting
    function sortValue(td) {
        var text = (td ? td.textContent : '').trim();
        if (text === '' || text === '\u2014' || text === '-') { return null; }
        var numeric = text.replace(/[\s,]/g, '');
        if (/^-?\d+(\.\d+)?$/.test(numeric)) { return parseFloat(numeric); }
        return text.toLowerCase();
    }

    function sortRows(table, col, dir) {
        var body = table.tBodies[0];
        if (!body) { return; }
        var rows = [];
        var emptyRow = null;
        var totalRows = [];
        Array.prototype.forEach.call(body.rows, function (tr) {
            if (isEmptyRow(tr)) { emptyRow = tr; }
            else if (isTotalRow(tr)) { totalRows.push(tr); }
            else { rows.push(tr); }
        });
        rows.forEach(function (tr, i) { tr.setAttribute('data-tt-idx', String(i)); });
        rows.sort(function (a, b) {
            var va = sortValue(a.cells[col]);
            var vb = sortValue(b.cells[col]);
            var r;
            if (va === null && vb === null) { r = 0; }
            else if (va === null) { r = 1; }
            else if (vb === null) { r = -1; }
            else if (typeof va === 'number' && typeof vb === 'number') { r = va - vb; }
            else if (typeof va === 'number') { r = -1; }
            else if (typeof vb === 'number') { r = 1; }
            else { r = va < vb ? -1 : (va > vb ? 1 : 0); }
            if (r === 0) {
                r = Number(a.getAttribute('data-tt-idx')) - Number(b.getAttribute('data-tt-idx'));
            }
            return dir === 'desc' ? -r : r;
        });
        rows.forEach(function (tr) { body.appendChild(tr); });
        // Totals rows stay pinned below the sorted data - never mixed into it.
        totalRows.forEach(function (tr) { body.appendChild(tr); });
        if (emptyRow) { body.appendChild(emptyRow); }
        renumber(table);
    }

    function markSort(table, col, dir) {
        var headRow = table.tHead && table.tHead.rows[0];
        if (!headRow) { return; }
        Array.prototype.forEach.call(headRow.cells, function (th, i) {
            th.classList.toggle('tt-sorted-asc', i === col && dir === 'asc');
            th.classList.toggle('tt-sorted-desc', i === col && dir === 'desc');
        });
    }

    function currentSort(table) {
        var headRow = table.tHead && table.tHead.rows[0];
        var cur = { col: -1, dir: 'asc' };
        if (headRow) {
            Array.prototype.forEach.call(headRow.cells, function (th, i) {
                if (th.classList.contains('tt-sorted-asc')) { cur = { col: i, dir: 'asc' }; }
                if (th.classList.contains('tt-sorted-desc')) { cur = { col: i, dir: 'desc' }; }
            });
        }
        return cur;
    }

    // ------------------------------------------------------------------ AJAX
    // Region-based partial refresh: any container marked data-tt-partial
    // (plus the legacy #table-partial id) can be swapped on its own. Filter
    // forms name their region via data-tt-target (default #table-partial)
    // and their pagination param via data-tt-page (default "page"), so pages
    // with several independent pickers (transfer / replace) refresh one
    // table at a time while the other picker's state survives in the URL.
    function partialOf(el) {
        return el && el.closest ? el.closest('[data-tt-partial], #table-partial') : null;
    }

    function swapRegions(doc, regions) {
        var ok = false;
        Array.prototype.forEach.call(regions, function (region) {
            var fresh = region.id ? doc.getElementById(region.id) : null;
            if (!fresh) { return; }
            region.innerHTML = fresh.innerHTML;
            ok = true;
        });
        return ok;
    }

    function loadPartial(url, region) {
        if (!region) { window.location.href = url; return; }
        region.classList.add('tt-loading');
        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (res) { return res.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                region.classList.remove('tt-loading');
                if (!swapRegions(doc, [region])) { window.location.href = url; return; }
                syncFilterForms(doc);
                initAll();
            })
            .catch(function () { window.location.href = url; });
    }

    function filterForms() {
        return document.querySelectorAll('form.filter-bar, form[data-tt-target]');
    }

    function syncFilterForms(doc) {
        Array.prototype.forEach.call(filterForms(), function (form) {
            Array.prototype.forEach.call(form.elements, function (el) {
                if (!el.name) { return; }
                if (el.type === 'checkbox' || el.type === 'radio') {
                    // Checkbox groups share one name (name[]): match by value.
                    var wanted = el.value;
                    var list = doc.getElementsByName(el.name);
                    var found = false;
                    for (var i = 0; i < list.length; i++) {
                        if (list[i].value === wanted) {
                            el.checked = list[i].checked;
                            found = true;
                            break;
                        }
                    }
                    if (!found) { el.checked = false; }
                    // Let listeners (filter-dropdown counts) react as if the
                    // user had ticked the box.
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                    return;
                }
                var src = doc.getElementsByName(el.name)[0];
                if (!src) { return; }
                el.value = src.value;
            });
        });
    }

    // Column defaults: a header marked data-col-default="hide" starts hidden
    // when the table has no saved preference yet. A saved user preference
    // (the Columns menu) always wins over the default.
    function defaultHiddenColumns(table) {
        var out = [];
        var headRow = table.tHead && table.tHead.rows[0];
        if (!headRow) { return out; }
        Array.prototype.forEach.call(headRow.cells, function (th, i) {
            if (th.getAttribute('data-col-default') === 'hide') { out.push(i); }
        });
        return out;
    }

    // ------------------------------------------------------------------- init
    function initTable(table, index) {
        if (table.getAttribute('data-tt-init')) { return; }
        table.setAttribute('data-tt-init', '1');

        var key = table.getAttribute('data-tt-key') || (location.pathname + '#' + index);
        var hidden = [];
        var rawHidden = storeGet(COLS_KEY + key);
        if (rawHidden) {
            try { hidden = JSON.parse(rawHidden) || []; } catch (e) { hidden = []; }
        }

        addNumberColumn(table);
        if (!rawHidden) {
            hidden = defaultHiddenColumns(table);
        }
        applyHidden(table, hidden);
        buildColumnMenu(table, hidden, function (col, on) {
            var pos = hidden.indexOf(col);
            if (on && pos !== -1) { hidden.splice(pos, 1); }
            if (!on && pos === -1) { hidden.push(col); }
            storeSet(COLS_KEY + key, JSON.stringify(hidden));
            applyHidden(table, hidden);
        });

        var headRow = table.tHead && table.tHead.rows[0];
        if (!headRow) { return; }

        var server = table.getAttribute('data-tt-sort') === 'server';
        var stored = null;
        var rawSort = storeGet(SORT_KEY + key);
        if (rawSort) {
            try { stored = JSON.parse(rawSort); } catch (e) { stored = null; }
        }

        var urlParams = new URLSearchParams(location.search);
        var urlSort  = urlParams.get('sort');
        var urlDir   = urlParams.get('dir') === 'desc' ? 'desc' : 'asc';

        function indexOfServerSort(sortKey) {
            var idx = -1;
            Array.prototype.forEach.call(headRow.cells, function (th, i) {
                if (th.getAttribute('data-sort') === sortKey) { idx = i; }
            });
            return idx;
        }
        function indexOfSlugSort(slug) {
            var idx = -1;
            Array.prototype.forEach.call(headRow.cells, function (th, i) {
                if (slugify(th.textContent) === slug) { idx = i; }
            });
            return idx;
        }

        if (server) {
            if (urlSort) {
                markSort(table, indexOfServerSort(urlSort), urlDir);
            } else if (stored && stored[0]) {
                // Restore the last-used sort via URL (shareable + persisted).
                var sp = new URLSearchParams(location.search);
                sp.set('sort', stored[0]);
                sp.set('dir', stored[1] === 'desc' ? 'desc' : 'asc');
                sp.delete('page');
                var target = location.pathname + '?' + sp.toString();
                history.replaceState({ tt: 1 }, '', target);
                loadPartial(target);
            }
        } else if (urlSort) {
            var ci = indexOfSlugSort(urlSort);
            if (ci !== -1) {
                sortRows(table, ci, urlDir);
                markSort(table, ci, urlDir);
            }
        } else if (stored && typeof stored[0] === 'number' && headRow.cells[stored[0]]) {
            var sdir = stored[1] === 'desc' ? 'desc' : 'asc';
            sortRows(table, stored[0], sdir);
            markSort(table, stored[0], sdir);
            var cp = new URLSearchParams(location.search);
            cp.set('sort', slugify(headRow.cells[stored[0]].textContent));
            cp.set('dir', sdir);
            history.replaceState({ tt: 1 }, '', location.pathname + '?' + cp.toString());
        }

        // ---- sortable header clicks ----------------------------------------
        Array.prototype.forEach.call(headRow.cells, function (th, i) {
            if (th.hasAttribute('data-no-sort') || th.classList.contains('tt-num')) { return; }
            th.classList.add('tt-sortable');
            th.addEventListener('click', function () {
                var cur = currentSort(table);
                var dir = (cur.col === i && cur.dir === 'asc') ? 'desc' : 'asc';
                var sortKey = th.getAttribute('data-sort');

                if (server && sortKey) {
                    // Server-side sort: the URL carries sort=<key>&dir= so the
                    // server sorts across ALL pages, not just the visible one.
                    var sp2 = new URLSearchParams(location.search);
                    sp2.set('sort', sortKey);
                    sp2.set('dir', dir);
                    sp2.delete('page');
                    var url = location.pathname + '?' + sp2.toString();
                    storeSet(SORT_KEY + key, JSON.stringify([sortKey, dir]));
                    markSort(table, i, dir);
                    history.pushState({ tt: 1 }, '', url);
                    loadPartial(url, partialOf(table));
                } else {
                    sortRows(table, i, dir);
                    markSort(table, i, dir);
                    storeSet(SORT_KEY + key, JSON.stringify([i, dir]));
                    var cp2 = new URLSearchParams(location.search);
                    cp2.set('sort', slugify(th.textContent));
                    cp2.set('dir', dir);
                    cp2.delete('page');
                    history.replaceState({ tt: 1 }, '', location.pathname + '?' + cp2.toString());
                }
            });
        });
    }

    function initAll() {
        Array.prototype.forEach.call(document.querySelectorAll('table.table'), function (table, index) {
            initTable(table, index);
        });
    }

    // ------------------------------------------------------------- bootstrap
    document.addEventListener('DOMContentLoaded', function () {
        // ---- filter submits refresh only the form's target region --------
        Array.prototype.forEach.call(filterForms(), function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var targetId = form.getAttribute('data-tt-target') || 'table-partial';
                var pageParam = form.getAttribute('data-tt-page') || 'page';
                // Start from the current URL so the OTHER regions' params
                // (and sort/dir) survive, then overlay this form's fields.
                var params = new URLSearchParams(location.search);
                // Multi-value fields (checkbox groups post name[]=a&name[]=b):
                // drop every existing occurrence first, then APPEND all
                // values - params.set() would keep only the last checked box.
                var names = [];
                new FormData(form).forEach(function (value, name) {
                    if (names.indexOf(name) === -1) { names.push(name); }
                });
                names.forEach(function (name) { params.delete(name); });
                new FormData(form).forEach(function (value, name) {
                    params.append(name, value);
                });
                params.delete(pageParam);
                var url = location.pathname + (params.toString() ? '?' + params.toString() : '');
                history.pushState({ tt: 1 }, '', url);
                loadPartial(url, document.getElementById(targetId));
            });
        });

        // ---- pagination links (and explicit data-tt-ajax links) inside
        //      any region refresh just that region --------------------------
        document.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('a.page-link, a[data-tt-ajax]') : null;
            if (!a) { return; }
            var region = partialOf(a);
            if (!region) { return; }
            var href = a.getAttribute('href');
            if (!href || href.charAt(0) === '#') { return; }
            e.preventDefault();
            var target = new URL(href, location.href);
            var url = target.pathname + target.search;
            history.pushState({ tt: 1 }, '', url);
            loadPartial(url, region);
        });

        // ---- browser back/forward re-applies every region at once ---------
        window.addEventListener('popstate', function () {
            var regions = document.querySelectorAll('[data-tt-partial], #table-partial');
            if (!regions.length) { return; }
            fetch(location.href, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (res) { return res.text(); })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    if (!swapRegions(doc, regions)) { return; }
                    syncFilterForms(doc);
                    initAll();
                })
                .catch(function () { /* keep the current view */ });
        });

        initAll();
    });
})();
