/* Tabs: the tab list is hidden in the markup and shown here, so without JavaScript every panel is
   simply visible. Follows the WAI-ARIA tabs pattern: arrow keys move between tabs, Home and End jump. */
(function () {
  document.querySelectorAll('[data-tabs]').forEach(function (root) {
    var list = root.querySelector('[data-tablist]');
    var tabs = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));
    if (!list || tabs.length === 0) return;
    var vertical = list.getAttribute('aria-orientation') === 'vertical';

    function panelOf(tab) { return document.getElementById(tab.getAttribute('aria-controls')); }

    function select(tab, focus) {
      tabs.forEach(function (other) {
        var on = other === tab;
        other.setAttribute('aria-selected', on ? 'true' : 'false');
        if (on) other.removeAttribute('tabindex'); else other.setAttribute('tabindex', '-1');
        var panel = panelOf(other);
        if (panel) panel.hidden = !on;
      });
      if (focus) tab.focus();
    }

    list.hidden = false;
    root.classList.add('is-enhanced');
    select(tabs[0], false);

    tabs.forEach(function (tab, i) {
      tab.addEventListener('click', function () { select(tab, false); });
      tab.addEventListener('keydown', function (event) {
        var prevKey = vertical ? 'ArrowUp' : 'ArrowLeft';
        var nextKey = vertical ? 'ArrowDown' : 'ArrowRight';
        var target = null;
        if (event.key === nextKey) target = tabs[(i + 1) % tabs.length];
        else if (event.key === prevKey) target = tabs[(i - 1 + tabs.length) % tabs.length];
        else if (event.key === 'Home') target = tabs[0];
        else if (event.key === 'End') target = tabs[tabs.length - 1];
        if (target) { event.preventDefault(); select(target, true); }
      });
    });
  });
})();
