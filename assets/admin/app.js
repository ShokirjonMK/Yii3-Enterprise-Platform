/* Yii3 Enterprise Platform — progressive enhancement (works without JS; HTMX adds partial updates). */
(function () {
  'use strict';

  var root = document.documentElement;

  // ---- Theme (light / dark / system), persisted per browser
  function applyTheme(theme) {
    if (theme === 'light' || theme === 'dark') { root.setAttribute('data-theme', theme); }
    else { root.removeAttribute('data-theme'); }
  }
  try { applyTheme(localStorage.getItem('yep-theme')); } catch (e) { /* storage blocked */ }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-theme-toggle]');
    if (t) {
      var current = root.getAttribute('data-theme') ||
        (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
      var next = current === 'dark' ? 'light' : 'dark';
      applyTheme(next);
      try { localStorage.setItem('yep-theme', next); } catch (err) { /* ignore */ }
      return;
    }
    var nav = e.target.closest('[data-nav-toggle]');
    if (nav) { document.querySelector('.shell').classList.toggle('is-nav-open'); return; }

    var copy = e.target.closest('[data-copy]');
    if (copy && navigator.clipboard) {
      navigator.clipboard.writeText(copy.getAttribute('data-copy')).then(function () { toast('Copied', 'success'); });
      return;
    }
    // Close open <details> dropdowns when clicking outside
    document.querySelectorAll('details.dropdown[open]').forEach(function (d) { if (!d.contains(e.target)) { d.removeAttribute('open'); } });
  });

  // ---- Confirmation dialog for destructive actions (data-confirm on forms/buttons)
  var dialog = null;
  function confirmDialog(message, onOk) {
    if (!dialog) {
      dialog = document.createElement('dialog');
      dialog.innerHTML = '<div class="dialog__body"><h3 class="js-title"></h3><p class="js-msg"></p></div>' +
        '<div class="dialog__foot"><button type="button" class="btn js-cancel"></button><button type="button" class="btn btn--primary js-ok"></button></div>';
      document.body.appendChild(dialog);
    }
    var i18n = window.YEP_I18N || {};
    dialog.querySelector('.js-title').textContent = i18n.confirmTitle || 'Please confirm';
    dialog.querySelector('.js-msg').textContent = message;
    dialog.querySelector('.js-cancel').textContent = i18n.cancel || 'Cancel';
    dialog.querySelector('.js-ok').textContent = i18n.ok || 'Confirm';
    var ok = dialog.querySelector('.js-ok'), cancel = dialog.querySelector('.js-cancel');
    function close() { dialog.close(); ok.onclick = cancel.onclick = null; }
    ok.onclick = function () { close(); onOk(); };
    cancel.onclick = close;
    dialog.showModal();
    ok.focus();
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.hasAttribute('data-confirm') && !form.__confirmed) {
      e.preventDefault();
      confirmDialog(form.getAttribute('data-confirm'), function () {
        form.__confirmed = true;
        if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
        form.__confirmed = false;
      });
    }
  }, true);

  // HTMX built-in confirm → our dialog
  document.addEventListener('htmx:confirm', function (e) {
    if (!e.detail.question) { return; }
    e.preventDefault();
    confirmDialog(e.detail.question, function () { e.detail.issueRequest(true); });
  });

  // ---- Toasts
  function toast(message, type) {
    var box = document.querySelector('.toasts');
    if (!box) { box = document.createElement('div'); box.className = 'toasts'; box.setAttribute('role', 'status'); document.body.appendChild(box); }
    var el = document.createElement('div');
    el.className = 'toast toast--' + (type || 'info');
    el.textContent = message;
    box.appendChild(el);
    setTimeout(function () { el.remove(); }, 6000);
  }
  window.yepToast = toast;

  function promoteFlashes(scope) {
    (scope || document).querySelectorAll('[data-flash]').forEach(function (f) {
      toast(f.getAttribute('data-flash-message'), f.getAttribute('data-flash'));
      f.remove();
    });
  }

  // ---- List state memory: filters / sort / page of a list survive "back" style links.
  // Every visited page's query string is kept per path (sessionStorage, this tab only). Breadcrumb links and links
  // marked `data-back` (Cancel, "back to list") that point at a bare list URL get the last query of that list, so
  // filter → open record → Cancel / breadcrumb returns to the same filtered list. Visiting the bare URL itself
  // (sidebar, "Reset") clears the remembered state. Links without data-back / breadcrumbs are never touched.
  var LIST_KEY = 'yep:list-state', LIST_MAX = 60;
  function readLists() {
    try { return JSON.parse(sessionStorage.getItem(LIST_KEY) || '{}') || {}; } catch (e) { return {}; }
  }
  function syncLists() {
    var lists = readLists(), path = location.pathname;
    if (location.search) {
      delete lists[path]; // re-insert → newest last
      lists[path] = location.search;
      var keys = Object.keys(lists);
      while (keys.length > LIST_MAX) { delete lists[keys.shift()]; }
    } else {
      delete lists[path];
    }
    try { sessionStorage.setItem(LIST_KEY, JSON.stringify(lists)); } catch (e) { /* storage blocked */ }
    document.querySelectorAll('.breadcrumbs a[href], a[data-back][href]').forEach(function (a) {
      var u;
      try { u = new URL(a.getAttribute('href'), location.href); } catch (e) { return; }
      if (u.origin !== location.origin || u.search || u.hash || !lists[u.pathname] || u.pathname === path) { return; }
      a.setAttribute('href', u.pathname + lists[u.pathname]);
    });
  }
  document.addEventListener('htmx:pushedIntoHistory', syncLists);
  document.addEventListener('htmx:afterSettle', syncLists);
  window.addEventListener('pageshow', syncLists);

  // ---- HTMX integration
  document.addEventListener('htmx:afterSwap', function (e) {
    promoteFlashes(e.target);
    var shell = document.querySelector('.shell');
    if (shell) { shell.classList.remove('is-nav-open'); }
    // Keep sidebar active state in sync after partial navigation
    var path = location.pathname;
    document.querySelectorAll('.nav a').forEach(function (a) {
      var href = a.getAttribute('href');
      var active = href === path || (href !== '/admin' && path.indexOf(href + '/') === 0);
      if (active) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
    });
    var main = document.getElementById('main');
    if (main && e.target === main) { main.focus({ preventScroll: true }); window.scrollTo(0, 0); }
  });
  document.addEventListener('htmx:responseError', function (e) {
    var xhr = e.detail.xhr;
    var msg = (window.YEP_I18N && window.YEP_I18N.requestFailed) || 'Request failed';
    try { var j = JSON.parse(xhr.responseText); if (j.error && j.error.message) { msg = j.error.message; } } catch (err) { /* html */ }
    toast(msg + ' (' + xhr.status + ')', 'error');
  });
  document.addEventListener('htmx:sendError', function () {
    toast((window.YEP_I18N && window.YEP_I18N.networkError) || 'Network error — check your connection', 'error');
  });
  // Drop stale responses: a newer request on the same element supersedes older ones (filters/search)
  document.addEventListener('htmx:configRequest', function (e) {
    e.detail.headers['X-Requested-With'] = 'XMLHttpRequest';
  });

  document.addEventListener('DOMContentLoaded', function () {
    promoteFlashes(document);
    syncLists();
    var i18n = document.getElementById('yep-i18n');
    if (i18n) { try { window.YEP_I18N = JSON.parse(i18n.textContent); } catch (e) { /* ignore */ } }
  });
})();
