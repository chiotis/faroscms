/*
 * A character count under the search and sharing texts of an entry (the SEO tab), in a colour that says whether the length suits:
 * green when it is a good length, amber when it is a little short or long, red when it will be cut off. A field opts in with
 *   data-count="min,max,cut"        good from min to max characters, over max it may be cut off, over cut it surely is
 *   data-count-from="a|b"           selectors of the fields the page uses when this one is empty (the first with a text), counted instead
 *   data-count-label="the title"    what to call that fallback (one for each selector, separated by | )
 * The text says the same as the colour, so the colour is never the only sign. Nothing here is needed to save.
 */
(function () {
  'use strict';

  var fields = Array.prototype.slice.call(document.querySelectorAll('[data-count]'));
  if (!fields.length) return;

  function length(text) { return Array.from(text.trim()).length; }

  fields.forEach(function (field, index) {
    var range = field.getAttribute('data-count').split(',').map(Number);
    if (range.length !== 3 || range.some(isNaN)) return;
    var min = range[0], max = range[1], cut = range[2];
    var from = (field.getAttribute('data-count-from') || '').split('|').filter(Boolean);
    var labels = (field.getAttribute('data-count-label') || 'the fallback').split('|');
    var note = document.createElement('p');
    note.className = 'seo-count';
    note.id = (field.id || 'seo-count-' + index) + '-count';
    // In a row of label and field (a grid) the count goes under the field, in the same cell; in a label it goes after the label, so it is not part of the label's name.
    var host = field.parentNode;
    if (host.tagName === 'LABEL') {
      host.parentNode.insertBefore(note, host.nextSibling);
    } else if (host.classList.contains('seo-row')) {
      var cell = document.createElement('div');
      host.insertBefore(cell, field);
      cell.appendChild(field);
      cell.appendChild(note);
    } else {
      host.insertBefore(note, field.nextSibling);
    }
    var described = field.getAttribute('aria-describedby');
    field.setAttribute('aria-describedby', (described ? described + ' ' : '') + note.id);

    var draw = function () {
      var text = field.value;
      var fallback = '';
      if (text.trim() === '') {
        for (var i = 0; i < from.length; i++) {
          var other = document.querySelector(from[i]);
          if (other && other.value.trim() !== '') { text = other.value; fallback = labels[i] || labels[0]; break; }
        }
      }
      var n = length(text);
      var state, words;
      if (n === 0) { state = 'short'; words = 'empty, search engines will choose a text'; }
      else if (n < min) { state = 'short'; words = 'a little short, aim for ' + min + '–' + max; }
      else if (n <= max) { state = 'good'; words = 'a good length'; }
      else if (n <= cut) { state = 'long'; words = 'a little long, it may be cut off (over ' + max + ')'; }
      else { state = 'over'; words = 'too long, it will be cut off (over ' + cut + ')'; }
      note.className = 'seo-count is-' + state;
      note.textContent = n === 0 ? 'Empty: ' + words.replace(/^empty, /, '') : n + ' characters' + (fallback ? ' (from ' + fallback + ')' : '') + ' · ' + words;
    };

    [field].concat(from.map(function (selector) { return document.querySelector(selector); })).forEach(function (watched) {
      if (watched) watched.addEventListener('input', draw);
    });
    draw();
  });
})();
