/*
 * Theme > Branding. The cards are plain fields, so the form works without this script. It adds:
 *   - the colour boxes: a picker that fills the hex code, and a button that empties it (empty means the theme's colour);
 *   - the source of each value, and field/group resets to the theme schema's defaults;
 *
 * The live preview beside the cards is admin-preview.js, shared with the Header and Footer tabs.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-bp]');
  if (!root) { return; }
  var defaults;
  try { defaults = JSON.parse(root.getAttribute('data-bp-defaults') || '{}'); } catch (e) { defaults = {}; }
  var groups = {};

  // ---- colour boxes
  var HEX = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i;
  function normalize(value) {
    value = value.trim().replace(/^#/, '');
    if (value.length === 3) { value = value.replace(/(.)/g, '$1$1'); }
    return '#' + value.toLowerCase();
  }
  function fire(el) { el.dispatchEvent(new Event('input', { bubbles: true })); }

  // Use the schema, not a guessed "auto" option: for example, Heading colour defaults to "text".
  root.querySelectorAll('[name]').forEach(function (field) {
    if (!Object.prototype.hasOwnProperty.call(defaults, field.name) || field.type === 'hidden') { return; }
    if (!groups[field.name]) { groups[field.name] = { fields: [], definition: defaults[field.name] }; }
    groups[field.name].fields.push(field);
  });

  function value(group) {
    var first = group.fields[0];
    if (first.type === 'checkbox') { return first.checked ? '1' : '0'; }
    if (first.type === 'radio') {
      var checked = group.fields.find(function (field) { return field.checked; });
      return checked ? checked.value : '';
    }
    return first.value.trim();
  }
  function defaultValue(group) {
    var initial = group.definition.value;
    return typeof initial === 'boolean' ? (initial ? '1' : '0') : String(initial == null ? '' : initial);
  }
  function syncSource(group) {
    if (!group.source) { return; }
    var custom = value(group) !== defaultValue(group);
    group.source.setAttribute('data-bp-value', custom ? 'custom' : 'theme');
    group.status.textContent = custom ? 'Your value' : 'Theme value';
    if (group.reset) { group.reset.hidden = !custom; }
  }
  function reset(group) {
    var initial = defaultValue(group);
    group.fields.forEach(function (field) {
      if (field.type === 'radio') { field.checked = field.value === initial; }
      else if (field.type === 'checkbox') { field.checked = initial === '1'; }
      else { field.value = initial; }
      fire(field);
      field.dispatchEvent(new Event('change', { bubbles: true }));
    });
    syncSource(group);
  }
  function refresh() { root.dispatchEvent(new CustomEvent('preview:refresh', { bubbles: true })); }

  Object.keys(groups).forEach(function (name) {
    var group = groups[name];
    var field = group.fields[0];
    var colour = field.closest('[data-colour]');
    var host = colour ? colour.parentElement : field.closest('dd, .bp-num');
    if (!host) { return; }
    group.source = document.createElement('span');
    group.source.className = 'bp-value';
    group.status = document.createElement('span');
    group.source.appendChild(group.status);
    // Colour boxes already have an individual reset beside the hex code.
    if (!colour) {
      group.reset = document.createElement('button');
      group.reset.type = 'button';
      group.reset.className = 'bp-reset';
      group.reset.textContent = 'Reset';
      group.reset.setAttribute('aria-label', group.definition.label + ': reset to the theme value');
      group.reset.addEventListener('click', function () { reset(group); refresh(); });
      group.source.appendChild(group.reset);
    }
    host.appendChild(group.source);
    group.fields.forEach(function (input) {
      input.addEventListener('input', function () { syncSource(group); });
      input.addEventListener('change', function () { syncSource(group); });
    });
    syncSource(group);
  });

  root.querySelectorAll('[data-colour]').forEach(function (box) {
    var picker = box.querySelector('input[type="color"]');
    var text = box.querySelector('input[type="text"]');
    var clear = box.querySelector('.lc-colour-x');
    function sync() {
      var value = text.value.trim();
      if (value === '') { box.setAttribute('data-empty', ''); } else { box.removeAttribute('data-empty'); }
      if (HEX.test(value)) { picker.value = normalize(value); }
      else if (value === '' && HEX.test(text.placeholder)) { picker.value = normalize(text.placeholder); }
    }
    picker.addEventListener('input', function () { text.value = picker.value; sync(); fire(text); });
    text.addEventListener('input', sync);
    clear.addEventListener('click', function () {
      if (groups[text.name]) { reset(groups[text.name]); } else { text.value = ''; fire(text); }
      sync();
      refresh();
    });
    sync();
  });

  // Empty colour controls show the inherited colour from the preview, while staying empty in the form.
  // Both light and dark tokens are present together, so no temporary mode switch or extra request is needed.
  function inheritedColours() {
    var frame = root.querySelector('[data-bp-frame]');
    var computed;
    try {
      var doc = frame && frame.contentDocument;
      if (!doc || !doc.documentElement) { return; }
      computed = frame.contentWindow.getComputedStyle(doc.documentElement);
    } catch (e) { return; }
    var heading = groups['theme_settings[design][heading_color]'];
    var headingMode = heading ? value(heading) : 'text';
    var tokens = { background: 'bg', surface: 'surface', text: 'text', muted: 'muted', border: 'border' };
    root.querySelectorAll('[data-colour]').forEach(function (box) {
      var text = box.querySelector('input[type="text"]');
      var match = text.name.match(/^theme_settings\[design\]\[(.+)\]$/);
      if (!match || text.value.trim() !== '') { return; }
      var key = match[1];
      var token = key === 'ink' ? '--ink' : '';
      ['light', 'dark'].forEach(function (mode) {
        var prefix = mode === 'light' ? '--n-' : '--d-';
        if (key === mode + '_accent') { token = mode === 'light' ? '--accent-l' : '--accent-d'; }
        Object.keys(tokens).forEach(function (role) { if (key === mode + '_' + role) { token = prefix + tokens[role]; } });
        if (key === 'heading_color_' + mode) {
          token = headingMode === 'accent' ? (mode === 'light' ? '--accent-l' : '--accent-d') : prefix + 'text';
        }
      });
      var colour = token ? computed.getPropertyValue(token).trim() : '';
      text.placeholder = HEX.test(colour) ? normalize(colour) : 'Theme';
      if (HEX.test(colour)) { box.querySelector('input[type="color"]').value = normalize(colour); }
    });
  }
  root.addEventListener('preview:drawn', inheritedColours);
  inheritedColours();

  // ---- the font file is asked for only while a font choice says "your own font file" (or a file is still set)
  var fontRow = root.querySelector('[data-bp-custom-font]');
  if (fontRow) {
    var fontSelects = root.querySelectorAll('select[name$="[heading_font]"], select[name$="[body_font]"]');
    var fontFile = fontRow.querySelector('input');
    var showFont = function () {
      var custom = Array.prototype.some.call(fontSelects, function (select) { return select.value === 'custom'; });
      fontRow.style.display = custom || fontFile.value.trim() !== '' ? '' : 'none';
    };
    fontSelects.forEach(function (select) { select.addEventListener('change', showFont); });
    fontFile.addEventListener('input', showFont);
    showFont();
  }

  // ---- reset only a card's design overrides; selected palette, font pairing and shape stay as the baseline.
  root.querySelectorAll('[data-bp-reset]').forEach(function (button) {
    button.addEventListener('click', function () {
      var card = button.closest('.bp-card');
      Object.keys(groups).forEach(function (name) {
        if (name.indexOf('theme_settings[design]') === 0 && card.contains(groups[name].fields[0])) { reset(groups[name]); }
      });
      refresh();
    });
  });
})();
