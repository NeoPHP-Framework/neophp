<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $this->e($title) ?> - NeoPHP Profiler</title>
    <script>(function () { try { var theme = window.localStorage.getItem('neo-wdt-theme'); if (theme === 'light' || theme === 'dark') { document.documentElement.setAttribute('data-neo-theme', theme); } } catch (e) { return; } })();</script>
    <style><?= $this->asset('profiler.css') ?></style>
</head>
<body>
<header class="neo-header">
    <a class="neo-brand" href="<?= $this->e($profilerPath) ?>"><?= $this->icon('logo', 20) ?> NeoPHP Profiler</a>
    <nav>
        <a href="<?= $this->e($this->siteUrl($profile)) ?>" title="Back to the profiled page">&larr; Back to the site</a>
        <a href="<?= $this->e($profilerPath) ?>">Last profiles</a>
        <a href="<?= $this->e($profilerPath . '/latest') ?>">Latest</a>
        <?php if ($profile !== null): ?>
            <a href="<?= $this->e($profilerPath . '/' . rawurlencode($profile->getToken()) . '.json') ?>">JSON</a>
        <?php endif; ?>
        <button type="button" class="neo-theme-switch" data-neo-theme-switch title="Theme: Auto / Light / Dark"><?= $this->icon('theme') ?> <span>Auto</span></button>
    </nav>
</header>
<main class="neo-main">
    <?= $content ?>
</main>
<script>
    (function () {
        var themes = ['auto', 'light', 'dark'];
        var labels = {auto: 'Auto', light: 'Light', dark: 'Dark'};
        var root = document.documentElement;
        function current() {
            try {
                var theme = window.localStorage.getItem('neo-wdt-theme');
                return themes.indexOf(theme) === -1 ? 'auto' : theme;
            } catch (e) {
                return 'auto';
            }
        }
        function apply(theme) {
            if (theme === 'auto') {
                root.removeAttribute('data-neo-theme');
            } else {
                root.setAttribute('data-neo-theme', theme);
            }
            document.querySelectorAll('[data-neo-theme-switch]').forEach(function (button) {
                button.title = 'Theme: ' + labels[theme] + ' (click to change)';
                var label = button.querySelector('span');
                if (label) {
                    label.textContent = labels[theme];
                }
            });
        }
        apply(current());
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-neo-theme-switch]');
            if (!button) {
                return;
            }
            var theme = themes[(themes.indexOf(current()) + 1) % themes.length];
            try {
                window.localStorage.setItem('neo-wdt-theme', theme);
            } catch (e) {
                theme = 'auto';
            }
            apply(theme);
        });
    })();
    document.addEventListener('click', function (event) {
        var tab = event.target.closest('.neo-tab');
        if (!tab) {
            return;
        }
        var group = tab.closest('.neo-tabs');
        group.querySelectorAll(':scope > .neo-tab-list > .neo-tab, :scope > .neo-tab-pane').forEach(function (node) {
            node.classList.remove('active');
        });
        tab.classList.add('active');
        var pane = document.getElementById(tab.getAttribute('data-tab'));
        if (pane) {
            pane.classList.add('active');
        }
    });
</script>
</body>
</html>