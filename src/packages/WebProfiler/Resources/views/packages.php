<div class="neo-panel">
    <h2>NeoPHP packages <span class="neo-badge"><?= count($packages) ?></span></h2>
    <p class="neo-muted">Environment: <strong><?= $this->e($environment) ?></strong>. Composer packages of type "neophp-package"; enable or disable them per environment in config/config.php.</p>
    <?php if ($packages === []): ?>
        <p class="neo-empty">No NeoPHP package is installed. Install one with: php bin/neo neophp:package:install vendor/name</p>
    <?php else: ?>
        <div class="neo-table-wrap">
            <table class="neo-table">
                <thead><tr><th>Package</th><th>Name</th><th>Version</th><th>Status</th><th>Environments</th><th>Modules</th><th>Provides</th></tr></thead>
                <tbody>
                <?php foreach ($packages as $package): ?>
                    <tr>
                        <td><strong><?= $this->e($package['name']) ?></strong><?php if ($package['description'] !== ''): ?><br><span class="neo-muted"><?= $this->e($package['description']) ?></span><?php endif; ?></td>
                        <td><?= $this->e($package['alias']) ?></td>
                        <td><?= $this->e($package['version']) ?></td>
                        <td>
                            <?php if ($package['active'] === null): ?>
                                <span class="neo-badge">No module</span>
                            <?php elseif ($package['active']): ?>
                                <span class="neo-badge neo-badge-success">Active</span>
                            <?php else: ?>
                                <span class="neo-badge neo-badge-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $this->e($package['environments']) ?></td>
                        <td><?= $this->e(implode(', ', $package['modules'])) ?></td>
                        <td>
                            <?php foreach ($package['features'] as $feature): ?>
                                <span class="neo-badge neo-badge-info"><?= $this->e($feature) ?></span>
                            <?php endforeach; ?>
                            <?php if (in_array('config', $package['features'], true) && !$package['published']): ?>
                                <span class="neo-badge neo-badge-warning">configuration not copied</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<div class="neo-panel">
    <h2>Modules <span class="neo-badge"><?= count($modules) ?></span></h2>
    <div class="neo-table-wrap">
        <table class="neo-table">
            <thead><tr><th>Module</th><th>Type</th><th>Source</th><th>Status</th><th>Environments</th></tr></thead>
            <tbody>
            <?php foreach ($modules as $module): ?>
                <tr>
                    <td title="<?= $this->e($module['class']) ?>"><?= $this->e($module['name']) ?></td>
                    <td><?= $this->e($module['type']) ?></td>
                    <td><?= $this->e($module['source']) ?></td>
                    <td><span class="neo-badge <?= $module['active'] ? 'neo-badge-success' : 'neo-badge-danger' ?>"><?= $module['active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td><?= $this->e($module['environments']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>