<?php $c = Wpl\Blueprints::defaults(); $c['name'] = ''; $isNew = true; ?>
<header class="page-head">
  <div><h1>New blueprint</h1>
  <p class="muted"><?= $welcome ? 'WordPress is installed. Now set up your first demo.' : 'A blueprint is a template WordPress site that demos are copied from.' ?></p></div>
</header>
<form method="post" class="stack form-wide">
  <?= Wpl\View::csrfField() ?>
  <?php require __DIR__ . '/_blueprint_fields.php'; ?>
  <details class="card">
    <summary>Import a WP Launcher blueprint JSON instead</summary>
    <label class="field"><span>Blueprint JSON</span><textarea name="import_json" rows="8" class="mono" placeholder='{"name": "My demo", "plugins": {"preinstall": [...]}}'></textarea>
    <small>Files from the Docker version's <code>blueprints/</code> folder work as-is. When filled in, the fields above are ignored.</small></label>
  </details>
  <div class="form-foot">
    <p class="fine">Next, your browser builds the template — a minute or two with plugins. Keep the tab open.</p>
    <button class="btn btn-primary" type="submit">Create and build</button>
  </div>
</form>
