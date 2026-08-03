/**
 * Confirms destructive actions (delete index, remove item) before their
 * form submits, using each form's own data-confirm message.
 */
(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.classList.contains('aikc-confirm-delete')) {
            return;
        }

        var message = form.getAttribute('data-confirm') || 'Are you sure?';

        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });
})();
