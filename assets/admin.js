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

    function initializeStudentPicker(picker) {
        var input = picker.querySelector('[data-student-search]');
        var button = picker.querySelector('[data-student-search-button]');
        var results = picker.querySelector('[data-student-search-results]');
        var selected = picker.querySelector('[data-student-selected]');
        if (!input || !button || !results || !selected || typeof olamaUsersAdmin === 'undefined') {
            return;
        }

        function escapeHtml(value) {
            var element = document.createElement('div');
            element.textContent = value == null ? '' : String(value);
            return element.innerHTML;
        }

        function addStudent(student) {
            var exists = Array.prototype.some.call(selected.querySelectorAll('[data-student-uid]'), function (item) {
                return item.getAttribute('data-student-uid') === student.uid;
            });
            if (exists) {
                return;
            }
            var chip = document.createElement('span');
            chip.className = 'olama-temp-student';
            chip.setAttribute('data-student-uid', student.uid);
            chip.innerHTML = '<input type="hidden" name="student_uids[]" value="' + escapeHtml(student.uid) + '">' +
                '<strong>' + escapeHtml(student.name) + '</strong><small>' + escapeHtml(student.uid) + '</small>' +
                '<button type="button" class="button-link-delete" data-remove-student aria-label="' + escapeHtml(olamaUsersAdmin.remove) + '">&times;</button>';
            selected.appendChild(chip);
        }

        function search() {
            var term = input.value.trim();
            if (term.length < 2) {
                return;
            }
            results.textContent = olamaUsersAdmin.searching;
            fetch(ajaxurl + '?action=olama_users_search_students&nonce=' + encodeURIComponent(olamaUsersAdmin.studentSearchNonce) + '&term=' + encodeURIComponent(term), { credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    results.innerHTML = '';
                    var rows = payload && payload.success ? payload.data : [];
                    if (!rows.length) {
                        results.textContent = olamaUsersAdmin.noStudents;
                        return;
                    }
                    rows.forEach(function (student) {
                        var row = document.createElement('button');
                        row.type = 'button';
                        row.className = 'olama-temp-search-result';
                        row.innerHTML = '<strong>' + escapeHtml(student.name) + '</strong><small>' + escapeHtml(student.uid) + ' · ' + escapeHtml(student.family_id) + '/' + escapeHtml(student.student_id) + '</small>';
                        row.addEventListener('click', function () { addStudent(student); });
                        results.appendChild(row);
                    });
                })
                .catch(function () { results.textContent = olamaUsersAdmin.noStudents; });
        }

        button.addEventListener('click', search);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                search();
            }
        });
        selected.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-remove-student]');
            if (remove) {
                remove.closest('[data-student-uid]').remove();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.olama-capability-form').forEach(initializeSelectAll);
        document.querySelectorAll('[data-temp-student-picker]').forEach(initializeStudentPicker);

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
