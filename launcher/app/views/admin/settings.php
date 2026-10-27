<?php
use Wpl\View;
$bool = function (string $key, string $label, string $help = '') use ($s) {
    return '<input type="hidden" name="' . e($key) . '" value="0"><label class="check"><input type="checkbox" name="' . e($key) . '" value="1" ' . ($s[$key] ? 'checked' : '') . '> <span>' . e($label) . ($help !== '' ? '<small>' . e($help) . '</small>' : '') . '</span></label>';
};
?>
<header class="page-head"><div><h1>Settings</h1></div></header>

<div class="stack form-wide">
<form method="post" class="card stack">
  <?= View::csrfField() ?><input type="hidden" name="section" value="launcher">
  <h2>Launcher page</h2>
  <div class="grid-2">
    <label class="field"><span>Brand name</span><input name="brand_name" value="<?= e($s['brand_name']) ?>"></label>
    <label class="field"><span>Accent colour</span><input name="accent" type="color" value="<?= e($s['accent']) ?>"></label>
  </div>
  <label class="field"><span>Headline</span><input name="launcher_title" value="<?= e($s['launcher_title']) ?>"></label>
  <label class="field"><span>Intro</span><textarea name="launcher_intro" rows="2"><?= e($s['launcher_intro']) ?></textarea></label>
  <label class="field"><span>Logo URL</span><input name="logo_url" type="url" value="<?= e($s['logo_url']) ?>" placeholder="https://…/logo.svg"></label>
  <label class="field"><span>Footer text</span><input name="footer_text" value="<?= e($s['footer_text']) ?>"></label>
  <label class="field"><span>Ask for an email before launching</span>
    <select name="email_capture">
      <?php foreach (['off' => 'No', 'optional' => 'Yes, optional', 'required' => 'Yes, required'] as $v => $l): ?><option value="<?= $v ?>" <?= $s['email_capture'] === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select></label>
  <label class="field"><span>Consent note under the email field</span><input name="consent_text" value="<?= e($s['consent_text']) ?>" placeholder="We'll send you one follow-up email. Unsubscribe anytime."></label>
  <div class="form-foot"><button class="btn btn-primary" type="submit">Save</button></div>
</form>

<form method="post" class="card stack">
  <?= View::csrfField() ?><input type="hidden" name="section" value="limits">
  <h2>Limits</h2>
  <p class="muted">Shared hosts suspend accounts that use too much. Keep these modest. 0 means no limit.</p>
  <div class="grid-3">
    <label class="field"><span>Demos running at once</span><input name="max_active_total" type="number" min="0" value="<?= (int) $s['max_active_total'] ?>"></label>
    <label class="field"><span>Launches per IP per hour</span><input name="max_per_ip_hour" type="number" min="0" value="<?= (int) $s['max_per_ip_hour'] ?>"></label>
    <label class="field"><span>Running demos per visitor</span><input name="max_active_per_visitor" type="number" min="0" value="<?= (int) $s['max_active_per_visitor'] ?>"></label>
    <label class="field"><span>Max upload size (MB)</span><input name="upload_limit_mb" type="number" min="0" value="<?= (int) $s['upload_limit_mb'] ?>"></label>
    <label class="field"><span>Media quota per demo (MB)</span><input name="disk_quota_mb" type="number" min="0" value="<?= (int) $s['disk_quota_mb'] ?>"></label>
  </div>
  <?= $bool('allow_demo_email', 'Let demo sites send email', 'Off by default: a demo admin could otherwise send mail to anyone from your hosting account.') ?>
  <?= $bool('trust_proxy', 'Behind Cloudflare or a proxy', 'Read the visitor IP from CF-Connecting-IP / X-Forwarded-For. Only turn on if you really are, or limits can be dodged.') ?>
  <?= $bool('wp_debug', 'WordPress debug log', 'Writes PHP notices to wpl-data/logs/. For troubleshooting only.') ?>
  <div class="form-foot"><button class="btn btn-primary" type="submit">Save</button></div>
</form>

<form method="post" class="card stack">
  <?= View::csrfField() ?><input type="hidden" name="section" value="webhook">
  <h2>Webhook</h2>
  <p class="muted">POSTs JSON to this URL when a demo is created, expires or is deleted — handy for sending leads to Zapier, Make or your CRM. Signed with HMAC-SHA256 in <code>X-WPL-Signature</code>.</p>
  <label class="field"><span>URL</span><input name="webhook_url" type="url" value="<?= e($s['webhook_url']) ?>" placeholder="https://hooks.zapier.com/…"></label>
  <label class="field"><span>Signing secret</span><input readonly value="<?= e($s['webhook_secret']) ?>" data-copy class="mono"></label>
  <div class="form-foot"><button class="btn btn-primary" type="submit">Save</button></div>
</form>

<section class="card stack" id="cron">
  <h2>Cleanup job</h2>
  <p class="muted">Deletes expired demos. Last run: <strong><?= $cronLast ? e(relative_time($cronLast)) : 'never' ?></strong>.
  Without it, cleanup still happens when someone opens the launcher — but a quiet site can then hold expired demos for hours.</p>
  <label class="field"><span>cPanel → Cron Jobs → every 5 minutes</span><input readonly class="mono" data-copy value="<?= e($cronCli) ?> >/dev/null 2>&1"></label>
  <label class="field"><span>Or, if your cron can only fetch a URL</span><input readonly class="mono" data-copy value="<?= e($cronUrl) ?>"></label>
</section>

<form method="post" class="card stack">
  <?= View::csrfField() ?><input type="hidden" name="section" value="password">
  <h2>Your login</h2>
  <div class="grid-3">
    <label class="field"><span>Email</span><input name="email" type="email" value="<?= e($s['admin_email']) ?>" autocomplete="username"></label>
    <label class="field"><span>Current password</span><input name="current" type="password" required autocomplete="current-password"></label>
    <label class="field"><span>New password</span><input name="new" type="password" minlength="10" required autocomplete="new-password"></label>
  </div>
  <div class="form-foot"><button class="btn" type="submit">Change login</button></div>
</form>
</div>
