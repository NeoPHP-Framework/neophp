<?php $base = $profilerPath . '/' . rawurlencode($profile->getToken()); ?>
<section class="neo-summary">
    <h1><span class="neo-status neo-status-code-<?= intdiv($profile->getStatus(), 100) ?>"><?= $this->e($profile->getStatus()) ?></span> <?= $this->e($profile->getMethod()) ?> <?= $this->e($profile->getUrl()) ?></h1>
    <dl>
        <div><dt>Route</dt><dd><?= $this->e($profile->getRoute() ?? 'n/a') ?></dd></div>
        <div><dt>Date</dt><dd><?= $this->e(date('Y-m-d H:i:s', $profile->getTime())) ?></dd></div>
        <div><dt>Duration</dt><dd><?= $this->e($this->duration($profile->getDuration())) ?></dd></div>
        <div><dt>Memory</dt><dd><?= $this->e($this->bytes($profile->getMemory())) ?></dd></div>
        <div><dt>IP</dt><dd><?= $this->e($profile->getIp()) ?></dd></div>
        <div><dt>Token</dt><dd><?= $this->e($profile->getToken()) ?></dd></div>
    </dl>
</section>
<div class="neo-layout">
    <nav class="neo-menu" aria-label="Panels">
        <?php foreach ($panels as $name => $panel): ?>
            <a href="<?= $this->e($base . '?panel=' . rawurlencode((string) $name)) ?>" class="<?= $name === $current ? 'active' : '' ?>">
                <?= $this->icon($panel->getIcon()) ?> <?= $this->e($panel->getTitle()) ?>
                <?php if ($panel->getBadge() !== null && $panel->getBadge() !== ''): ?>
                    <span class="neo-badge neo-badge-<?= $this->e($panel->getBadgeStatus()) ?>"><?= $this->e($panel->getBadge()) ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <section class="neo-panel">
        <?php if (isset($panels[$current])): ?>
            <h2><?= $this->icon($panels[$current]->getIcon(), 22) ?> <?= $this->e($panels[$current]->getTitle()) ?></h2>
            <?= $this->getBlockRenderer()->renderPanel($panels[$current]) ?>
        <?php else: ?>
            <p class="neo-empty">No panel available for this profile.</p>
        <?php endif; ?>
    </section>
</div>