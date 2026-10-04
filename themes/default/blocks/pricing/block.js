/* Pricing: the monthly / yearly switch. A plan shows both prices in the markup; the script shows the one that is chosen,
   so without JavaScript a visitor still sees both. */
(function () {
  document.querySelectorAll('[data-pricing]').forEach(function (root) {
    var bar = root.querySelector('[data-pricing-switch]');
    if (!bar) return;
    var buttons = Array.prototype.slice.call(bar.querySelectorAll('[data-billing]'));

    function show(period) {
      buttons.forEach(function (button) {
        var on = button.getAttribute('data-billing') === period;
        button.classList.toggle('is-active', on);
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      root.querySelectorAll('[data-billing-price]').forEach(function (price) {
        price.hidden = price.getAttribute('data-billing-price') !== period;
      });
    }

    bar.hidden = false;
    buttons.forEach(function (button) {
      button.addEventListener('click', function () { show(button.getAttribute('data-billing')); });
    });
    show('monthly');
  });
})();
