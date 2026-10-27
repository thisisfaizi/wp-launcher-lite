<?php
$sqlite = extension_loaded('pdo_sqlite');
$mysql = extension_loaded('pdo_mysql') && extension_loaded('mysqli');
?>
<section class="hero hero-sm">
  <h1>Install WP Launcher Lite</h1>
  <p class="lede">Temporary WordPress demo sites on ordinary shared hosting. About two minutes.</p>
</section>

<div class="install-grid">
<section class="card">
  <h2>1. Server check</h2>
  <ul class="checks">
    <?php foreach ($checks as $c): ?>
      <li class="<?= $c['ok'] ? 'ok' : ($c['fatal'] ? 'fail' : 'warn') ?>">
        <span class="dot" aria-hidden="true"></span>
        <div><strong><?= e($c['label']) ?></strong><small><?= e($c['detail']) ?></small></div>
      </li>
    <?php endforeach; ?>
  </ul>
</section>

<section class="card">
  <?php if (!$ok): ?>
    <h2>2. Fix the items in red first</h2>
    <p class="muted">Then reload this page.</p>
  <?php else: ?>
  <form id="install-form" class="stack" data-install
        data-configure="<?= e(url('install/configure')) ?>"
        data-step="<?= e(url('install/step')) ?>"
        data-finish="<?= e(url('install/finish')) ?>"
        data-configured="<?= $configured ? '1' : '0' ?>">
    <h2>2. Settings</h2>

    <label class="field"><span>Launcher URL</span>
      <input name="base_url" type="url" required value="<?= e($detected) ?>">
      <small>Where this folder is reachable. Demos appear under it.</small>
    </label>

    <fieldset class="field">
      <legend>Demo addresses</legend>
      <label class="choice"><input type="radio" name="url_mode" value="path" checked> <span><strong>In a folder</strong> — <code><?= e(parse_url($detected, PHP_URL_HOST)) ?>…/s/k7f3m9q2xa/</code>. Works on every host.</span></label>
      <label class="choice"><input type="radio" name="url_mode" value="subdomain"> <span><strong>On subdomains</strong> — <code>k7f3m9q2xa.demo.example.com</code>. Needs a wildcard subdomain pointing at this folder. Better isolation.</span></label>
      <label class="field" data-show-when="url_mode=subdomain" hidden><span>Base domain</span><input name="base_domain" placeholder="demo.example.com"></label>
    </fieldset>

    <fieldset class="field">
      <legend>Database</legend>
      <label class="choice"><input type="radio" name="engine" value="mysql" <?= $mysql ? 'checked' : 'disabled' ?>> <span><strong>MySQL</strong> — best plugin compatibility. Create one database in your hosting panel; every demo gets its own tables in it.</span></label>
      <label class="choice"><input type="radio" name="engine" value="sqlite" <?= !$mysql && $sqlite ? 'checked' : '' ?> <?= $sqlite ? '' : 'disabled' ?>> <span><strong>SQLite</strong> — nothing to set up; each demo is one file. Most plugins work, a few don't.</span></label>
      <div class="grid-2" data-show-when="engine=mysql" <?= $mysql ? '' : 'hidden' ?>>
        <label class="field"><span>Database name</span><input name="mysql[name]" placeholder="cpuser_demos"></label>
        <label class="field"><span>Username</span><input name="mysql[user]" placeholder="cpuser_demos" autocomplete="off"></label>
        <label class="field"><span>Password</span><input name="mysql[pass]" type="password" autocomplete="new-password"></label>
        <label class="field"><span>Host</span><input name="mysql[host]" value="localhost"></label>
      </div>
    </fieldset>

    <fieldset class="field">
      <legend>Your admin login</legend>
      <div class="grid-2">
        <label class="field"><span>Email</span><input name="admin_email" type="email" required autocomplete="username"></label>
        <label class="field"><span>Password</span><input name="admin_password" type="password" minlength="10" required autocomplete="new-password"><small>10+ characters.</small></label>
      </div>
    </fieldset>

    <div class="flash flash-error" data-error hidden role="alert"></div>
    <button class="btn btn-primary btn-block" type="submit">Install</button>

    <div class="progress-wrap" data-progress hidden>
      <div class="bar"><i style="width:0"></i></div>
      <p class="muted" data-status>Starting…</p>
    </div>
  </form>
  <?php endif; ?>
</section>
</div>
