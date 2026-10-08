<div class="page-head"><h1><?= e($title) ?></h1></div>
<div class="notice notice-error"><p><?= e($message) ?></p></div>
<?php if (!empty($back)): ?><p><a href="<?= e($back[0]) ?>"><?= e($back[1]) ?></a></p><?php endif ?>
