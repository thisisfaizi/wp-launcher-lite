<?php
/**
 * A blueprint's card with its launch form. Expects $bp, $capture, $consent;
 * optional $next ('go': straight into the demo) and $autostart (submit on load).
 */
use Wpl\View;

$c = $bp['config'];
?>
<article class="card demo-card">
  <?php if ($c['image_url'] !== ''): ?>
    <img class="demo-img" src="<?= e($c['image_url']) ?>" alt="" loading="lazy">
  <?php else: ?>
    <div class="demo-img demo-img-blank" aria-hidden="true"><span><?= e(mb_strtoupper(mb_substr($bp['name'], 0, 1))) ?></span></div>
  <?php endif; ?>
  <div class="demo-body">
    <h2><?= e($bp['name']) ?></h2>
    <?php if ($c['description'] !== ''): ?><p class="muted"><?= e($c['description']) ?></p><?php endif; ?>
    <form method="post" action="<?= e(url('launch')) ?>" class="launch-form" data-launch<?= !empty($autostart) ? ' data-autostart' : '' ?>>
      <?= View::csrfField() ?>
      <input type="hidden" name="blueprint" value="<?= e($bp['id']) ?>">
      <?php if (!empty($next)): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
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
