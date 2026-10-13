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
<?php if (!empty($indexable)): ?>
<meta name="robots" content="index, follow">
<meta name="description" content="<?= e($description ?? '') ?>">
<link rel="canonical" href="<?= e(\SimpleCommerce\Http\Req::origin() . '/') ?>">
<meta property="og:title" content="Simple Commerce">
<meta property="og:description" content="<?= e($description ?? '') ?>">
<meta property="og:image" content="<?= e(\SimpleCommerce\Http\Req::origin() . '/assets/logo-512.png') ?>">
<?php else: ?>
<meta name="robots" content="noindex, nofollow">
<?php endif ?>
<meta name="theme-color" content="#f4efe6">
<meta name="csrf" content="<?= e(session_status() === PHP_SESSION_ACTIVE ? Session::csrf() : '') ?>">
<title><?= e(($title ?? '') !== '' ? (!empty($indexable) ? 'Simple Commerce — ' . $title : $title . ' — Simple Commerce') : 'Simple Commerce') ?></title>
<link rel="icon" href="/assets/favicon-32.png" type="image/png" sizes="32x32">
<link rel="icon" href="/assets/logo-96.png" type="image/png" sizes="96x96">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="apple-mobile-web-app-title" content="Simple Commerce">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="stylesheet" href="/assets/app.css?v=<?= e(substr(md5($v), 0, 8)) ?>">
<script src="/assets/app.js?v=<?= e(substr(md5($v), 0, 8)) ?>" defer></script>
</head>
<body<?= \SimpleCommerce\Http\Req::inApp() ? ' class="in-app"' : '' ?>>
<?php if (Config::demo()): ?>
<div class="demo-bar">Démonstration : rien n'est envoyé sur Internet. Les sites des clients sont des copies locales, remises à zéro en effaçant le dossier storage/.</div>
<?php endif ?>
