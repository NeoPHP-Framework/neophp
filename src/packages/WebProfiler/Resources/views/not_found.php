<div class="neo-panel">
    <h2>Profile not found</h2>
    <div class="neo-alert neo-alert-warning">No profile matches the token "<?= $this->e($token) ?>". It may have been purged (see max_profiles and lifetime).</div>
    <p><a href="<?= $this->e($profilerPath) ?>">Back to the last profiles</a></p>
</div>