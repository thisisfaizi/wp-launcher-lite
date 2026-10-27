<?php $mb = fn ($u) => number_format($u['files']) . ' files · ' . round($u['bytes'] / 1048576, 1) . ' MB'; ?>
<header class="page-head"><div><h1>System</h1></div></header>
<div class="stack form-wide">
<section class="card stack">
  <h2>WordPress</h2>
  <p>All demos share one WordPress core: <strong><?= e($core ?? 'not installed') ?></strong><?php if ($latest && $core && version_compare($latest, $core, '>')): ?> — <strong><?= e($latest) ?></strong> is available.<?php elseif ($latest): ?> — up to date.<?php endif; ?></p>
  <?php if ($latest && $core && version_compare($latest, $core, '>')): ?>
    <div data-core-update data-url="<?= e(url('admin/system/core-step')) ?>" data-csrf="<?= e(Wpl\Auth::csrf()) ?>">
      <button class="btn btn-primary" type="button">Update to <?= e($latest) ?></button>
      <div class="progress-wrap" hidden><div class="bar"><i style="width:0"></i></div><p class="muted" data-status></p></div>
    </div>
    <p class="fine">Running demos switch to the new version at once. Open each template afterwards: WordPress may ask to update its database.</p>
  <?php endif; ?>
</section>

<section class="card stack">
  <h2>Self-test</h2>
  <ul class="checks" data-selftest data-ping="<?= e($pingUrl) ?>" data-probe="<?= e($probeUrl) ?>">
    <li class="pending" data-test="ping"><span class="dot"></span><div><strong>Demo URLs reach WordPress</strong><small>Checking…</small></div></li>
    <li class="pending" data-test="probe"><span class="dot"></span><div><strong>Private data folder is blocked</strong><small>Checking…</small></div></li>
  </ul>
  <form method="post"><?= Wpl\View::csrfField() ?><input type="hidden" name="do" value="htaccess"><button class="btn btn-ghost btn-sm" type="submit">Rewrite routing rules (.htaccess)</button></form>
</section>

<section class="card stack">
  <h2>Install</h2>
  <dl class="meta">
    <div><dt>Database</dt><dd><?= e($engine === 'mysql' ? 'MySQL' : 'SQLite') ?></dd></div>
    <div><dt>Demo URLs</dt><dd><?= e($mode === 'subdomain' ? 'Subdomains' : 'Folder (/s/…)') ?></dd></div>
    <div><dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd></div>
    <div><dt>Launcher</dt><dd><?= e(WPL_VERSION) ?></dd></div>
  </dl>
  <h3>Disk</h3>
  <dl class="meta">
    <div><dt>WordPress core</dt><dd><?= e($mb($disk['core'])) ?></dd></div>
    <div><dt>Plugins &amp; themes</dt><dd><?= e($mb($disk['content'])) ?></dd></div>
    <div><dt>Media</dt><dd><?= e($mb($disk['uploads'])) ?></dd></div>
    <div><dt>Data</dt><dd><?= e($mb($disk['data'])) ?></dd></div>
  </dl>
  <p class="fine">Many hosts cap the number of files (inodes). Plugins and themes count once per blueprint; each demo adds only its database and a copy of the template's media.</p>
</section>

<section class="card stack">
  <h2>Server</h2>
  <ul class="checks">
    <?php foreach ($checks as $c): ?>
      <li class="<?= $c['ok'] ? 'ok' : ($c['fatal'] ? 'fail' : 'warn') ?>"><span class="dot"></span><div><strong><?= e($c['label']) ?></strong><small><?= e($c['detail']) ?></small></div></li>
    <?php endforeach; ?>
  </ul>
</section>
</div>
