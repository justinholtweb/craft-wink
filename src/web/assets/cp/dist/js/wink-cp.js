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
})();
