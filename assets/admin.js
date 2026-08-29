(function () {
    'use strict';

    function initializeSelectAll(form) {
        var selectAll = form.querySelector('.olama-cap-select-all');
        var capabilities = Array.prototype.slice.call(form.querySelectorAll('input[name="caps[]"]'));

        if (!selectAll || !capabilities.length) {
            return;
        }

        function updateSelectAll() {
            var checkedCount = capabilities.filter(function (checkbox) {
                return checkbox.checked;
            }).length;

            selectAll.checked = checkedCount === capabilities.length;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < capabilities.length;
        }

        selectAll.addEventListener('change', function () {
            capabilities.forEach(function (checkbox) {
                checkbox.checked = selectAll.checked;
            });
            updateSelectAll();
        });

        capabilities.forEach(function (checkbox) {
            checkbox.addEventListener('change', updateSelectAll);
            checkbox.addEventListener('change', function () {
                var capability = checkbox.getAttribute('data-capability');
                if (!capability) {
                    return;
                }
                capabilities.forEach(function (matchingCheckbox) {
                    if (matchingCheckbox !== checkbox && matchingCheckbox.getAttribute('data-capability') === capability) {
                        matchingCheckbox.checked = checkbox.checked;
                    }
                });
                updateSelectAll();
            });
        });

        updateSelectAll();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.olama-capability-form').forEach(initializeSelectAll);

        document.querySelectorAll('[data-olama-modal-open]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                var modal = document.getElementById(trigger.getAttribute('data-olama-modal-open'));
                if (!modal) {
                    return;
                }
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('olama-modal-open');
                modal.querySelector('.olama-modal-close').focus();
            });
        });

        document.querySelectorAll('[data-olama-modal-close]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                var modal = trigger.closest('.olama-modal');
                if (modal) {
                    modal.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('olama-modal-open');
                }
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }
            document.querySelectorAll('.olama-modal[aria-hidden="false"]').forEach(function (modal) {
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('olama-modal-open');
            });
        });
    });
}());
