<header class="page-head">
  <div><h1>Blueprints</h1><p class="muted">Each blueprint is a template site. Every demo starts as a copy of it.</p></div>
  <a class="btn btn-primary" href="<?= e(url('admin/blueprints/new')) ?>">New blueprint</a>
</header>
<section class="card flush">
<?php if (!$blueprints): ?>
  <div class="empty"><h2>No blueprints yet</h2><p class="muted">Create one to start offering demos.</p><a class="btn btn-primary" href="<?= e(url('admin/blueprints/new')) ?>">New blueprint</a></div>
<?php else: ?>
  <table class="table">
    <thead><tr><th>Name</th><th>Status</th><th>Running</th><th>Lasts</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($blueprints as $bp): ?>
      <tr>
        <td><a href="<?= e(url('admin/blueprints/' . $bp['id'])) ?>"><strong><?= e($bp['name']) ?></strong></a><br><small class="muted"><?= e($bp['id']) ?></small></td>
        <td><?php if (!$bp['ready']): ?><span class="badge badge-warn">Not built</span><?php elseif (!$bp['enabled']): ?><span class="badge">Hidden</span><?php else: ?><span class="badge badge-ok">Live</span><?php endif; ?></td>
        <td><?= (int) ($counts[$bp['id']] ?? 0) ?><?php $max = (int) $bp['config']['demo']['max_concurrent_sites']; if ($max): ?> <span class="muted">/ <?= $max ?></span><?php endif; ?></td>
        <td><?= e(human_duration(parse_duration((string) $bp['config']['demo']['default_expiration']))) ?></td>
        <td class="right"><a class="btn btn-sm" href="<?= e(url('admin/blueprints/' . $bp['id'])) ?>">Manage</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</section>
