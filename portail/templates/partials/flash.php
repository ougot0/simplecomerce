<?php
/** @var array $flash */
foreach (array_merge($flash ?? [], \SimpleCommerce\Support\Session::takeFlash()) as $f): ?>
<div class="notice <?= $f['kind'] === 'error' ? 'notice-error' : ($f['kind'] === 'warn' ? 'notice-warn' : 'notice-ok') ?>" role="<?= $f['kind'] === 'error' ? 'alert' : 'status' ?>"><p><?= e($f['text']) ?></p></div>
<?php endforeach;
