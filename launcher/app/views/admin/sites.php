<header class="page-head"><div><h1>Site history</h1><p class="muted">The last 500 demos.</p></div></header>
<section class="card flush">
<?php if (!$rows): ?>
  <div class="empty"><h2>Nothing launched yet</h2></div>
<?php else: ?>
  <table class="table">
    <thead><tr><th>Site</th><th>Blueprint</th><th>Visitor</th><th>Launched</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td><code><?= e($s['slug']) ?></code></td>
        <td><?= e($s['blueprint_id']) ?></td>
        <td><?= e($s['email'] ?: $s['ip']) ?></td>
        <td><?= e(fmt_time((int) $s['created_at'])) ?></td>
        <td><?php if ($s['status'] === 'active'): ?><span class="badge badge-ok">running</span> <small class="muted"><?= $s['expires_at'] ? 'ends ' . e(relative_time((int) $s['expires_at'])) : '' ?></small><?php else: ?><span class="badge">ended</span><?php endif; ?></td>
        <td class="right"><?php if ($s['status'] === 'active') { require __DIR__ . '/_site_actions.php'; } ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
