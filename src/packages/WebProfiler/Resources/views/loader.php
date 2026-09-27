<?php $id = 'neo-wdt-' . preg_replace('/[^a-zA-Z0-9]/', '', (string) $token); ?>
<div id="<?= $this->e($id) ?>" class="neo-wdt-container" data-token="<?= $this->e($token) ?>"></div>
<script>
    <?= $this->asset('toolbar.js') ?>(<?= $this->json(['id' => $id, 'token' => (string) $token, 'toolbarUrl' => (string) $toolbarUrl, 'toolbarPath' => dirname((string) $toolbarUrl) . '/', 'profilerPath' => (string) $profilerPath, 'ajaxLimit' => (int) $ajaxLimit]) ?>);
</script>