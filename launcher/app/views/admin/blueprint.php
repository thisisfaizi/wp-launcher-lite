<?php $c = $bp['config']; $isNew = false; $form = 'bp-' . $bp['id']; ?>
<header class="page-head">
  <div>
    <p class="crumb"><a href="<?= e(url('admin/blueprints')) ?>">Blueprints</a></p>
    <h1><?= e($bp['name']) ?>
      <?php if (!$bp['ready']): ?><span class="badge badge-warn">Not built</span><?php elseif (!$bp['enabled']): ?><span class="badge">Hidden</span><?php else: ?><span class="badge badge-ok">Live</span><?php endif; ?></h1>
  </div>
</header>

<div class="split">
<div class="stack">
  <section class="card stack">
    <h2>Template site</h2>
    <?php if (!$bp['ready']): ?>
      <p><?= $building ? 'The build has not finished. It continues where it stopped.' : 'This template has not been built yet.' ?></p>
      <form method="post" action="<?= e(url('admin/blueprints/' . $bp['id'] . '/build')) ?>"><?= Wpl\View::csrfField() ?><button class="btn btn-primary" type="submit"><?= $building ? 'Continue building' : 'Build template' ?></button></form>
    <?php else: ?>
      <p class="muted">Set it up exactly how a visitor should find it: install premium plugins, import content, configure settings. New demos copy it; running ones are not affected.</p>
      <div class="row-actions">
        <form method="post" action="<?= e(url('admin/blueprints/' . $bp['id'] . '/open')) ?>" target="_blank"><?= Wpl\View::csrfField() ?><button class="btn btn-primary" type="submit">Edit template in wp-admin ↗</button></form>
        <a class="btn btn-ghost" href="<?= e($templateUrl) ?>/" target="_blank" rel="noopener">View site ↗</a>
      </div>
      <dl class="meta">
        <div><dt>Template media</dt><dd><?= number_format($usage['files']) ?> files · <?= round($usage['bytes'] / 1048576, 1) ?> MB <small class="muted">copied into every demo</small></dd></div>
        <div><dt>Database</dt><dd><?= round($dbSize / 1048576, 1) ?> MB</dd></div>
        <div><dt>Running demos</dt><dd><?= (int) $active ?></dd></div>
      </dl>
      <p class="fine">Keep the template private: don't share its URL. Visitors get copies, never the template itself.</p>
    <?php endif; ?>
  </section>

  <?php if ($bp['ready']): ?>
  <section class="card stack">
    <h2>Link for your website</h2>
    <p class="muted">Point a "Try the demo" button on your own site here. It builds a fresh demo and logs the visitor straight in (after asking for an email, if you collect them). Someone who already has one running goes back to it.</p>
    <label class="field"><span>Demo link</span><input readonly class="mono" data-copy value="<?= e(url('demo/' . $bp['id'])) ?>"></label>
    <?php if (!$bp['enabled']): ?><p class="fine">Turn on <strong>Show on the launcher page</strong> below first; hidden demos can't be launched.</p><?php endif; ?>
  </section>
  <?php endif; ?>

  <form method="post" class="stack" id="<?= e($form) ?>">
    <?= Wpl\View::csrfField() ?>
    <?php require __DIR__ . '/_blueprint_fields.php'; ?>
    <fieldset class="card">
      <legend>Visibility</legend>
      <input type="hidden" name="enabled" value="0">
      <label class="check"><input type="checkbox" name="enabled" value="1" <?= $bp['enabled'] ? 'checked' : '' ?>> <span>Show on the launcher page</span></label>
    </fieldset>
    <div class="form-foot"><button class="btn btn-primary" type="submit">Save changes</button></div>
  </form>
</div>

<aside class="stack">
  <section class="card stack">
    <h2>Export</h2>
    <p class="muted">The blueprint as WP Launcher JSON — settings and plugin list, not the template's content.</p>
    <a class="btn btn-ghost" href="<?= e(url('admin/blueprints/' . $bp['id'] . '/export')) ?>">Download JSON</a>
  </section>
  <section class="card stack danger-zone">
    <h2>Delete blueprint</h2>
    <p class="muted">Deletes the template, its plugins and themes, and <?= (int) $active ?> running demo<?= $active == 1 ? '' : 's' ?>.</p>
    <form method="post" action="<?= e(url('admin/blueprints/' . $bp['id'] . '/delete')) ?>"><?= Wpl\View::csrfField() ?><button class="btn btn-danger" type="submit" data-confirm="Delete for good">Delete blueprint</button></form>
  </section>
</aside>
</div>
