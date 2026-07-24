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
    });
}());
