(function () {
    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    ready(function () {
        document.querySelectorAll('[data-official-menu-toggle]').forEach(function (button) {
            var targetId = button.getAttribute('aria-controls');
            var target = targetId ? document.getElementById(targetId) : null;
            if (!target) {
                return;
            }

            button.addEventListener('click', function () {
                var expanded = button.getAttribute('aria-expanded') === 'true';
                button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                target.hidden = expanded;
            });
        });

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    });
})();
