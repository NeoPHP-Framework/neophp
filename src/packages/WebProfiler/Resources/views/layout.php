<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $this->e($title) ?> - NeoPHP Profiler</title>
    <style><?= $this->asset('profiler.css') ?></style>
</head>
<body>
<header class="neo-header">
    <a class="neo-brand" href="<?= $this->e($profilerPath) ?>"><?= $this->icon('logo', 20) ?> NeoPHP Profiler</a>
    <nav>
        <a href="<?= $this->e($profilerPath) ?>">Last profiles</a>
        <a href="<?= $this->e($profilerPath . '/latest') ?>">Latest</a>
        <?php if ($profile !== null): ?>
            <a href="<?= $this->e($profilerPath . '/' . rawurlencode($profile->getToken()) . '.json') ?>">JSON</a>
        <?php endif; ?>
    </nav>
</header>
<main class="neo-main">
    <?= $content ?>
</main>
<script>
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