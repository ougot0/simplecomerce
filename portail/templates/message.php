<h2><?= e($title) ?></h2>
<p class="muted"><?= e($text) ?></p>
<?php if (!empty($link)): ?><p><a class="btn" href="<?= e($link[0]) ?>"><?= e($link[1]) ?></a></p><?php endif ?>
