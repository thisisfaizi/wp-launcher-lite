<?php
/** Public layout: launcher, ready page, installer, sign-in, errors. */
use Wpl\Config;
use Wpl\Settings;

$installed = Config::installed();
$brand = $installed ? (string) Settings::get('brand_name') : 'WP Launcher Lite';
$accent = $installed ? (string) Settings::get('accent') : '#2271b1';
$logo = $installed ? (string) Settings::get('logo_url') : '';
$footer = $installed ? (string) Settings::get('footer_text') : '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? '') !== '' ? $title . ' · ' . $brand : $brand) ?></title>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<style>:root{--accent:<?= e($accent) ?>}</style>
<script defer src="<?= e(asset('app.js')) ?>"></script>
</head>
<body class="public">
<header class="topbar">
  <a class="brand" href="<?= e($installed ? url('') : '#') ?>">
    <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="" class="brand-logo"><?php else: ?><svg class="brand-mark" viewBox="0 0 26 26" aria-hidden="true"><rect width="26" height="26" rx="5" fill="currentColor"/><rect class="bm-line" x="10.5" y="6.5" width="9" height="9" rx="1.5" fill="none" stroke-width="1.6"/><rect class="bm-fg" x="6.5" y="10.5" width="9" height="9" rx="1.5"/></svg><?php endif; ?>
    <span><?= e($brand) ?></span>
  </a>
</header>
<main class="page">
<?= $content ?>
</main>
<footer class="footer">
  <?php if ($footer !== ''): ?><p><?= e($footer) ?></p><?php endif; ?>
</footer>
</body>
</html>
