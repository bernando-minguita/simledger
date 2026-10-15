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
 *   - drag a header's right edge to resize that column: widths are frozen into
 *     px and taken from (or given to) the neighbouring column so the table keeps
 *     filling its card; the result is persisted per page in localStorage. The
 *     Columns menu also offers auto-fit (size every column to its content) and
 *     reset
 *   - filter submits and pagination links refresh only their table REGION
 *     (fetch + history API): any container marked data-tt-partial, plus the
 *     legacy #table-partial id; forms declare data-tt-target / data-tt-page
 */
(function () {
    'use strict';

    var COLS_KEY = 'simledger:tt:cols:';
    var SORT_KEY = 'simledger:tt:sort:';
    var WIDTHS_KEY = 'simledger:tt:widths:';

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

    // ----------------------------------------------------------------- widths
    // Drag-to-resize column edges, persisted per page in localStorage.
    //
    // The tables are table-layout: fixed, so a width set on the first-row cell
    // IS the column width and the body follows automatically. While the columns
    // add up to less than the container the browser stretches them to fill it,
    // so a drag would not track the pointer - the first drag therefore "freezes"
    // the rendered widths into inline px.
    //
    // The table itself is deliberately NEVER given a fixed width: it stays 100%
    // wide so it always fills the card and the docked action column sits flush
    // against the right edge. Instead a drag takes its width from (or gives it
    // to) the next visible column, so the total - and therefore the table width
    // - never changes.
    //
    // A column is identified by data-sort when it has one, so widths survive
    // the column being hidden and re-shown (and are unrelated to its position).
    function colId(th, index) {
        if (th.classList.contains('tt-num')) { return '#num'; }
        var s = th.getAttribute('data-sort');
        return s ? 'k:' + s : 'c:' + index;
    }

    // The "#" row-number column is FIXED at its stylesheet width (76px - see
    // th.tt-num): it gets no resize grip, is skipped by freeze / auto-fit /
    // save / restore, and can never be a drag partner. Everything else only
    // ever steals width from (or yields it to) its neighbour, so the table
    // keeps filling its card.

    function visibleCells(headRow) {
        var out = [];
        Array.prototype.forEach.call(headRow.cells, function (th, i) {
            if (!th.classList.contains('tt-hidden-c')) { out.push([th, i]); }
        });
        return out;
    }

    // Freezing gives every column an explicit width, which can leave the table
    // wider than its container - so make sure it can scroll. The TABLE is never
    // given a fixed width: it must stay 100% wide so it always fills the card,
    // with the docked action column flush against the right edge.
    function ensureScrollable(table) {
        for (var p = table.parentNode; p && p.nodeType === 1; p = p.parentNode) {
            var ov = window.getComputedStyle(p).overflowX;
            if (ov === 'auto' || ov === 'scroll') { return; }
        }
        var parent = table.parentNode;
        if (parent && parent.nodeType === 1) { parent.style.overflowX = 'auto'; }
    }

    // Freeze the widths currently rendered into inline px, so a drag is not
    // absorbed by the browser stretching the columns to fill the container.
    // Fractional precision is kept (no Math.round): with table-layout: fixed
    // the browser stretches N columns to fill the container, so each rendered
    // width is usually fractional (e.g. 123.37px). Rounding every column to an
    // integer loses up to N/2 px of total width in one step, and the columns
    // visibly jump - the reported "other columns move to the right" on wide
    // tables like the SIM Inventory Report.
    function freezeWidths(table, headRow) {
        if (table.hasAttribute('data-tt-frozen')) { return; }
        visibleCells(headRow).forEach(function (pair) {
            // The "#" column is fixed at its stylesheet 76px - it must never
            // get an inline width, so a freeze cannot pin a stretched copy.
            if (pair[0].classList.contains('tt-num')) { return; }
            var w = pair[0].getBoundingClientRect().width;
            if (w > 0) { pair[0].style.width = w + 'px'; }
        });
        table.setAttribute('data-tt-frozen', '1');
        ensureScrollable(table);
    }

    function applyWidths(table, headRow, widths) {
        if (!widths) { return; }
        var any = false;
        visibleCells(headRow).forEach(function (pair) {
            var id = colId(pair[0], pair[1]);
            // The "#" column is fixed at its stylesheet 76px: ignore any width
            // stored before the grip was removed, so it always snaps back.
            if (id === '#num') {
                pair[0].style.width = '';
                return;
            }
            var w = widths[id];
            if (w) {
                pair[0].style.width = w + 'px';
                any = true;
            }
        });
        if (any) {
            table.setAttribute('data-tt-frozen', '1');
            ensureScrollable(table);
        }
    }

    function saveWidths(table, headRow, key) {
        var out = {};
        visibleCells(headRow).forEach(function (pair) {
            // The "#" column is fixed - never persist a width for it, so a
            // value narrowed before the grip was removed cannot come back.
            if (pair[0].classList.contains('tt-num')) { return; }
            var w = parseFloat(pair[0].style.width);
            // One decimal keeps the file small without re-introducing the
            // freeze rounding jump (integers can lose ~N/2 px of total width).
            if (w > 0) { out[colId(pair[0], pair[1])] = Math.round(w * 10) / 10; }
        });
        storeSet(WIDTHS_KEY + key, JSON.stringify(out));
    }

    function resetWidths(table, headRow, key) {
        Array.prototype.forEach.call(headRow.cells, function (th) {
            th.style.width = '';
        });
        table.style.width = '';
        table.removeAttribute('data-tt-frozen');
        try { localStorage.removeItem(WIDTHS_KEY + key); } catch (e) { /* private mode */ }
    }

    // "Auto-fit": size every visible column to its content, the way a
    // spreadsheet does. The browser does the measuring - the table is briefly
    // switched to auto layout with nowrap cells, the natural widths are read,
    // then frozen and stored. Reading getBoundingClientRect() forces the layout
    // synchronously but never paints, so the temporary state is invisible.
    function autoFitWidths(table, headRow, key) {
        var MIN = 80;
        var MAX = 480;

        var cells = visibleCells(headRow);
        if (cells.length === 0) { return; }

        // Clear the current widths so the browser is free to size to content.
        cells.forEach(function (pair) { pair[0].style.width = ''; });

        table.classList.add('tt-autofit');
        var natural = [];
        cells.forEach(function (pair) {
            natural.push(Math.round(pair[0].getBoundingClientRect().width));
        });
        table.classList.remove('tt-autofit');

        var out = {};
        cells.forEach(function (pair, i) {
            var id  = colId(pair[0], pair[1]);
            // The "#" column is fixed: leave it on its stylesheet width and
            // never store a width for it.
            if (id === '#num') {
                pair[0].style.width = '';
                return;
            }
            var w   = Math.max(MIN, Math.min(MAX, natural[i]));
            pair[0].style.width = w + 'px';
            out[id] = w;
        });

        table.setAttribute('data-tt-frozen', '1');
        ensureScrollable(table);
        storeSet(WIDTHS_KEY + key, JSON.stringify(out));
    }

    function initResize(table, headRow, key) {
        var MIN = 40;

        Array.prototype.forEach.call(headRow.cells, function (th) {
            if (th.querySelector('.tt-resize')) { return; }
            // The "#" row-number column is fixed at its 76px design width - it
            // can never widen, so a grip on it would only ever disturb the
            // neighbour column. No grip, no drag.
            if (th.classList.contains('tt-num')) { return; }

            var grip = document.createElement('span');
            grip.className = 'tt-resize';
            grip.setAttribute('title', 'Drag to resize');
            th.appendChild(grip);

            // A click that lands on the grip must never reach the header's
            // sort handler.
            grip.addEventListener('click', function (e) { e.stopPropagation(); });

            grip.addEventListener('pointerdown', function (e) {
                if (e.pointerType === 'mouse' && e.button !== 0) { return; }
                e.preventDefault();
                e.stopPropagation();

                freezeWidths(table, headRow);

                // The neighbour that gives up (or takes) the difference, so the
                // table's total width - and therefore its fill of the card -
                // never changes. Falls back to the previous column, which is the
                // boundary a last-column drag actually moves. The fixed "#"
                // column never takes part: it can neither give nor receive.
                // Re-read the rendered widths AFTER the freeze: freeze() rewrites
                // style.width on every column, so measuring before it would hand
                // the drag stale startW/mateW values and the two columns would
                // visibly snap on the first pointermove - the reported "other
                // columns move to the right" on wide tables like the SIM
                // Inventory Report. getBoundingClientRect() forces a sync
                // reflow, so these reads observe the freshly frozen layout.
                var cells = visibleCells(headRow);
                var at = -1;
                for (var i = 0; i < cells.length; i++) {
                    if (cells[i][0] === th) { at = i; }
                }
                var mate = null;
                if (at !== -1 && cells.length > 1) {
                    for (var j = at + 1; j < cells.length; j++) {
                        if (!cells[j][0].classList.contains('tt-num')) { mate = cells[j][0]; break; }
                    }
                    if (!mate) {
                        for (var k = at - 1; k >= 0; k--) {
                            if (!cells[k][0].classList.contains('tt-num')) { mate = cells[k][0]; break; }
                        }
                    }
                }

                // Tables wider than their wrapper scroll instead of stretching:
                // resizing one column then needs no partner - stealing from the
                // neighbour would only make it visibly jump (reported on Current
                // Assignments and the SIM Inventory Report, both of which
                // overflow). Only tables that fill their card keep the neighbour
                // trade, which preserves the total and avoids a global
                // re-stretch of every column.
                var wrapW  = table.parentNode ? table.parentNode.clientWidth : 0;
                var tableW = table.getBoundingClientRect().width;
                var single = tableW > wrapW + 1;
                if (single) { mate = null; }

                var startX = e.clientX;
                var startW = th.getBoundingClientRect().width;
                var mateW  = mate ? mate.getBoundingClientRect().width : 0;
                var moved  = false;
                // Pointermove can fire several times per frame; two columns whose
                // inline widths disagree with the layout for even one frame read
                // as judder on wide tables (25 body rows re-lay out per write).
                // Coalesce to one paired write per animation frame so the two
                // widths always change atomically.
                var rafId  = 0;
                var lastD  = 0;

                function flush() {
                    rafId = 0;
                    var d = lastD;
                    if (mate) {
                        // Card-filling table: clamp so neither column drops
                        // below MIN; the total never changes.
                        d = Math.max(MIN - startW, Math.min(mateW - MIN, d));
                        th.style.width   = (startW + d) + 'px';
                        mate.style.width = (mateW - d) + 'px';
                    } else if (single) {
                        // Scrolling table: only this column moves. It can grow
                        // freely (the wrapper scrolls) but may not shrink past
                        // MIN or past the point where the table stops filling
                        // its card (which would re-stretch every column).
                        d = Math.max(MIN - startW, wrapW - tableW, d);
                        th.style.width = (startW + d) + 'px';
                    } else {
                        th.style.width = Math.max(MIN, startW + d) + 'px';
                    }
                }

                function move(ev) {
                    var d = ev.clientX - startX;
                    if (Math.abs(d) > 2) { moved = true; }
                    lastD = d;
                    if (!rafId) { rafId = requestAnimationFrame(flush); }
                }

                function stop() {
                    if (rafId) { cancelAnimationFrame(rafId); rafId = 0; }
                    // A drag that ends mid-frame still has a queued delta -
                    // apply it so the saved widths match what was on screen.
                    if (moved) { flush(); }
                    grip.removeEventListener('pointermove', move);
                    grip.removeEventListener('pointerup', stop);
                    grip.removeEventListener('pointercancel', stop);
                    document.body.classList.remove('tt-resizing');
                    grip.classList.remove('tt-active');
                    if (moved) { saveWidths(table, headRow, key); }
                }

                grip.setPointerCapture(e.pointerId);
                grip.addEventListener('pointermove', move);
                grip.addEventListener('pointerup', stop);
                grip.addEventListener('pointercancel', stop);
                document.body.classList.add('tt-resizing');
                grip.classList.add('tt-active');
            });
        });
    }

    function buildColumnMenu(table, hidden, key, onChange) {
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

        // Column-width actions: fit every column to its content, or clear the
        // dragged widths back to the stylesheet defaults.
        function closeMenu() {
            var toggle = host.querySelector('[data-bs-toggle="dropdown"]');
            if (toggle && window.bootstrap && window.bootstrap.Dropdown) {
                var inst = window.bootstrap.Dropdown.getInstance(toggle);
                if (inst) { inst.hide(); }
            }
        }

        function widthAction(label, fn) {
            var li = document.createElement('li');
            var b  = document.createElement('button');
            b.type = 'button';
            b.className = 'dropdown-item';
            b.textContent = label;
            li.appendChild(b);
            ul.appendChild(li);
            b.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                fn();
                closeMenu();
            });
        }

        var sep = document.createElement('li');
        sep.innerHTML = '<hr class="dropdown-divider">';
        ul.appendChild(sep);

        widthAction('Auto-fit column widths', function () {
            autoFitWidths(table, headRow, key);
        });
        widthAction('Reset column widths', function () {
            resetWidths(table, headRow, key);
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

    function loadPartial(url, region, soft) {
        if (!region) {
            if (!soft) { window.location.href = url; }
            return;
        }
        region.classList.add('tt-loading');
        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (res) { return res.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                region.classList.remove('tt-loading');
                if (!swapRegions(doc, [region])) {
                    if (!soft) { window.location.href = url; }
                    return;
                }
                syncFilterForms(doc);
                initAll();
            })
            .catch(function () {
                region.classList.remove('tt-loading');
                // "soft" callers (the remembered-sort restore, which fires on
                // page load) must NEVER navigate: a failed fetch used to fall
                // back to window.location.href and reload the whole page a
                // second time - sidebar and all. Degrade to the server's
                // default order instead.
                if (!soft) { window.location.href = url; }
            });
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

        var headRow = table.tHead && table.tHead.rows[0];
        if (!headRow) { return; }

        buildColumnMenu(table, hidden, key, function (col, on) {
            var pos = hidden.indexOf(col);
            if (on && pos !== -1) { hidden.splice(pos, 1); }
            if (!on && pos === -1) { hidden.push(col); }
            storeSet(COLS_KEY + key, JSON.stringify(hidden));
            applyHidden(table, hidden);
        });

        // Restore dragged column widths (applied before the first paint, since
        // the toolkit boots during parsing).
        var rawWidths = storeGet(WIDTHS_KEY + key);
        if (rawWidths) {
            try { applyWidths(table, headRow, JSON.parse(rawWidths)); } catch (e) { /* ignore */ }
        }
        initResize(table, headRow, key);

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
                // The table's own region MUST be passed: without it loadPartial()
                // has no target to swap and falls back to window.location.href,
                // so every visit to a previously-sorted list navigated a SECOND
                // time (the whole page - sidebar included - rendered twice).
                // The trailing true = "soft": this runs on page load, so even a
                // failed fetch must not navigate - it just keeps the server's
                // default order.
                var sp = new URLSearchParams(location.search);
                sp.set('sort', stored[0]);
                sp.set('dir', stored[1] === 'desc' ? 'desc' : 'asc');
                sp.delete('page');
                var target = location.pathname + '?' + sp.toString();
                history.replaceState({ tt: 1 }, '', target);
                loadPartial(target, partialOf(table), true);
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
    // Runs IMMEDIATELY - deliberately NOT on DOMContentLoaded.
    //
    // This script sits at the end of <body>, so every table is already parsed
    // here. Initialising now inserts the "#" row-number column BEFORE the
    // browser's first paint. Waiting for DOMContentLoaded let the browser paint
    // the tables first and the column landed a frame later, so every column
    // visibly jumped to the right (the reported "column flicker").
    //
    // initAll() is idempotent - initTable() skips any table already carrying
    // data-tt-init - so the partial-refresh path below can keep calling it.
    (function boot() {
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
    })();
})();
