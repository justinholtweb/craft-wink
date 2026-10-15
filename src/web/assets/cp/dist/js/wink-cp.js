/**
 * Wink CP JavaScript
 */
(function() {
    'use strict';

    // Auto-generate handle from title
    var titleInput = document.getElementById('title');
    var handleInput = document.getElementById('handle');

    if (titleInput && handleInput && !handleInput.value) {
        titleInput.addEventListener('input', function() {
            if (!handleInput.dataset.changed) {
                handleInput.value = this.value
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-|-$/g, '');
            }
        });

        handleInput.addEventListener('input', function() {
            this.dataset.changed = '1';
        });
    }

    // Variant and goal rows: add from the row <template>, remove down to the minimum. New rows are
    // keyed n0, n1… so their names never collide with the rendered v0/g0 rows.
    var added = 0;

    function rows(container) {
        return container.querySelectorAll('[data-wink-row]');
    }

    function refresh(container) {
        var list = rows(container);
        var min = parseInt(container.dataset.winkMin || '0', 10);
        list.forEach(function(row) {
            row.querySelector('[data-wink-remove]').disabled = list.length <= min;
        });
        // A removed control or primary row leaves no radio checked; the first row takes it.
        if (list.length && !container.querySelector('input[type=radio]:checked')) {
            var radio = list[0].querySelector('input[type=radio]');
            if (radio) {
                radio.checked = true;
            }
        }
        updateWeightTotal();
    }

    function updateWeightTotal() {
        var container = document.querySelector('[data-wink-rows=variant]');
        var output = document.querySelector('[data-wink-weight-total]');
        if (!container || !output) {
            return;
        }
        var total = 0;
        container.querySelectorAll('input[name$="[weight]"]').forEach(function(input) {
            total += parseInt(input.value, 10) || 0;
        });
        output.textContent = output.dataset.label.replace('{total}', total);
        output.classList.toggle('error', total !== 100);
        output.classList.toggle('light', total === 100);
    }

    function updatePlaceholder(select) {
        var container = select.closest('[data-wink-placeholders]');
        var row = select.closest('[data-wink-row]');
        var target = row && row.querySelector('input[name$="[goalTarget]"]');
        if (container && target) {
            var placeholders = JSON.parse(container.dataset.winkPlaceholders);
            target.placeholder = placeholders[select.value] || '';
        }
    }

    document.querySelectorAll('[data-wink-rows]').forEach(function(container) {
        var type = container.dataset.winkRows;
        var template = document.getElementById('wink-' + type + '-template');
        var addButton = document.querySelector('[data-wink-add="' + type + '"]');

        if (addButton && template) {
            addButton.addEventListener('click', function() {
                var key = 'n' + (added++);
                var html = template.innerHTML.replace(/__ROW__/g, key);
                container.insertAdjacentHTML('beforeend', html);
                refresh(container);
                var first = container.lastElementChild.querySelector('input[type=text]');
                if (first) {
                    first.focus();
                }
            });
        }

        container.addEventListener('click', function(e) {
            var button = e.target.closest('[data-wink-remove]');
            if (!button) {
                return;
            }
            var row = button.closest('[data-wink-row]');
            if (row.dataset.saved === '1' && !confirm(container.dataset.winkConfirmRemove)) {
                return;
            }
            row.remove();
            refresh(container);
        });

        container.addEventListener('input', function(e) {
            if (e.target.matches('input[name$="[weight]"]')) {
                updateWeightTotal();
            }
        });

        container.addEventListener('change', function(e) {
            if (e.target.matches('select[name$="[goalType]"]')) {
                updatePlaceholder(e.target);
            }
        });

        refresh(container);
    });
})();
