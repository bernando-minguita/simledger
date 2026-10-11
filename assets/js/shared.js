/* SIMLedger shared UI helpers.
 *
 * slDialog(opts) -> Promise
 *   Promise-based, Bootstrap-styled replacement for the native confirm()/
 *   prompt() dialogs (which are un-themeable and jarring). Renders a centered
 *   card like the rest of the UI: message, optional single-line input, and
 *   Cancel / OK buttons. Esc and backdrop never approve; Cancel/Esc/backdrop
 *   reject (treated as cancel) so destructive actions can never be approved
 *   by a stray click. Only if Bootstrap itself is unavailable does this fall
 *   back to the native dialogs, so the app never hard-blocks.
 *
 * Declarative wiring (no per-page JS needed):
 *   <form data-confirm="Are you sure?">                      styled confirm
 *   <form data-confirm="..." data-confirm-danger>            red OK button
 *   <form data-confirm="..." data-confirm-ok="Yes, do it">   custom OK label
 *   <form data-confirm="..." data-prompt="field_name"
 *         data-prompt-label="Reason">   confirm + one required line of text,
 *                                       written into that form field on OK
 *
 * The guarded re-submit uses a form flag (not the event object) because
 * requestSubmit() fires a brand-new submit event. requestSubmit() also
 * re-runs HTML constraint validation, so a dialog only appears once the form
 * is genuinely submittable - same ordering users get from a native confirm.
 */
(function () {
    'use strict';

    var DIALOG_HTML =
        '<div class="modal-dialog modal-dialog-centered" role="document">' +
        '  <div class="modal-content">' +
        '    <div class="modal-body pt-4">' +
        '      <p class="mb-0" id="sl-dialog-msg"></p>' +
        '      <div class="mt-3 d-none" id="sl-dialog-input-wrap">' +
        '        <label class="form-label small mb-1" id="sl-dialog-input-label"></label>' +
        '        <input type="text" class="form-control" id="sl-dialog-input">' +
        '      </div>' +
        '    </div>' +
        '    <div class="modal-footer">' +
        '      <button type="button" class="btn btn-outline-secondary" data-sl-cancel>Cancel</button>' +
        '      <button type="button" class="btn btn-primary" data-sl-ok>OK</button>' +
        '    </div>' +
        '  </div>' +
        '</div>';

    function slDialog(opts) {
        opts = opts || {};
        return new Promise(function (resolve, reject) {
            if (!window.bootstrap) { // last resort - styled UI impossible
                var msg = String(opts.message || '');
                if (opts.input) {
                    var v = window.prompt(msg);
                    resolve(v === null ? null : v.trim());
                } else {
                    resolve(window.confirm(msg));
                }
                return;
            }

            var wrap = document.createElement('div');
            wrap.className = 'modal fade';
            wrap.setAttribute('tabindex', '-1');
            wrap.setAttribute('aria-hidden', 'true');
            wrap.innerHTML = DIALOG_HTML;
            document.body.appendChild(wrap);

            wrap.querySelector('#sl-dialog-msg').textContent = String(opts.message || '');

            var okBtn = wrap.querySelector('[data-sl-ok]');
            if (opts.okLabel) { okBtn.textContent = String(opts.okLabel); }
            if (opts.danger) {
                okBtn.classList.remove('btn-primary');
                okBtn.classList.add('btn-danger');
            }

            var inputWrap = wrap.querySelector('#sl-dialog-input-wrap');
            var input     = wrap.querySelector('#sl-dialog-input');
            if (opts.input) {
                inputWrap.classList.remove('d-none');
                wrap.querySelector('#sl-dialog-input-label').textContent =
                    String(opts.inputLabel || '');
                input.value = String(opts.inputValue || '');
            }

            var modal = new bootstrap.Modal(wrap, { backdrop: 'static', keyboard: true });
            var settled = false;

            function settle(fn, value) {
                if (settled) { return; }
                settled = true;
                wrap.addEventListener('hidden.bs.modal', function () { wrap.remove(); });
                modal.hide();
                fn(value);
            }

            var cancel = function () { settle(reject, new Error('slDialog cancelled')); };

            okBtn.addEventListener('click', function () {
                if (opts.input) {
                    var v = input.value.trim();
                    if (v === '') { input.focus(); return; }  // one required line
                    settle(resolve, v);
                } else {
                    settle(resolve, true);
                }
            });
            wrap.querySelector('[data-sl-cancel]').addEventListener('click', cancel);
            wrap.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { cancel(); }
            });
            wrap.addEventListener('hidden.bs.modal', cancel);   // safety net
            if (opts.input) {
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); okBtn.click(); }
                });
                wrap.addEventListener('shown.bs.modal', function () { input.focus(); });
            }

            modal.show();
        });
    }
    window.slDialog = slDialog;
    // One delegated listener instead of per-form binding: forms injected or
    // replaced by a table-toolkit partial refresh are covered automatically.
    document.addEventListener('submit', function (e) {
        var form = e.target && e.target.closest ? e.target.closest('form[data-confirm]') : null;
        if (!form) { return; }
        if (form.slApproved) {           // our own re-submit - go through
            form.slApproved = false;
            return;
        }
        e.preventDefault();
        var opts = { message: form.getAttribute('data-confirm') };
        if (form.hasAttribute('data-confirm-danger')) { opts.danger = true; }
        if (form.hasAttribute('data-confirm-ok')) {
            opts.okLabel = form.getAttribute('data-confirm-ok');
        }
        var promptField = form.getAttribute('data-prompt');
        if (promptField) {
            opts.input = true;
            opts.inputLabel = form.getAttribute('data-prompt-label') || 'Reason';
            opts.inputValue = form.getAttribute('data-prompt-value') || '';
        }
        slDialog(opts).then(function (result) {
            if (promptField && form.elements[promptField]) {
                form.elements[promptField].value = result;
            }
            form.slApproved = true;
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();     // new submit event; guard passes once
            } else {
                HTMLFormElement.prototype.submit.call(form);
            }
        }).catch(function () { /* cancelled - stay on the page */ });
    });
})();
