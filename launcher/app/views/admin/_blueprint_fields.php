<?php
/** Blueprint form fields shared by "new" and "edit". Expects $c (config) and $isNew. */
use Wpl\Blueprints;

$blocked = (array) $c['restrictions']['blocked_capabilities'];
$always = ['install_plugins', 'install_themes', 'edit_plugins', 'edit_themes', 'update_core'];
?>
<fieldset class="card stack">
  <legend>Launcher card</legend>
  <label class="field"><span>Name</span><input name="name" required maxlength="190" value="<?= e($c['name']) ?>"></label>
  <label class="field"><span>Description</span><textarea name="description" rows="2" maxlength="500"><?= e($c['description']) ?></textarea></label>
  <label class="field"><span>Image URL <small class="muted">optional, shown on the card</small></span><input name="image_url" type="url" value="<?= e($c['image_url']) ?>" placeholder="https://…/screenshot.png"></label>
</fieldset>

<fieldset class="card stack">
  <legend>Demo</legend>
  <div class="grid-3">
    <label class="field"><span>Lasts</span>
      <select name="expiration">
        <?php foreach (['30m' => '30 minutes', '1h' => '1 hour', '2h' => '2 hours', '6h' => '6 hours', '1d' => '1 day', '3d' => '3 days', '7d' => '7 days', 'never' => 'Never (not recommended)'] as $v => $l): ?>
          <option value="<?= e($v) ?>" <?= $c['demo']['default_expiration'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>Max running at once</span><input name="max_concurrent" type="number" min="0" value="<?= (int) $c['demo']['max_concurrent_sites'] ?>"><small>0 = no limit</small></label>
    <?php $lockUser = !$isNew && !empty($bp['ready']); ?>
    <label class="field"><span>Admin username</span><input name="admin_user" pattern="[a-z0-9_.@-]+" value="<?= e($c['demo']['admin_user']) ?>" <?= $lockUser ? 'readonly' : '' ?>><?php if ($lockUser): ?><small>Set when the template was built. To change it, edit the user in the template.</small><?php endif; ?></label>
  </div>
  <label class="field"><span>Open visitors at <small class="muted">after one-click login</small></span><input name="landing_page" value="<?= e($c['demo']['landing_page']) ?>" placeholder="wp-admin/  (default) — or e.g. wp-admin/edit.php?post_type=product"></label>
</fieldset>

<?php if ($isNew): ?>
<fieldset class="card stack">
  <legend>Start with</legend>
  <label class="field"><span>Plugins from wordpress.org <small class="muted">one per line · add <code>:inactive</code> to leave one off · zip URLs work too</small></span>
    <textarea name="plugins" rows="4" placeholder="woocommerce&#10;contact-form-7&#10;https://example.com/my-plugin.zip"><?= e(Blueprints::pluginLines($c)) ?></textarea></label>
  <label class="field"><span>Theme <small class="muted">wordpress.org slug or zip URL, activated</small></span>
    <input name="theme" value="<?= e($c['themes']['install'][0]['slug'] ?? ($c['themes']['install'][0]['url'] ?? '')) ?>" placeholder="astra"></label>
  <p class="fine">Premium plugins, demo content, settings: add them afterwards in the template's own wp-admin — it is a normal WordPress site.</p>
</fieldset>
<?php endif; ?>

<fieldset class="card stack">
  <legend>What visitors can't do</legend>
  <div class="checkgrid">
    <?php foreach (Blueprints::TOGGLE_LABELS as $key => $label): $locked = in_array($key, $always, true); ?>
      <label class="check <?= $locked ? 'locked' : '' ?>">
        <input type="checkbox" name="blocked[]" value="<?= e($key) ?>" <?= $locked || in_array($key, $blocked, true) ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
        <?php if ($locked): ?><input type="hidden" name="blocked[]" value="<?= e($key) ?>"><?php endif; ?>
        <span><?= e($label) ?><?= $locked ? ' <small>always</small>' : '' ?></span>
      </label>
    <?php endforeach; ?>
  </div>
  <p class="fine">Installing or editing code is always blocked: on shared hosting every demo runs as your hosting account, so a visitor who could upload a plugin could reach everything else on it.</p>
  <label class="field"><span>Hide admin menus <small class="muted">menu slugs, comma separated</small></span><input name="hidden_menus" value="<?= e(implode(', ', (array) $c['restrictions']['hidden_menu_items'])) ?>" placeholder="tools.php, options-general.php"></label>
</fieldset>
