<header class="page-head"><div><h1>Activity</h1><p class="muted">Launches, expiries, sign-ins and errors. Kept for 90 days.</p></div></header>
<section class="card flush">
  <table class="table table-dense">
    <thead><tr><th>When</th><th>Event</th><th>Details</th><th>Site</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($events as $ev): $t = $ev['type']; $cls = str_contains($t, 'fail') || $t === 'error' ? 'badge-bad' : (str_starts_with($t, 'site.created') ? 'badge-ok' : ''); ?>
      <tr><td class="nowrap"><?= e(fmt_time((int) $ev['created_at'])) ?></td><td><span class="badge <?= $cls ?>"><?= e($t) ?></span></td><td><?= e($ev['message']) ?></td><td><code><?= e($ev['slug']) ?></code></td><td class="muted"><?= e($ev['ip']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
