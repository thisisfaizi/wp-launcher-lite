/* WP Launcher Lite — progressive enhancement only; every page works without it. */
(function () {
  'use strict';

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function post(url, data) {
    var body = data instanceof FormData ? data : new URLSearchParams(data);
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { return { error: 'Unexpected response (HTTP ' + r.status + ').' }; }).then(function (j) {
        if (!r.ok || j.error) throw new Error(j.error || ('HTTP ' + r.status));
        return j;
      });
    });
  }

  /* Run Core::step-style jobs until done, reporting progress. */
  function runSteps(url, extra, onProgress) {
    var state = {};
    function next() {
      var data = Object.assign({ state: JSON.stringify(state) }, extra || {});
      return post(url, data).then(function (r) {
        state = r;
        onProgress(r.progress || 0, r.message || '');
        return r.done ? r : next();
      });
    }
    return next();
  }

  function progress(wrap, pct, text) {
    wrap.hidden = false;
    $('.bar i', wrap).style.width = pct + '%';
    $('[data-status]', wrap).textContent = text;
  }

  /* ── Launch buttons ──────────────────────────────────────────────── */
  $$('[data-launch]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var btn = $('button[type=submit]', form);
      btn.classList.add('busy');
      btn.disabled = true;
    });
  });
  // A product's own demo page with nothing to fill in starts by itself.
  $$('[data-autostart]').forEach(function (form) {
    var btn = $('button[type=submit]', form);
    btn.classList.add('busy');
    btn.disabled = true;
    form.submit();
  });
  // Back/forward cache: re-enable buttons when the page is shown again.
  window.addEventListener('pageshow', function () {
    $$('[data-launch] button').forEach(function (b) { b.classList.remove('busy'); b.disabled = false; });
  });

  /* ── Two-click confirmation (no blocking dialogs) ────────────────── */
  $$('[data-confirm]').forEach(function (btn) {
    var label = btn.textContent;
    btn.addEventListener('click', function (ev) {
      if (btn.classList.contains('armed')) return;
      ev.preventDefault();
      btn.classList.add('armed');
      btn.textContent = btn.getAttribute('data-confirm') + '?';
      setTimeout(function () { btn.classList.remove('armed'); btn.textContent = label; }, 4000);
    });
  });

  /* ── Click to copy ───────────────────────────────────────────────── */
  $$('[data-copy]').forEach(function (el) {
    el.title = 'Click to copy';
    el.addEventListener('click', function () {
      var text = el.value !== undefined && el.tagName === 'INPUT' ? el.value : el.textContent;
      if (el.select) el.select();
      if (navigator.clipboard) navigator.clipboard.writeText(text).catch(function () {});
      el.classList.add('copied');
      setTimeout(function () { el.classList.remove('copied'); }, 900);
    });
  });

  /* ── Live countdowns ─────────────────────────────────────────────── */
  $$('[data-countdown]').forEach(function (el) {
    var end = parseInt(el.getAttribute('data-countdown'), 10) * 1000;
    function tick() {
      var left = Math.max(0, end - Date.now());
      var h = Math.floor(left / 3600000), m = Math.floor(left % 3600000 / 60000), s = Math.floor(left % 60000 / 1000);
      el.textContent = left ? 'in ' + (h ? h + 'h ' : '') + m + 'm ' + (h ? '' : s + 's') : 'now';
      if (left) setTimeout(tick, 1000);
    }
    tick();
  });

  /* ── Show fields depending on a radio choice ─────────────────────── */
  $$('[data-show-when]').forEach(function (el) {
    var parts = el.getAttribute('data-show-when').split('=');
    var form = el.closest('form');
    function sync() {
      var checked = $('input[name="' + parts[0] + '"]:checked', form);
      el.hidden = !checked || checked.value !== parts[1];
    }
    $$('input[name="' + parts[0] + '"]', form).forEach(function (r) { r.addEventListener('change', sync); });
    sync();
  });

  /* ── Installer ───────────────────────────────────────────────────── */
  var install = $('[data-install]');
  if (install) {
    install.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var err = $('[data-error]', install);
      var wrap = $('[data-progress]', install);
      var btn = $('button[type=submit]', install);
      var data = new FormData(install);
      var password = data.get('admin_password');
      err.hidden = true;
      btn.disabled = true;
      progress(wrap, 2, 'Saving settings…');

      var configured = install.getAttribute('data-configured') === '1'
        ? Promise.resolve()
        : post(install.getAttribute('data-configure'), data);

      configured
        .then(function () {
          install.setAttribute('data-configured', '1');
          return runSteps(install.getAttribute('data-step'), {}, function (pct, msg) { progress(wrap, pct, msg); });
        })
        .then(function () {
          progress(wrap, 96, 'Turning on routing…');
          return post(install.getAttribute('data-finish'), { admin_password: password });
        })
        .then(function (r) {
          progress(wrap, 98, 'Checking demo URLs reach WordPress…');
          return Promise.all([
            fetch(r.ping, { cache: 'no-store' }).then(function (x) { return x.text(); }).catch(function () { return ''; }),
            fetch(r.probe, { cache: 'no-store' }).then(function (x) { return x.status; }).catch(function () { return 0; })
          ]).then(function (res) {
            if (res[0].indexOf('WPL-ROUTE-OK') === -1) {
              throw new Error('Installed, but demo URLs do not reach WordPress: the server is not applying the .htaccess rules (mod_rewrite). See docs/troubleshooting.md, then open ' + r.next);
            }
            if (res[1] === 200) {
              throw new Error('Installed, but the private wpl-data folder is readable from the web. On nginx you must add the rules from docs/nginx.md before going live. Then open ' + r.next);
            }
            progress(wrap, 100, 'Done!');
            window.location.href = r.next;
          });
        })
        .catch(function (e) {
          err.textContent = e.message;
          err.hidden = false;
          btn.disabled = false;
          btn.textContent = 'Retry';
        });
    });
  }

  /* ── Core update ─────────────────────────────────────────────────── */
  var upd = $('[data-core-update]');
  if (upd) {
    $('button', upd).addEventListener('click', function () {
      var btn = this;
      var wrap = $('.progress-wrap', upd);
      btn.disabled = true;
      runSteps(upd.getAttribute('data-url'), { _csrf: upd.getAttribute('data-csrf') }, function (pct, msg) { progress(wrap, pct, msg); })
        .then(function () { setTimeout(function () { window.location.reload(); }, 800); })
        .catch(function (e) { progress(wrap, 0, 'Failed: ' + e.message); btn.disabled = false; });
    });
  }

  /* ── Self-test ───────────────────────────────────────────────────── */
  var st = $('[data-selftest]');
  if (st) {
    var set = function (name, ok, msg) {
      var li = $('[data-test="' + name + '"]', st);
      li.className = ok ? 'ok' : 'fail';
      $('small', li).textContent = msg;
    };
    fetch(st.getAttribute('data-ping'), { cache: 'no-store' }).then(function (r) { return r.text(); }).then(function (t) {
      var ok = t.indexOf('WPL-ROUTE-OK') !== -1;
      set('ping', ok, ok ? 'Working.' : 'Requests are not routed. Check that .htaccess / mod_rewrite is enabled.');
    }).catch(function () { set('ping', false, 'Could not reach the test URL.'); });
    fetch(st.getAttribute('data-probe'), { cache: 'no-store' }).then(function (r) {
      set('probe', r.status !== 200, r.status !== 200 ? 'Blocked (HTTP ' + r.status + ').' : 'READABLE FROM THE WEB — your config and databases are exposed. See docs/nginx.md.');
    }).catch(function () { set('probe', true, 'Blocked.'); });
  }
})();
