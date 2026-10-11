/**
 * simledger picker tables - shared click-to-select behaviour for every
 * <table data-picker="key">: row highlight, "✓ Selected" button swap,
 * "Selected:" status line, optional preview field, data-keep hidden-input
 * sync (survives search/page reloads), and #reviewBtn gating.
 *
 * Row markup:  <tr data-<key>-id="N" data-label="...">
 * Optional:     data-sim="M" + table data-sim-sync="hiddenId"  (extra sync)
 *               data-preview="pvId"  (preview field update)
 */
(function () {
    'use strict';

    var tables = [];

    function keepInputs(key) {
        return Array.prototype.slice.call(
            document.querySelectorAll('input[data-keep="' + key + '"]'));
    }

    function syncReview() {
        var btn = document.getElementById('reviewBtn');
        if (!btn || tables.length === 0) { return; }
        var ok = tables.every(function (t) {
            return keepInputs(t.key).some(function (el) { return el.value !== ''; });
        });
        btn.disabled = !ok;
    }

    function paint(t, row) {
        if (t.selected && t.selected !== row) {
            t.selected.classList.remove('row-selected');
            var prev = t.selected.querySelector('button[data-role="pick"]');
            if (prev) {
                prev.textContent = 'Select';
                prev.classList.remove('btn-primary');
                prev.classList.add('btn-outline-primary');
            }
        }
        row.classList.add('row-selected');
        var btn = row.querySelector('button[data-role="pick"]');
        if (btn) {
            btn.textContent = '✓ Selected';
            btn.classList.add('btn-primary');
            btn.classList.remove('btn-outline-primary');
        }
        t.selected = row;

        var val = row.getAttribute('data-' + t.key + '-id') || '';
        keepInputs(t.key).forEach(function (el) { el.value = val; });

        var label = row.getAttribute('data-label') || '';
        if (t.status) { t.status.textContent = 'Selected: ' + label; }
        if (t.preview) { t.preview.textContent = label; }
        if (t.simSync) {
            var hid = document.getElementById(t.simSync);
            if (hid) { hid.value = row.getAttribute('data-sim') || 0; }
        }
        syncReview();
    }

    function initTable(table) {
        var t = {
            key: table.getAttribute('data-picker'),
            table: table,
            status: document.getElementById(table.getAttribute('data-status') || ''),
            preview: document.getElementById(table.getAttribute('data-preview') || ''),
            simSync: table.getAttribute('data-sim-sync'),
            selected: table.querySelector('tr.row-selected')
        };
        if (!t.key) { return; }
        var sel = 'tr[data-' + t.key + '-id]';

        table.addEventListener('click', function (e) {
            var row = e.target.closest ? e.target.closest(sel) : null;
            if (row) { paint(t, row); }
        });

        if (t.selected) { paint(t, t.selected); }
        tables.push(t);
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(
            document.querySelectorAll('table[data-picker]'), initTable);
        syncReview();
    });
})();