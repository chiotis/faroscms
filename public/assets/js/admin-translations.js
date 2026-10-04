/*
 * Admin > Translations: filters the strings as you type (by key or text, by what they need, by area), counts what you changed,
 * puts the source text in a box on request, and asks before leaving with changes that are not saved. Without this script the
 * screen is the plain form: every string is shown and Save saves them.
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-tr-form]');
  if (!form) { return; }
  var rows = Array.prototype.slice.call(form.querySelectorAll('.tr-row'));
  var groups = Array.prototype.slice.call(form.querySelectorAll('.tr-group'));
  var search = form.querySelector('[data-tr-search]');
  var area = form.querySelector('[data-tr-area]');
  var filters = Array.prototype.slice.call(form.querySelectorAll('[data-tr-filter]'));
  var shown = form.querySelector('[data-tr-shown]');
  var none = form.querySelector('[data-tr-none]');
  var dirtyButton = form.querySelector('[data-tr-filter="dirty"]');
  var dirtyCount = form.querySelector('[data-tr-dirty-count]');
  var status = form.querySelector('[data-tr-status]');
  var total = rows.length;
  var filter = 'all';
  var submitting = false;

  function field(row) { return row.querySelector('.tr-field'); }
  function isDirty(row) {
    var f = field(row);
    var reset = row.querySelector('input[name="reset[]"]');
    return f.value !== f.getAttribute('data-original') || (reset && reset.checked);
  }

  function apply() {
    var q = search.value.trim().toLowerCase();
    var g = area.value;
    var visible = 0;
    rows.forEach(function (row) {
      var ok = (!q || row.getAttribute('data-text').indexOf(q) !== -1 || field(row).value.toLowerCase().indexOf(q) !== -1) &&
        (!g || row.getAttribute('data-group') === g);
      if (ok && filter !== 'all') {
        ok = filter === 'custom' ? row.getAttribute('data-custom') === '1' : (filter === 'dirty' ? row.classList.contains('is-dirty') : row.getAttribute('data-status') === filter);
      }
      row.hidden = !ok;
      if (ok) { visible++; }
    });
    groups.forEach(function (group) {
      var any = group.querySelector('.tr-row:not([hidden])');
      group.hidden = !any;
      // While searching, an area with matches is open so the matches can be seen.
      if (any && (q || filter !== 'all' || g)) { group.open = true; }
    });
    none.hidden = visible > 0;
    shown.textContent = visible === total ? shown.getAttribute('data-all') : visible + ' of ' + total + ' strings';
  }

  function count() {
    var n = rows.filter(function (row) { return row.classList.contains('is-dirty'); }).length;
    dirtyCount.textContent = n;
    dirtyButton.hidden = n === 0;
    if (n === 0 && filter === 'dirty') { setFilter('all'); }
    status.textContent = n ? n + (n === 1 ? ' change' : ' changes') + ' not saved' : '';
    status.className = 'text-xs ' + (n ? 'font-medium text-amber-600' : '');
    return n;
  }

  function setFilter(name) {
    filter = name;
    filters.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-tr-filter') === name ? 'true' : 'false'); });
    apply();
  }

  function grow(el) {
    if (el.tagName !== 'TEXTAREA') { return; }
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight + 2, 240) + 'px';
  }

  function touch(row) {
    row.classList.toggle('is-dirty', !!isDirty(row));
    count();
  }

  shown.setAttribute('data-all', shown.textContent);
  rows.forEach(function (row) {
    var f = field(row);
    grow(f);
    f.addEventListener('input', function () { grow(f); touch(row); });
    var reset = row.querySelector('input[name="reset[]"]');
    if (reset) { reset.addEventListener('change', function () { row.classList.toggle('is-reset', reset.checked); touch(row); }); }
    var copy = row.querySelector('[data-tr-copy]');
    if (copy) {
      copy.addEventListener('click', function () {
        f.value = copy.getAttribute('data-tr-copy');
        grow(f);
        f.focus();
        touch(row);
      });
    }
  });
  search.addEventListener('input', apply);
  area.addEventListener('change', apply);
  filters.forEach(function (b) { b.addEventListener('click', function () { setFilter(b.getAttribute('data-tr-filter')); }); });

  // A new string of your own counts as a change too.
  var added = form.querySelectorAll('.tr-add input');
  added.forEach(function (input) { input.addEventListener('input', function () { status.textContent = 'A new string is not saved'; status.className = 'text-xs font-medium text-amber-600'; }); });

  form.addEventListener('submit', function () { submitting = true; });
  window.addEventListener('beforeunload', function (e) {
    var added = Array.prototype.some.call(form.querySelectorAll('.tr-add input'), function (i) { return i.value.trim() !== ''; });
    if (!submitting && (count() > 0 || added)) {
      e.preventDefault();
      e.returnValue = '';
    }
  });
  count();
})();
