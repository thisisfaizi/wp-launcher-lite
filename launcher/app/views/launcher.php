<?php
use Wpl\Settings;

$capture = Settings::get('email_capture');
$consent = (string) Settings::get('consent_text');
?>
<section class="hero">
  <h1><?= e(Settings::get('launcher_title')) ?></h1>
  <p class="lede"><?= e(Settings::get('launcher_intro')) ?></p>
</section>

<?php if (!empty($flash)): ?>
  <div class="flash flash-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
<?php endif; ?>

<?php if ($mine): ?>
<section class="mine">
  <h2>Your demos</h2>
  <ul class="mine-list">
    <?php foreach ($mine as $s): ?>
      <li>
        <div>
          <strong><?= e(($bpNames[$s['blueprint_id']] ?? null) ?: $s['blueprint_id']) ?></strong>
          <span class="muted">· <?= $s['expires_at'] ? 'ends ' . e(relative_time((int) $s['expires_at'])) : 'no expiry' ?></span>
        </div>
        <span class="row-actions">
          <a class="btn btn-sm" href="<?= e(url('go/' . $s['slug'])) ?>">Open dashboard</a>
          <a class="btn btn-sm btn-ghost" href="<?= e(url('ready/' . $s['slug'])) ?>">Details</a>
        </span>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if (!$blueprints): ?>
  <div class="empty">
    <h2>No demos yet</h2>
    <p>Check back soon.</p>
  </div>
<?php else: ?>
<section class="cards">
  <?php foreach ($blueprints as $bp) {
      require __DIR__ . '/_demo_card.php';
  } ?>
</section>
<?php endif; ?>
