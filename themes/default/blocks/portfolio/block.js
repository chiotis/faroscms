/* Portfolio: filter buttons that show the pieces of one category. They are hidden in the markup and shown here,
   so without JavaScript every piece is simply visible. */
(function () {
  document.querySelectorAll('[data-portfolio]').forEach(function (root) {
    var bar = root.querySelector('[data-portfolio-filters]');
    var status = root.querySelector('[data-portfolio-status]');
    if (!bar) return;
    var buttons = Array.prototype.slice.call(bar.querySelectorAll('[data-filter]'));
    var items = Array.prototype.slice.call(root.querySelectorAll('.portfolio-item'));
    bar.hidden = false;

    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        var wanted = button.getAttribute('data-filter');
        var shown = 0;
        buttons.forEach(function (other) {
          var on = other === button;
          other.classList.toggle('is-active', on);
          other.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        items.forEach(function (item) {
          var match = wanted === '' || (' ' + item.getAttribute('data-categories') + ' ').indexOf(' ' + wanted + ' ') !== -1;
          item.hidden = !match;
          if (match) shown++;
        });
        if (status) status.textContent = shown + ' / ' + items.length;
      });
    });
  });
})();
