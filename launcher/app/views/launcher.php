<?php
use Wpl\Settings;
use Wpl\View;

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
  <?php foreach ($blueprints as $bp): $c = $bp['config']; ?>
    <article class="card demo-card">
      <?php if ($c['image_url'] !== ''): ?>
        <img class="demo-img" src="<?= e($c['image_url']) ?>" alt="" loading="lazy">
      <?php else: ?>
        <div class="demo-img demo-img-blank" aria-hidden="true"><span><?= e(mb_strtoupper(mb_substr($bp['name'], 0, 1))) ?></span></div>
      <?php endif; ?>
      <div class="demo-body">
        <h2><?= e($bp['name']) ?></h2>
        <?php if ($c['description'] !== ''): ?><p class="muted"><?= e($c['description']) ?></p><?php endif; ?>
        <form method="post" action="<?= e(url('launch')) ?>" class="launch-form" data-launch>
          <?= View::csrfField() ?>
          <input type="hidden" name="blueprint" value="<?= e($bp['id']) ?>">
          <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
          <?php if ($capture !== 'off'): ?>
            <label class="field">
              <span class="sr-only">Email</span>
              <input type="email" name="email" placeholder="you@company.com<?= $capture === 'optional' ? ' (optional)' : '' ?>" autocomplete="email" <?= $capture === 'required' ? 'required' : '' ?>>
            </label>
            <?php if ($consent !== ''): ?><p class="consent"><?= e($consent) ?></p><?php endif; ?>
          <?php endif; ?>
          <button class="btn btn-primary btn-block" type="submit">
            <span class="when-idle">Launch demo</span>
            <span class="when-busy"><span class="spinner" aria-hidden="true"></span> Building your site…</span>
          </button>
          <p class="fine">Ready in seconds · lasts <?= e(human_duration(parse_duration((string) $c['demo']['default_expiration']))) ?></p>
        </form>
      </div>
    </article>
  <?php endforeach; ?>
</section>
<?php endif; ?>
