<?php /** Open / extend / delete buttons for one running demo. Expects $s. */ ?>
<span class="row-actions">
  <form method="post" action="<?= e(url('admin/sites/' . $s['slug'] . '/open')) ?>" target="_blank"><?= Wpl\View::csrfField() ?><button class="btn btn-sm" type="submit">Open</button></form>
  <form method="post" action="<?= e(url('admin/sites/' . $s['slug'] . '/extend')) ?>" class="inline-select"><?= Wpl\View::csrfField() ?>
    <select name="duration" aria-label="Extend by"><option value="1h">+1 hour</option><option value="1d">+1 day</option><option value="7d">+7 days</option><option value="never">Never expire</option></select>
    <button class="btn btn-sm btn-ghost" type="submit">Extend</button>
  </form>
  <form method="post" action="<?= e(url('admin/sites/' . $s['slug'] . '/delete')) ?>"><?= Wpl\View::csrfField() ?><button class="btn btn-sm btn-danger" type="submit" data-confirm="Delete">Delete</button></form>
</span>
