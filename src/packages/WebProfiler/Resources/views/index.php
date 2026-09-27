<form class="neo-filters" method="get" action="<?= $this->e($profilerPath) ?>">
    <label>IP <input type="text" name="ip" value="<?= $this->e($filters['ip']) ?>" size="14"></label>
    <label>URL <input type="text" name="url" value="<?= $this->e($filters['url']) ?>" size="30"></label>
    <label>Method
        <select name="method">
            <option value="">Any</option>
            <?php foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'] as $method): ?>
                <option<?= $filters['method'] === $method ? ' selected' : '' ?>><?= $this->e($method) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Status <input type="text" name="status" value="<?= $this->e($filters['status']) ?>" size="6" placeholder="404, 5xx"></label>
    <label>Token <input type="text" name="token" value="<?= $this->e($filters['token']) ?>" size="14"></label>
    <label>Limit <input type="number" name="limit" value="<?= $this->e($limit) ?>" min="1" max="500"></label>
    <button class="neo-button" type="submit">Search</button>
    <a href="<?= $this->e($profilerPath) ?>">Reset</a>
</form>
<div class="neo-panel">
    <h2>Last profiles <span class="neo-badge"><?= count($profiles) ?></span></h2>
    <?php if ($profiles === []): ?>
        <p class="neo-empty">No profile found.</p>
    <?php else: ?>
        <div class="neo-table-wrap">
            <table class="neo-table">
                <thead><tr><th>Token</th><th>Date</th><th>IP</th><th>Method</th><th>Status</th><th>URL</th><th>Duration</th><th>Memory</th></tr></thead>
                <tbody>
                <?php foreach ($profiles as $entry): ?>
                    <tr>
                        <td><a href="<?= $this->e($profilerPath . '/' . rawurlencode((string) $entry['token'])) ?>"><?= $this->e($entry['token']) ?></a></td>
                        <td><?= $this->e(date('Y-m-d H:i:s', (int) ($entry['time'] ?? 0))) ?></td>
                        <td><?= $this->e($entry['ip'] ?? '') ?></td>
                        <td><?= $this->e($entry['method'] ?? '') ?></td>
                        <td class="neo-status neo-status-code-<?= intdiv((int) ($entry['status'] ?? 0), 100) ?>"><?= $this->e($entry['status'] ?? '') ?></td>
                        <td><?= $this->e($entry['url'] ?? '') ?></td>
                        <td><?= $this->e($this->duration((float) ($entry['duration'] ?? 0))) ?></td>
                        <td><?= $this->e($this->bytes((int) ($entry['memory'] ?? 0))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>