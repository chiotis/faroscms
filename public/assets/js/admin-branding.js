/*
 * Theme > Branding. The cards are plain fields, so the form works without this script. It adds:
 *   - the colour boxes: a picker that fills the hex code, and a button that empties it (empty means the theme's colour);
 *   - the "back to the theme" links of the cards;
 *   - the live preview: the real site in a frame, which follows every change. The unsaved choices go to the server
 *     (/admin/theme?preview=branding), which answers with the CSS and the attributes the pages would get; they are put into
 *     the frame's document. Nothing is stored until the form is saved. The frame can be shown in a computer, tablet or phone
 *     width, and in light or dark.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-bp]');
  if (!root) { return; }
  var form = root.closest('form');

  // ---- colour boxes
  var HEX = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i;
  function normalize(value) {
    value = value.trim().replace(/^#/, '');
    if (value.length === 3) { value = value.replace(/(.)/g, '$1$1'); }
    return '#' + value.toLowerCase();
  }
  function fire(el) { el.dispatchEvent(new Event('input', { bubbles: true })); }

  root.querySelectorAll('[data-colour]').forEach(function (box) {
    var picker = box.querySelector('input[type="color"]');
    var text = box.querySelector('input[type="text"]');
    var clear = box.querySelector('.lc-colour-x');
    function sync() {
      var value = text.value.trim();
      if (value === '') { box.setAttribute('data-empty', ''); } else { box.removeAttribute('data-empty'); }
      if (HEX.test(value)) { picker.value = normalize(value); }
    }
    picker.addEventListener('input', function () { text.value = picker.value; sync(); fire(text); });
    text.addEventListener('input', sync);
    clear.addEventListener('click', function () { text.value = ''; sync(); fire(text); });
    sync();
  });

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

  // ---- "back to the theme": empties the numbers, colours and words of a card that are the site's own
  root.querySelectorAll('[data-bp-reset]').forEach(function (button) {
    button.addEventListener('click', function () {
      var card = button.closest('.bp-card');
      var kind = button.getAttribute('data-bp-reset');
      var fields = kind === 'colour' ? card.querySelectorAll('.lc-colour input[type="text"]') : card.querySelectorAll('[name*="[design]"]');
      fields.forEach(function (field) {
        if (field.tagName === 'SELECT') {
          var neutral = Array.prototype.find.call(field.options, function (o) { return o.value === 'pairing' || o.value === 'auto'; });
          if (neutral) { field.value = neutral.value; }
        } else if (field.type === 'radio') {
          field.checked = field.value === 'auto';
        } else if (field.type !== 'hidden') {
          field.value = '';
        }
      });
      card.querySelectorAll('[data-colour] input[type="text"]').forEach(function (text) { text.dispatchEvent(new Event('input')); });
      fire(card);
    });
  });

  // ---- the preview
  var frame = root.querySelector('[data-bp-frame]');
  var stage = root.querySelector('[data-bp-stage]');
  var endpoint = root.getAttribute('data-endpoint');
  if (!frame || !stage || !form || !endpoint) { return; }

  var latest = null;       // the last answer of the server
  var sequence = 0;
  var timer = null;
  var originals = typeof WeakMap === 'function' ? new WeakMap() : null;

  function frameDocument() {
    try { return frame.contentDocument; } catch (e) { return null; }
  }

  function selected(name) {
    var el = root.querySelector('input[name="' + name + '"]:checked');
    return el ? el.value : '';
  }

  function applyMode(doc) {
    var forced = selected('bp_mode');
    var attributes = latest ? latest.attributes : {};
    var mode = forced || attributes['data-mode'] || doc.documentElement.getAttribute('data-mode') || 'system';
    var dark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    doc.documentElement.setAttribute('data-mode', mode);
    doc.documentElement.classList.toggle('dark', dark);
    doc.documentElement.classList.toggle('light', !dark);
  }

  function node(doc, tag, attributes, text) {
    var el = doc.createElement(tag);
    Object.keys(attributes || {}).forEach(function (key) { el.setAttribute(key, attributes[key]); });
    if (text) { el.textContent = text; }
    return el;
  }

  function applyBrand(doc) {
    var brand = doc.querySelector('.site-brand');
    if (!brand || !latest || !originals) { return; }
    if (!originals.has(brand)) { originals.set(brand, brand.innerHTML); }
    if (latest.logo) {
      brand.textContent = '';
      brand.appendChild(node(doc, 'img', { 'class': 'site-brand-logo' + (latest.logo_dark ? ' logo-on-light' : ''), alt: '', src: latest.logo }));
      if (latest.logo_dark) { brand.appendChild(node(doc, 'img', { 'class': 'site-brand-logo logo-on-dark', alt: '', src: latest.logo_dark })); }
      if (latest.show_name) { brand.appendChild(node(doc, 'span', { 'class': 'site-brand-title' }, latest.name)); }
    } else {
      brand.innerHTML = originals.get(brand);
    }
  }

  function apply() {
    var doc = frameDocument();
    if (!doc || !doc.documentElement || !doc.head) { return; }
    if (latest) {
      var style = doc.getElementById('faros-branding');
      if (!style) {
        style = doc.createElement('style');
        style.id = 'faros-branding';
        doc.head.appendChild(style);
      }
      style.textContent = latest.css;
      Object.keys(latest.attributes).forEach(function (key) { doc.documentElement.setAttribute(key, latest.attributes[key]); });
      applyBrand(doc);
    }
    applyMode(doc);
    // The page may have been drawn at another width: let it lay itself out again (sticky header, slider).
    try { frame.contentWindow.dispatchEvent(new Event('resize')); } catch (e) { /* the frame went somewhere else */ }
  }

  function request() {
    var data = new FormData();
    new FormData(form).forEach(function (value, key) {
      if (key.indexOf('theme_settings[') === 0 || key === '_csrf') { data.append(key, value); }
    });
    var mine = ++sequence;
    fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (answer) {
        if (answer && mine === sequence) { latest = answer; apply(); }
      })
      .catch(function () { /* the preview is a convenience: a failed request leaves the last picture */ });
  }

  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(request, 250);
  }

  root.addEventListener('input', function (event) {
    if (event.target.name && event.target.name.indexOf('theme_settings[') === 0) { schedule(); }
  });
  root.addEventListener('change', function (event) {
    if (event.target.name && event.target.name.indexOf('theme_settings[') === 0) { schedule(); }
  });

  // The frame shows the page at a computer, tablet or phone width, scaled down to fit the space.
  function fit() {
    var width = parseInt(selected('bp_size'), 10) || 1280;
    var space = stage.clientWidth;
    var height = stage.clientHeight;
    if (!space || !height) { return; }
    var scale = Math.min(1, space / width);
    frame.style.width = width + 'px';
    frame.style.height = Math.ceil(height / scale) + 'px';
    frame.style.transform = 'scale(' + scale + ')';
    frame.style.left = Math.max(0, Math.floor((space - width * scale) / 2)) + 'px';
  }

  root.querySelectorAll('[data-bp-size]').forEach(function (input) { input.addEventListener('change', fit); });
  root.querySelectorAll('[data-bp-mode]').forEach(function (input) {
    input.addEventListener('change', function () { var doc = frameDocument(); if (doc && doc.documentElement) { applyMode(doc); } });
  });
  root.querySelector('[data-bp-reload]').addEventListener('click', function () { frame.contentWindow.location.reload(); });
  frame.addEventListener('load', apply);
  window.addEventListener('resize', fit);
  if (typeof ResizeObserver === 'function') { new ResizeObserver(fit).observe(stage); }
  fit();
})();
