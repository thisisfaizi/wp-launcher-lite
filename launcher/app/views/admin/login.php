<section class="card narrow">
  <h1>Sign in</h1>
  <p class="muted">Launcher panel</p>
  <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <?= Wpl\View::csrfField() ?>
    <label class="field"><span>Email</span><input type="email" name="email" required autocomplete="username" autofocus></label>
    <label class="field"><span>Password</span><input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn btn-primary btn-block" type="submit">Sign in</button>
  </form>
</section>
