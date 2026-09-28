/* FarosCMS Admin — vanilla JS interactions.
 * No framework. All behaviors are delegated and data-attribute driven
 * so partials can be composed freely in PHP views.
 *
 *   data-toggle="sidebar"            -> show/hide mobile sidebar
 *   data-dropdown                    -> wrapper; [data-dropdown-trigger] + [data-dropdown-menu]
 *   data-tabs                        -> wrapper; [data-tab="key"] buttons + [data-tab-panel="key"]
 *   data-toggle="filter" data-target -> show/hide a filter panel by id
 *   data-offcanvas-open="id"         -> open off-canvas panel #id
 *   data-offcanvas-close             -> close nearest open off-canvas
 *   data-modal-open="id"             -> open modal #id
 *   data-modal-close                 -> close nearest modal
 *   data-toast-dismiss               -> remove nearest [data-toast]
 *   window.adminToast(msg, type)     -> spawn a toast programmatically
 */
(function () {
  'use strict';

  function closest(el, sel) { return el && el.closest ? el.closest(sel) : null; }

  /* ---- Theme (light / dark / system) ---- */
  var SUN = '<svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 3v2m0 14v2m9-9h-2M5 12H3m15.5-6.5l-1.4 1.4M6.9 17.1l-1.4 1.4m12.6 0l-1.4-1.4M6.9 6.9L5.5 5.5"/></svg>';
  var MOON = '<svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/></svg>';
  function prefValue() { try { return localStorage.getItem('admin.theme') || 'light'; } catch (e) { return 'light'; } }
  function effectiveTheme() {
    var p = prefValue();
    if (p === 'system') return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    return p;
  }
  function refreshToggleIcon(eff) {
    var btn = document.querySelector('[data-theme-toggle]');
    if (!btn) return;
    btn.innerHTML = eff === 'dark' ? SUN : MOON;
    btn.setAttribute('title', eff === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
  }
  function applyTheme() {
    var eff = effectiveTheme();
    document.documentElement.classList.toggle('dark', eff === 'dark');
    refreshToggleIcon(eff);
  }
  window.applyTheme = applyTheme;
  window.toggleTheme = function () {
    var next = effectiveTheme() === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('admin.theme', next); } catch (e) {}
    applyTheme();
  };
  applyTheme(); // run ASAP to minimize flash
  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    (mq.addEventListener ? mq.addEventListener.bind(mq, 'change') : mq.addListener.bind(mq))(function () {
      if (prefValue() === 'system') applyTheme();
    });
  }
  function injectThemeToggle() {
    if (document.querySelector('[data-theme-toggle]')) return;
    var host = document.querySelector('header .ml-auto');
    if (!host) return;
    var btn = document.createElement('button');
    btn.setAttribute('data-theme-toggle', '');
    btn.setAttribute('type', 'button');
    btn.className = 'rounded-md p-1.5 text-slate-500 hover:bg-slate-100';
    btn.addEventListener('click', window.toggleTheme);
    host.insertBefore(btn, host.firstChild);
    refreshToggleIcon(effectiveTheme());
  }

  /* ---- Sidebar (mobile) ---- */
  function setSidebar(open) {
    var sb = document.querySelector('[data-sidebar]');
    var bd = document.querySelector('[data-sidebar-backdrop]');
    if (!sb) return;
    sb.classList.toggle('-translate-x-full', !open);
    if (bd) bd.classList.toggle('hidden', !open);
  }

  /* ---- Dropdowns ---- */
  function closeAllDropdowns(except) {
    document.querySelectorAll('[data-dropdown-menu]').forEach(function (m) {
      if (m !== except) m.classList.add('hidden');
    });
  }

  /* ---- Tabs ---- */
  function activateTab(wrap, key) {
    wrap.querySelectorAll('[data-tab]').forEach(function (b) {
      var on = b.getAttribute('data-tab') === key;
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.classList.toggle('text-slate-900', on);
      b.classList.toggle('border-blue-600', on);
      b.classList.toggle('text-slate-500', !on);
      b.classList.toggle('border-transparent', !on);
    });
    wrap.querySelectorAll('[data-tab-panel]').forEach(function (p) {
      p.classList.toggle('hidden', p.getAttribute('data-tab-panel') !== key);
    });
  }

  /* ---- Off-canvas ---- */
  function openOffcanvas(id) {
    var p = document.getElementById(id);
    if (!p) return;
    var bd = p.querySelector('[data-offcanvas-backdrop]') ||
             document.querySelector('[data-offcanvas-backdrop][data-for="' + id + '"]');
    p.removeAttribute('hidden');
    var panel = p.querySelector('[data-offcanvas]') || p;
    requestAnimationFrame(function () { panel.classList.remove('translate-x-full'); });
    if (bd) bd.removeAttribute('hidden');
  }
  function closeOffcanvas(root) {
    if (!root) return;
    var panel = root.querySelector('[data-offcanvas]') || root;
    panel.classList.add('translate-x-full');
    var bd = root.querySelector('[data-offcanvas-backdrop]');
    if (bd) bd.setAttribute('hidden', '');
    setTimeout(function () { root.setAttribute('hidden', ''); }, 250);
  }

  /* ---- Modal ---- */
  function openModal(id) { var m = document.getElementById(id); if (m) m.removeAttribute('hidden'); }
  function closeModal(m) { if (m) m.setAttribute('hidden', ''); }

  /* ---- Toasts ---- */
  window.adminToast = function (msg, type) {
    type = type || 'info';
    var host = document.querySelector('[data-toast-host]');
    if (!host) return;
    var map = {
      success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
      error:   'border-red-200 bg-red-50 text-red-800',
      warning: 'border-amber-200 bg-amber-50 text-amber-800',
      info:    'border-slate-200 bg-white text-slate-700'
    };
    var t = document.createElement('div');
    t.setAttribute('data-toast', '');
    t.className = 'pointer-events-auto flex items-start gap-3 rounded-md border px-3.5 py-2.5 shadow-sm text-sm ' + (map[type] || map.info);
    t.innerHTML = '<span class="mt-px flex-1">' + msg + '</span>' +
      '<button type="button" data-toast-dismiss class="text-current/60 hover:text-current">&times;</button>';
    host.appendChild(t);
    setTimeout(function () { t.remove(); }, 5000);
  };

  /* ---- Global delegated click handler ---- */
  document.addEventListener('click', function (e) {
    var t = e.target;

    var sbToggle = closest(t, '[data-toggle="sidebar"]');
    if (sbToggle) { setSidebar(true); return; }
    if (closest(t, '[data-sidebar-backdrop]')) { setSidebar(false); return; }
    if (closest(t, '[data-sidebar-close]')) { setSidebar(false); return; }

    var dTrigger = closest(t, '[data-dropdown-trigger]');
    if (dTrigger) {
      e.preventDefault();
      var menu = dTrigger.parentElement.querySelector('[data-dropdown-menu]');
      var willOpen = menu && menu.classList.contains('hidden');
      closeAllDropdowns(menu);
      if (menu) menu.classList.toggle('hidden', !willOpen);
      return;
    }

    var tabBtn = closest(t, '[data-tab]');
    if (tabBtn) {
      var wrap = closest(tabBtn, '[data-tabs]');
      if (wrap) activateTab(wrap, tabBtn.getAttribute('data-tab'));
      return;
    }

    var fToggle = closest(t, '[data-toggle="filter"]');
    if (fToggle) {
      var target = document.getElementById(fToggle.getAttribute('data-target'));
      if (target) target.classList.toggle('hidden');
      return;
    }

    var ocOpen = closest(t, '[data-offcanvas-open]');
    if (ocOpen) { openOffcanvas(ocOpen.getAttribute('data-offcanvas-open')); return; }
    if (closest(t, '[data-offcanvas-close]') || closest(t, '[data-offcanvas-backdrop]')) {
      closeOffcanvas(closest(t, '[data-offcanvas-root]')); return;
    }

    var mOpen = closest(t, '[data-modal-open]');
    if (mOpen) { openModal(mOpen.getAttribute('data-modal-open')); return; }
    if (closest(t, '[data-modal-close]') || closest(t, '[data-modal-backdrop]')) {
      closeModal(closest(t, '[data-modal]')); return;
    }

    var tDismiss = closest(t, '[data-toast-dismiss]');
    if (tDismiss) { var toast = closest(tDismiss, '[data-toast]'); if (toast) toast.remove(); return; }

    /* ---- Media library: filter by type ---- */
    var mediaFilter = closest(t, '[data-media-filter]');
    if (mediaFilter) {
      var scope = closest(mediaFilter, '[data-media-scope]') || document;
      var val = mediaFilter.getAttribute('data-media-filter');
      scope.querySelectorAll('[data-media-filter]').forEach(function (b) {
        var on = b === mediaFilter;
        b.classList.toggle('bg-slate-900', on);
        b.classList.toggle('text-white', on);
        b.classList.toggle('text-slate-600', !on);
        b.classList.toggle('hover:bg-slate-100', !on);
      });
      scope.querySelectorAll('[data-file-type]').forEach(function (item) {
        var match = val === 'all' || item.getAttribute('data-file-type') === val;
        item.classList.toggle('hidden', !match);
      });
      var counter = scope.querySelector('[data-media-count]');
      if (counter) {
        var shown = scope.querySelectorAll('[data-media-pane="list"] [data-file-type]:not(.hidden)').length;
        counter.textContent = shown + (shown === 1 ? ' file' : ' files');
      }
      return;
    }

    /* ---- Media library: list / grid view toggle ---- */
    var viewBtn = closest(t, '[data-media-view]');
    if (viewBtn) {
      var vscope = closest(viewBtn, '[data-media-scope]') || document;
      var mode = viewBtn.getAttribute('data-media-view');
      vscope.querySelectorAll('[data-media-view]').forEach(function (b) {
        var on = b === viewBtn;
        b.classList.toggle('bg-white', on);
        b.classList.toggle('text-slate-900', on);
        b.classList.toggle('shadow-sm', on);
        b.classList.toggle('text-slate-400', !on);
      });
      vscope.querySelectorAll('[data-media-pane]').forEach(function (p) {
        p.classList.toggle('hidden', p.getAttribute('data-media-pane') !== mode);
      });
      return;
    }

    if (!closest(t, '[data-dropdown]')) closeAllDropdowns(null);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeAllDropdowns(null);
      setSidebar(false);
      document.querySelectorAll('[data-offcanvas-root]:not([hidden])').forEach(closeOffcanvas);
      document.querySelectorAll('[data-modal]:not([hidden])').forEach(closeModal);
    }
  });

  /* ---- CSRF safety net ----
   * Every server-rendered POST form already carries {{ csrf_field() }}; this covers forms
   * built or moved by scripts so they cannot silently fail verification. */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || String(form.getAttribute('method') || '').toLowerCase() !== 'post') return;
    if (form.querySelector('input[name="_csrf"]')) return;
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) return;
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = '_csrf';
    input.value = meta.getAttribute('content') || '';
    form.appendChild(input);
  }, true);

  /* Activate first tab in each tab group on load */
  document.addEventListener('DOMContentLoaded', function () {
    injectThemeToggle();
    document.querySelectorAll('[data-tabs]').forEach(function (wrap) {
      var first = wrap.querySelector('[data-tab][data-tab-default]') || wrap.querySelector('[data-tab]');
      if (first) activateTab(wrap, first.getAttribute('data-tab'));
    });
  });
})();
