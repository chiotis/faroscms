/*
 * Theme > Single Layouts, Archive Layouts, Header and Footer. The controls of a card are plain radio buttons, checkboxes and
 * fields, so the form works without this script; it only keeps the little page of a single layout card, the picture of the
 * header and the footer, and the summary line of every card in step with what is chosen.
 */
(function () {
  'use strict';

  function chosen(card, key) {
    var found = null;
    card.querySelectorAll('[data-lc="' + key + '"]').forEach(function (el) {
      if (el.type === 'radio' && !el.checked) { return; }
      found = el;
    });
    return found;
  }

  /** The words of a choice: the label of the chosen segment, or the chosen option of a list. */
  function words(el, full) {
    if (!el) { return ''; }
    if (el.tagName === 'SELECT') { return el.options[el.selectedIndex] ? el.options[el.selectedIndex].text.trim() : ''; }
    if (el.type === 'radio') {
      var label = el.parentElement;
      if (full && label.getAttribute('title')) { return label.getAttribute('title'); }
      var span = el.nextElementSibling;
      return span ? span.textContent.trim() : '';
    }
    return el.value;
  }

  function update(card) {
    var preview = card.querySelector('[data-lc-preview]');
    ['template', 'title', 'header', 'sidebar'].forEach(function (key) {
      var el = chosen(card, key);
      if (el && preview) { preview.setAttribute('data-' + key, el.value); }
      if (el && (key === 'template' || key === 'sidebar')) { card.setAttribute('data-' + key, el.value); }
    });

    var out = card.querySelector('[data-lc-summary]');
    if (!out) { return; }
    var parts = [];
    if (chosen(card, 'archive')) {
      parts.push(words(chosen(card, 'archive'), true));
      var columns = chosen(card, 'columns');
      if (columns) { parts.push(columns.value + ' columns'); }
      var per = chosen(card, 'per_page');
      if (per) { parts.push(parseInt(per.value, 10) > 0 ? per.value + ' per page' : 'all on one page'); }
    } else {
      var template = chosen(card, 'template');
      if (template) { parts.push(words(template)); }
      if (!template || template.value !== 'landing') { parts.push(words(chosen(card, 'title'))); }
      var sidebar = chosen(card, 'sidebar');
      if (sidebar && sidebar.value !== 'none' && (!template || template.value !== 'landing')) { parts.push('Sidebar ' + words(sidebar).toLowerCase()); }
      var header = chosen(card, 'header');
      if (header && header.value !== 'site') { parts.push(words(header)); }
    }
    out.textContent = parts.filter(Boolean).join(' · ');
  }

  /**
   * The picture of the Header and Footer tabs: data-hf-map says which fields of the form each data-* of the picture comes
   * from. A radio button or a list gives its value, a box "on" or "off", a text "on" when something is typed in it; several
   * fields together give "on" when any of them is ({"all": [...]} when all of them are).
   */
  function fieldState(root, name) {
    var els = root.querySelectorAll('[name="' + name + '"]');
    var state = null;
    els.forEach(function (el) {
      if (el.type === 'radio') {
        if (el.checked) { state = el.value; }
      } else if (el.type === 'checkbox') {
        state = el.checked ? 'on' : 'off';
      } else if (el.tagName === 'SELECT') {
        state = el.value;
      } else {
        state = el.value.trim() !== '' ? 'on' : 'off';
      }
    });
    return state;
  }

  function updatePreview(root, preview, map) {
    Object.keys(map).forEach(function (attr) {
      var names = map[attr];
      var value;
      if (!Array.isArray(names)) {
        // {"all": [...]}: on only when every one of them has something.
        value = names.all.every(function (name) { return fieldState(root, name) === 'on'; }) ? 'on' : 'off';
      } else if (names.length > 1) {
        value = names.some(function (name) { return fieldState(root, name) === 'on'; }) ? 'on' : 'off';
      } else {
        value = fieldState(root, names[0]);
      }
      if (value === null) { return; }
      // A box that says "on" or "off" is a switch; for the pictures of lists the value itself is what the style is named after.
      if (attr === 'transparent' || attr === 'language' || attr === 'mode' || attr === 'phonebar' || attr === 'social' || attr === 'top') {
        value = value === 'on' ? 'on' : 'off';
      }
      preview.setAttribute('data-' + attr, value);
      if (attr === 'layout') { root.setAttribute('data-layout', value); }
    });
  }

  document.querySelectorAll('[data-hf-root]').forEach(function (root) {
    var preview = root.querySelector('[data-hf-preview]');
    if (!preview) { return; }
    var map = {};
    try { map = JSON.parse(preview.getAttribute('data-hf-map') || '{}'); } catch (e) { map = {}; }
    var sync = function () { updatePreview(root, preview, map); };
    root.addEventListener('change', sync);
    root.addEventListener('input', sync);
    sync();
  });

  document.querySelectorAll('[data-layout-card]').forEach(function (card) {
    update(card);
    card.addEventListener('change', function () { update(card); });
    card.addEventListener('input', function () { update(card); });
  });
}());
