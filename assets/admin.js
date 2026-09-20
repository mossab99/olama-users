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

    function initializeMemberEditor(editor) {
        var list = editor.querySelector('[data-member-list]');
        var template = editor.querySelector('[data-member-template]');
        var add = editor.querySelector('[data-add-member]');
        if (!list || !template || !add) {
            return;
        }

        function filterSections(row, keepSelection) {
            var grade = row.querySelector('[data-member-grade]');
            var section = row.querySelector('[data-member-section]');
            if (!grade || !section) {
                return;
            }
            var selectedSection = keepSelection ? section.value : '';
            Array.prototype.forEach.call(section.options, function (option) {
                var optionGrade = option.getAttribute('data-grade-id');
                option.hidden = !!optionGrade && optionGrade !== grade.value;
                option.disabled = option.hidden;
            });
            if (!selectedSection || !section.querySelector('option[value="' + selectedSection + '"]:not([disabled])')) {
                section.value = '';
            }
        }

        Array.prototype.forEach.call(list.querySelectorAll('[data-member-row]'), function (row) {
            filterSections(row, true);
        });
        add.addEventListener('click', function () {
            var holder = document.createElement('div');
            holder.innerHTML = template.innerHTML.replace(/__INDEX__/g, 'new_' + Date.now());
            var row = holder.firstElementChild;
            if (row) {
                list.appendChild(row);
                filterSections(row, false);
            }
        });
        editor.addEventListener('change', function (event) {
            if (event.target.matches('[data-member-grade]')) {
                filterSections(event.target.closest('[data-member-row]'), false);
            }
        });
        editor.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-remove-member]');
            if (remove) {
                remove.closest('[data-member-row]').remove();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.olama-capability-form').forEach(initializeSelectAll);
        document.querySelectorAll('[data-temp-members]').forEach(initializeMemberEditor);

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
