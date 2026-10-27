<?php
/** Panel layout with sidebar navigation. */
use Wpl\Settings;

$nav = $nav ?? '';
$items = [
    'dashboard' => ['admin', 'Dashboard'],
    'blueprints' => ['admin/blueprints', 'Blueprints'],
    'sites' => ['admin/sites', 'Site history'],
    'leads' => ['admin/leads', 'Leads'],
    'log' => ['admin/log', 'Activity'],
    'settings' => ['admin/settings', 'Settings'],
    'system' => ['admin/system', 'System'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · Panel</title>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<style>:root{--accent:<?= e((string) Settings::get('accent')) ?>}</style>
<script defer src="<?= e(asset('app.js')) ?>"></script>
</head>
<body class="admin">
<div class="shell">
  <aside class="sidebar">
    <a class="brand" href="<?= e(url('admin')) ?>"><span class="brand-mark" aria-hidden="true"></span><span>Launcher</span></a>
    <nav>
      <?php foreach ($items as $key => [$path, $label]): ?>
        <a href="<?= e(url($path)) ?>" class="<?= $nav === $key ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <a href="<?= e(url('')) ?>" target="_blank" rel="noopener">View launcher ↗</a>
      <form method="post" action="<?= e(url('admin/logout')) ?>"><?= Wpl\View::csrfField() ?><button class="linkish" type="submit">Sign out</button></form>
    </div>
  </aside>
  <div class="main">
    <?php if (!empty($flash)): ?>
      <div class="flash flash-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <?= $content ?>
  </div>
</div>
</body>
</html>
