<?php
use SimpleCommerce\Config;
use SimpleCommerce\Support\Session;
/** @var string $title */
$v = @filemtime(SC_ROOT . '/public/assets/app.css') . @filemtime(SC_ROOT . '/public/assets/app.js');
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#f4efe6">
<meta name="csrf" content="<?= e(session_status() === PHP_SESSION_ACTIVE ? Session::csrf() : '') ?>">
<title><?= e(($title ?? '') !== '' ? $title . ' — Simple Commerce' : 'Simple Commerce') ?></title>
<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/app.css?v=<?= e(substr(md5($v), 0, 8)) ?>">
<script src="/assets/app.js?v=<?= e(substr(md5($v), 0, 8)) ?>" defer></script>
</head>
<body>
<?php if (Config::demo()): ?>
<div class="demo-bar">Démonstration : rien n'est envoyé sur Internet. Les sites des clients sont des copies locales, remises à zéro en effaçant le dossier storage/.</div>
<?php endif ?>
