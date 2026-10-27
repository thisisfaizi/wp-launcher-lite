<?php
/**
 * One product's demo page, linked from the operator's own website
 * (/demo/<blueprint>). With email capture off it submits itself, so a
 * "Try the demo" button lands the visitor in a fresh demo in one click.
 */
use Wpl\Settings;

$capture = Settings::get('email_capture');
$consent = (string) Settings::get('consent_text');
$next = 'go';
$autostart = $capture === 'off';
?>
<?php if (!empty($flash)): ?>
  <div class="flash flash-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
<?php endif; ?>

<section class="cards cards-single">
  <?php require __DIR__ . '/_demo_card.php'; ?>
</section>
