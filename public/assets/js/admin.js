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
 *   window.adminToast(msg, type)     -> spawn a toast programmatically (text only)
 *   <template data-flash data-type>  -> server flash message, shown as a toast on load
 *   table[data-sortable] th[data-sort] -> client-side column sorting (td[data-sort-value] optional)
 *   form[data-confirm="message"]     -> confirmation modal before submitting
 *   [data-copy="text"]               -> copy text to the clipboard
 *   [data-select-all="name"]         -> checkbox toggling every input[name] in its form
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

  /* ---- Toasts ----
   * One placement for every screen (bottom right, as in the admin template). Success and info
   * toasts hide after 5s and warnings after 8s, with a countdown bar; errors stay until dismissed. */
  var TOAST_TONES = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    error:   'border-red-200 bg-red-50 text-red-800',
    warning: 'border-amber-200 bg-amber-50 text-amber-800',
    info:    'border-slate-200 bg-white text-slate-700'
  };
  var TOAST_BARS = { success: 'bg-emerald-400', warning: 'bg-amber-400', info: 'bg-slate-300', error: 'bg-red-400' };
  /* On a screen with an action bar every message shows in the bar: the bar changes colour, the buttons slide away,
   * and the message takes their place. Messages queue, so two flashes on one page show one after the other.
   * Errors stay until dismissed; the rest go back by themselves once there was time to read them. */
  var BAR_ICONS = {
    success: '<path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>',
    warning: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9L2.4 18a2 2 0 001.7 3h15.8a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/>',
    error: '<path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/>',
    info: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 8h.01M11 12h1v5h1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'
  };
  var barQueue = [];
  var barBusy = false;

  function showBarMessage(bar, msg, type) {
    barQueue.push({ bar: bar, msg: msg, type: type });
    if (!barBusy) nextBarMessage();
  }

  function nextBarMessage() {
    var item = barQueue.shift();
    if (!item) { barBusy = false; return; }
    barBusy = true;
    var bar = item.bar;
    var content = bar.querySelector('[data-action-bar-content]');
    var text = bar.querySelector('[data-action-bar-text]');
    var icon = bar.querySelector('[data-action-bar-icon]');
    var progress = bar.querySelector('[data-action-bar-progress]');
    var live = bar.querySelector('[data-action-bar-live]');
    var dismiss = bar.querySelector('[data-action-bar-dismiss]');
    var timer = null;
    var done = false;

    text.textContent = item.msg;
    icon.innerHTML = '<svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">' + BAR_ICONS[item.type] + '</svg>';
    ['success', 'warning', 'error', 'info'].forEach(function (t) { bar.classList.remove('tone-' + t); });
    bar.classList.add('tone-' + item.type);
    // Screen readers get the message from a live region; the visible layer is hidden from them.
    live.setAttribute('aria-live', item.type === 'error' ? 'assertive' : 'polite');
    live.textContent = '';
    setTimeout(function () { live.textContent = item.msg; }, 50);
    if (content) content.setAttribute('inert', '');
    dismiss.removeAttribute('tabindex');
    bar.classList.add('is-notifying');

    function finish() {
      if (done) return;
      done = true;
      clearTimeout(timer);
      document.removeEventListener('keydown', onKey);
      bar.classList.remove('is-notifying');
      if (content) content.removeAttribute('inert');
      dismiss.setAttribute('tabindex', '-1');
      progress.style.transition = 'none';
      progress.style.width = '0';
      setTimeout(function () {
        if (!barQueue.length) bar.classList.remove('tone-' + item.type);
        nextBarMessage();
      }, 450);
    }
    function onKey(event) { if (event.key === 'Escape') finish(); }
    dismiss.onclick = finish;
    document.addEventListener('keydown', onKey);

    if (item.type === 'error') {
      progress.style.width = '0';
      dismiss.focus();
      return;
    }
    var base = item.type === 'warning' ? 8000 : 4500;
    var duration = Math.max(base, 2000 + item.msg.length * 40);
    progress.style.transition = 'none';
    progress.style.width = '100%';
    void progress.offsetWidth;
    progress.style.transition = 'width ' + duration + 'ms linear';
    progress.style.width = '0';
    timer = setTimeout(finish, duration);
  }

  window.adminToast = function (msg, type) {
    type = TOAST_TONES[type] ? type : 'info';
    var bar = document.querySelector('[data-action-bar]');
    if (bar) { showBarMessage(bar, String(msg), type); return; }
    var host = document.querySelector('[data-toast-host]');
    if (!host) return;
    var t = document.createElement('div');
    t.setAttribute('data-toast', '');
    t.setAttribute('role', type === 'error' ? 'alert' : 'status');
    t.className = 'pointer-events-auto relative overflow-hidden rounded-md border shadow-sm text-sm ' + TOAST_TONES[type];
    var row = document.createElement('div');
    row.className = 'flex items-start gap-3 px-3.5 py-2.5';
    var text = document.createElement('span');
    text.className = 'mt-px flex-1 break-words';
    text.textContent = msg;
    var close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('data-toast-dismiss', '');
    close.setAttribute('aria-label', 'Dismiss');
    close.className = 'opacity-60 hover:opacity-100';
    close.textContent = '\u00d7';
    row.appendChild(text);
    row.appendChild(close);
    t.appendChild(row);
    host.appendChild(t);
    var duration = type === 'error' ? 0 : (type === 'warning' ? 8000 : 5000);
    if (duration > 0) {
      var bar = document.createElement('div');
      bar.className = 'absolute bottom-0 left-0 h-0.5 ' + TOAST_BARS[type];
      bar.style.width = '100%';
      bar.style.transition = 'width ' + duration + 'ms linear';
      t.appendChild(bar);
      requestAnimationFrame(function () { requestAnimationFrame(function () { bar.style.width = '0%'; }); });
      setTimeout(function () { t.remove(); }, duration);
    }
  };

  function showServerFlashes() {
    document.querySelectorAll('template[data-flash]').forEach(function (tpl) {
      var message = (tpl.content ? tpl.content.textContent : tpl.textContent || '').trim();
      if (message) window.adminToast(message, tpl.getAttribute('data-type') || 'success');
      tpl.remove();
    });
  }

  /* ---- Sortable tables ---- */
  function sortTable(th) {
    var table = closest(th, 'table');
    var body = table && table.tBodies[0];
    if (!body) return;
    var index = Array.prototype.indexOf.call(th.parentElement.children, th);
    var dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
    table.querySelectorAll('th[data-sort]').forEach(function (other) {
      other.removeAttribute('aria-sort');
      var icon = other.querySelector('[data-sort-icon]');
      if (icon) icon.textContent = '\u2195';
    });
    th.setAttribute('aria-sort', dir);
    var ownIcon = th.querySelector('[data-sort-icon]');
    if (ownIcon) ownIcon.textContent = dir === 'ascending' ? '\u2191' : '\u2193';
    var numeric = th.getAttribute('data-sort') === 'number';
    var rows = Array.prototype.slice.call(body.rows);
    rows.sort(function (a, b) {
      var ca = a.cells[index], cb = b.cells[index];
      var va = ca ? (ca.getAttribute('data-sort-value') || ca.textContent).trim() : '';
      var vb = cb ? (cb.getAttribute('data-sort-value') || cb.textContent).trim() : '';
      var result = numeric ? (parseFloat(va) || 0) - (parseFloat(vb) || 0) : va.localeCompare(vb, undefined, { numeric: true, sensitivity: 'base' });
      return dir === 'ascending' ? result : -result;
    });
    rows.forEach(function (row) { body.appendChild(row); });
  }
  function decorateSortableHeaders() {
    document.querySelectorAll('table[data-sortable] th[data-sort]').forEach(function (th) {
      if (th.querySelector('[data-sort-icon]')) return;
      th.classList.add('cursor-pointer', 'select-none', 'hover:text-slate-600');
      th.setAttribute('tabindex', '0');
      var icon = document.createElement('span');
      icon.setAttribute('data-sort-icon', '');
      icon.setAttribute('aria-hidden', 'true');
      icon.className = 'ml-1 text-[10px] text-slate-300';
      icon.textContent = '\u2195';
      th.appendChild(icon);
    });
  }

  /* ---- Confirmation modal (replaces window.confirm for forms and submit buttons with data-confirm) ---- */
  var pendingConfirm = null;
  function ensureConfirmModal() {
    var modal = document.getElementById('admin-confirm-modal');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'admin-confirm-modal';
    modal.setAttribute('data-modal', '');
    modal.setAttribute('hidden', '');
    modal.className = 'fixed inset-0 z-[1000] flex items-center justify-center p-4';
    modal.innerHTML =
      '<div data-modal-backdrop class="absolute inset-0 bg-slate-900/40"></div>' +
      '<div role="dialog" aria-modal="true" aria-labelledby="admin-confirm-title" class="relative w-full max-w-sm rounded-lg border border-slate-200 bg-white p-5 shadow-xl">' +
      '<h2 id="admin-confirm-title" class="text-sm font-semibold text-slate-900">Please confirm</h2>' +
      '<p data-confirm-message class="mt-2 text-sm text-slate-600"></p>' +
      '<div class="mt-5 flex justify-end gap-2">' +
      '<button type="button" data-modal-close class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>' +
      '<button type="button" data-confirm-accept class="rounded-md bg-red-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-red-700">Confirm</button>' +
      '</div></div>';
    document.body.appendChild(modal);
    modal.querySelector('[data-confirm-accept]').addEventListener('click', function () {
      var accept = pendingConfirm;
      pendingConfirm = null;
      closeModal(modal);
      if (accept) accept();
    });
    return modal;
  }
  function askConfirm(source, onAccept) {
    pendingConfirm = onAccept;
    var modal = ensureConfirmModal();
    modal.querySelector('[data-confirm-message]').textContent = source.getAttribute('data-confirm') || 'Are you sure?';
    modal.querySelector('[data-confirm-accept]').textContent = source.getAttribute('data-confirm-button') || 'Confirm';
    openModal('admin-confirm-modal');
    modal.querySelector('[data-confirm-accept]').focus();
  }
  function submitConfirmed(form, submitter) {
    form.setAttribute('data-confirmed', '1');
    if (submitter) submitter.setAttribute('data-confirmed', '1');
    if (form.requestSubmit) { form.requestSubmit(submitter || undefined); } else { form.submit(); }
    form.removeAttribute('data-confirmed');
    if (submitter) submitter.removeAttribute('data-confirmed');
  }
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.getAttribute('data-confirmed') === '1') return;
    var submitter = e.submitter || null;
    var source = submitter && submitter.hasAttribute('data-confirm') ? submitter : (form.hasAttribute('data-confirm') ? form : null);
    if (!source) return;
    e.preventDefault();
    askConfirm(source, function () { submitConfirmed(form, submitter); });
  });

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

    var sortHeader = closest(t, 'table[data-sortable] th[data-sort]');
    if (sortHeader) { sortTable(sortHeader); return; }

    var copyBtn = closest(t, '[data-copy]');
    if (copyBtn) {
      var value = copyBtn.getAttribute('data-copy') || '';
      var done = function () { window.adminToast('Copied: ' + value, 'success'); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(value).then(done, function () { window.prompt('Copy:', value); });
      } else {
        window.prompt('Copy:', value);
      }
      return;
    }

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

  document.addEventListener('change', function (e) {
    var toggle = closest(e.target, '[data-select-all]');
    if (!toggle) return;
    var scope = toggle.form || document;
    var name = toggle.getAttribute('data-select-all');
    scope.querySelectorAll('input[type="checkbox"][name="' + name + '"]').forEach(function (box) {
      if (!box.disabled) box.checked = toggle.checked;
    });
  });

  document.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target && e.target.matches && e.target.matches('table[data-sortable] th[data-sort]')) {
      e.preventDefault();
      sortTable(e.target);
      return;
    }
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
    showServerFlashes();
    decorateSortableHeaders();
    document.querySelectorAll('[data-tabs]').forEach(function (wrap) {
      var first = wrap.querySelector('[data-tab][data-tab-default]') || wrap.querySelector('[data-tab]');
      if (first) activateTab(wrap, first.getAttribute('data-tab'));
    });
  });
})();
