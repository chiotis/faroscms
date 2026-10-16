/* Accordion: the items are native <details>, so it works without this file. The script adds a smooth height
   change (skipped when the visitor asks for less motion) and, for "one open at a time", closes the others
   with the same animation instead of the browser's instant switch. */
(function () {
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');

  function panelOf(item) { return item.querySelector('.accordion-panel'); }

  function animate(item, opening) {
    var panel = panelOf(item);
    item._target = opening;
    if (item._anim) item._anim.cancel();
    if (!panel || reduce.matches || !panel.animate) { item.open = opening; return; }
    if (opening) item.open = true;
    var full = panel.offsetHeight;
    panel.style.overflow = 'hidden';
    var from = opening ? 0 : full;
    var to = opening ? full : 0;
    var anim = panel.animate({ height: [from + 'px', to + 'px'] }, { duration: 220, easing: 'cubic-bezier(0.2, 0.7, 0.2, 1)' });
    item._anim = anim;
    anim.onfinish = function () {
      if (!opening) item.open = false;
      panel.style.overflow = '';
      item._anim = null;
    };
    anim.oncancel = function () { panel.style.overflow = ''; };
  }

  document.querySelectorAll('[data-accordion]').forEach(function (list) {
    var single = list.hasAttribute('data-single');
    var items = Array.prototype.slice.call(list.querySelectorAll('.accordion-item'));
    // The script takes over the one-at-a-time rule so the others can close with an animation.
    if (single) items.forEach(function (item) { item.removeAttribute('name'); });
    items.forEach(function (item) {
      var summary = item.querySelector('.accordion-summary');
      if (!summary) return;
      summary.addEventListener('click', function (event) {
        event.preventDefault();
        // What the item is heading for, so a click during the animation turns it around.
        var opening = !(item._target !== undefined ? item._target : item.open);
        if (opening && single) {
          items.forEach(function (other) { if (other !== item && (other._target !== undefined ? other._target : other.open)) animate(other, false); });
        }
        animate(item, opening);
      });
    });
  });
})();
