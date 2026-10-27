<header class="page-head">
  <div><h1>Leads</h1><p class="muted">Email addresses visitors gave when launching a demo.</p></div>
  <?php if ($rows): ?><a class="btn" href="<?= e(url('admin/leads.csv')) ?>">Download CSV</a><?php endif; ?>
</header>
<?php if ($capture === 'off'): ?>
  <div class="flash flash-warn">Email capture is off. Turn it on under <a href="<?= e(url('admin/settings')) ?>">Settings → Launcher page</a>.</div>
<?php endif; ?>
<section class="card flush">
<?php if (!$rows): ?>
  <div class="empty"><h2>No leads yet</h2></div>
<?php else: ?>
  <table class="table">
    <thead><tr><th>Email</th><th>Blueprint</th><th>Launched</th><th>Demo</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a></td><td><?= e($r['blueprint_id']) ?></td><td><?= e(fmt_time((int) $r['created_at'])) ?></td><td><span class="badge <?= $r['status'] === 'active' ? 'badge-ok' : '' ?>"><?= e($r['status'] === 'active' ? 'running' : 'ended') ?></span></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
