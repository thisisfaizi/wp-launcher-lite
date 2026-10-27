<?php
$c = $bp['config'] ?? [];
?>
<section class="ready card">
  <div class="ready-icon" aria-hidden="true">✓</div>
  <h1>Your demo is ready</h1>
  <p class="lede">A private copy of <strong><?= e($bp['name'] ?? 'the demo') ?></strong>, just for you.
    <?php if ($site['expires_at']): ?>It deletes itself <span data-countdown="<?= (int) $site['expires_at'] ?>"><?= e(relative_time((int) $site['expires_at'])) ?></span>.<?php endif; ?></p>

  <a class="btn btn-primary btn-lg" href="<?= e(url('go/' . $site['slug'])) ?>">Open the dashboard →</a>

  <dl class="creds">
    <div><dt>Site</dt><dd><a href="<?= e($url) ?>/" target="_blank" rel="noopener"><?= e($url) ?></a></dd></div>
    <div><dt>Login page</dt><dd><a href="<?= e($url) ?>/wp-admin/" target="_blank" rel="noopener"><?= e($url) ?>/wp-admin/</a></dd></div>
    <div><dt>Username</dt><dd><code data-copy><?= e($site['admin_user']) ?></code></dd></div>
    <div><dt>Password</dt><dd><code data-copy><?= e($site['admin_password']) ?></code></dd></div>
  </dl>
  <p class="fine">Bookmark this page to come back — it only works in this browser. <a href="<?= e(url('')) ?>">Back to all demos</a></p>
</section>
