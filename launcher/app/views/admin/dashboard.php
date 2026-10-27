<header class="page-head">
  <div><h1>Dashboard</h1><p class="muted">Demos running right now.</p></div>
  <a class="btn btn-primary" href="<?= e(url('admin/blueprints/new')) ?>">New blueprint</a>
</header>

<div class="stats">
  <div class="stat"><span>Running</span><strong><?= $stats['active'] ?><?php if ($limit): ?><small> / <?= $limit ?></small><?php endif; ?></strong></div>
  <div class="stat"><span>Launched today</span><strong><?= $stats['today'] ?></strong></div>
  <div class="stat"><span>This week</span><strong><?= $stats['week'] ?></strong></div>
  <div class="stat"><span>Leads</span><strong><?= $stats['leads'] ?></strong></div>
</div>

<?php if (!$cronLast || $cronLast < time() - 900): ?>
  <div class="flash flash-warn">Cleanup last ran <?= $cronLast ? e(relative_time($cronLast)) : 'never' ?>. Expired demos are only deleted when someone visits the launcher, unless you add the cron job from <a href="<?= e(url('admin/settings')) ?>#cron">Settings</a>.</div>
<?php endif; ?>

<section class="card flush">
<?php if (!$sites): ?>
  <div class="empty"><h2>No demos running</h2><p class="muted">Share your <a href="<?= e(url('')) ?>" target="_blank" rel="noopener">launcher page</a> to get the first one.</p></div>
<?php else: ?>
  <table class="table">
    <thead><tr><th>Site</th><th>Blueprint</th><th>Visitor</th><th>Ends</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sites as $s): ?>
      <tr>
        <td><a href="<?= e(Wpl\Sites::url($s['slug'])) ?>/" target="_blank" rel="noopener"><code><?= e($s['slug']) ?></code></a></td>
        <td><?= e($blueprints[$s['blueprint_id']]['name'] ?? $s['blueprint_id']) ?></td>
        <td><?= $s['email'] ? e($s['email']) : '<span class="muted">' . e($s['ip']) . '</span>' ?></td>
        <td><?= $s['expires_at'] ? e(relative_time((int) $s['expires_at'])) : 'never' ?></td>
        <td class="right"><?php require __DIR__ . '/_site_actions.php'; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
