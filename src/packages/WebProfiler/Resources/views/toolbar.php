<style><?= $this->asset('toolbar.css') ?></style>
<div class="neo-wdt" role="region" aria-label="NeoPHP debug toolbar" data-token="<?= $this->e($profile->getToken()) ?>">
    <div class="neo-wdt-items">
        <?php foreach ($items as $name => $item): ?>
        <?php $href = $item->getPanel() !== null ? $profilerPath . '/' . rawurlencode($profile->getToken()) . '?panel=' . rawurlencode($item->getPanel()) : null; ?>
        <div class="neo-wdt-item neo-wdt-<?= $this->e($item->getStatus()) ?>" data-name="<?= $this->e($name) ?>">
            <<?= $href !== null ? 'a href="' . $this->e($href) . '"' : 'span tabindex="0"' ?> class="neo-wdt-main" title="<?= $this->e($item->getLabel()) ?>">
            <?= $this->icon($item->getIcon()) ?>
            <?php if ($item->getValue() !== ''): ?><span class="neo-wdt-value"><?= $this->e($item->getValue()) ?></span><?php endif; ?>
            <span class="neo-wdt-label"><?= $this->e($item->getLabel()) ?></span>
        </<?= $href !== null ? 'a' : 'span' ?>>
        <?php if ($item->getDetails() !== []): ?>
            <div class="neo-wdt-pop">
                <table>
                    <?php foreach ($item->getDetails() as $label => $value): ?>
                        <tr><th><?= $this->e((string) $label) ?></th><td><?= $this->e($value) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <div class="neo-wdt-item neo-wdt-default" data-neo-wdt-ajax hidden>
        <span class="neo-wdt-main" tabindex="0"><?= $this->icon('ajax') ?><span class="neo-wdt-value">0</span><span class="neo-wdt-label">Ajax</span></span>
        <div class="neo-wdt-pop">
            <table>
                <thead><tr><th>Method</th><th>Status</th><th>URL</th><th>Time</th><th>Profile</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
<div class="neo-wdt-actions">
    <a href="<?= $this->e($profilerPath . '/' . rawurlencode($profile->getToken())) ?>" title="Open the profiler"><?= $this->icon('list') ?></a>
    <button type="button" data-neo-theme-switch title="Theme: Auto"><?= $this->icon('theme') ?></button>
    <button type="button" data-neo-wdt-hide title="Hide the toolbar"><?= $this->icon('close') ?></button>
</div>
</div>
<button type="button" class="neo-wdt-mini" title="Show the NeoPHP toolbar" hidden><?= $this->icon('logo') ?></button>
<?php foreach (($assets['css'] ?? []) as $name => $css): ?>
    <style data-neo-wdt-asset="<?= $this->e($name) ?>"><?= str_ireplace('</style', '<\\/style', (string) $css) ?></style>
<?php endforeach; ?>
<?php foreach (($assets['js'] ?? []) as $name => $js): ?>
    <script type="text/plain" data-neo-wdt-script="<?= $this->e($name) ?>"><?= str_ireplace('</script', '<\\/script', (string) $js) ?></script>
<?php endforeach; ?>