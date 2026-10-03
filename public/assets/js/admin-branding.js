/*
 * Theme > Branding. The cards are plain fields, so the form works without this script. It adds:
 *   - the colour boxes: a picker that fills the hex code, and a button that empties it (empty means the theme's colour);
 *   - the "back to the theme" links of the cards;
 *
 * The live preview beside the cards is admin-preview.js, shared with the Header and Footer tabs.
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
})();
