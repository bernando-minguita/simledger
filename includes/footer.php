<?php
/**
 * SIMLedger - page footer (content close, sidebar
 * toggle behaviour).
 */

declare(strict_types=1);
?>
    </main>

    <footer class="site-footer">
        <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &mdash; company SIM assets are tracked by assignment, never overwritten.</span>
    </footer>
</div><!-- /.app-content -->

<div class="sidebar-backdrop" data-sidebar-close></div>

<script src="<?= e($base ?? '') ?>assets/js/bootstrap.bundle.min.js"></script>
<script src="<?= e($base ?? '') ?>assets/js/table-toolkit.js"></script>
<script src="<?= e($base ?? '') ?>assets/js/picker.js"></script>
<script src="<?= e($base ?? '') ?>assets/js/shared.js"></script>
<script>
/* Sidebar toggle: off-canvas below 992px, always visible on desktop.
   Tapping the backdrop or any nav link closes the drawer again. */
(function () {
    'use strict';

    function setOpen(open) {
        document.body.classList.toggle('sidebar-open', open);
        var btn = document.querySelector('[data-sidebar-toggle]');
        if (btn) {
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    document.querySelectorAll('[data-sidebar-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setOpen(!document.body.classList.contains('sidebar-open'));
        });
    });

    document.querySelectorAll('[data-sidebar-close]').forEach(function (el) {
        el.addEventListener('click', function () { setOpen(false); });
    });

    document.querySelectorAll('.app-sidebar a').forEach(function (link) {
        link.addEventListener('click', function () { setOpen(false); });
    });
})();
</script>
</body>
</html>
