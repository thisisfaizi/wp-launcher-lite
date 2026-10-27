<section class="card narrow center">
  <h1><?= e($title) ?></h1>
  <p class="muted"><?= e($message) ?></p>
  <?php if (Wpl\Config::installed()): ?><a class="btn" href="<?= e(url('')) ?>">Back to the demos</a><?php endif; ?>
</section>
