/*
 * Admin > Redirects: "select all" for the rows of the page, and the Delete selected button appears with how many are selected. Without
 * this script every row has its own checkbox and the button is not shown (the rows can still be deleted one by one).
 */
(function () {
  'use strict';

  var form = document.querySelector('[data-rd-bulk]');
  if (!form) { return; }
  var all = form.querySelector('[data-rd-all]');
  var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="ids[]"]'));
  var label = form.querySelector('[data-rd-selected]');
  var button = form.querySelector('[data-rd-bulk-button]');

  function update() {
    var n = boxes.filter(function (b) { return b.checked; }).length;
    label.hidden = n === 0;
    button.hidden = n === 0;
    label.textContent = n + ' selected';
    all.checked = n > 0 && n === boxes.length;
    all.indeterminate = n > 0 && n < boxes.length;
    boxes.forEach(function (b) { b.closest('.rd-row').classList.toggle('is-selected', b.checked); });
  }

  all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); update(); });
  boxes.forEach(function (b) { b.addEventListener('change', update); });
  update();
})();
