/* Hero slider: when it opens the page under a transparent header, the header's text is light over a slide that is a picture
   and dark over one that is not, so it follows the slide that shows. (The server draws it for the first slide.) */
(function () {
  var header = document.querySelector('.site-header.is-transparent');
  var slider = document.querySelector('.main-shell > .block-hero-slider:first-of-type .slider-hero');
  if (!header || !slider) return;
  slider.addEventListener('slider:change', function (event) {
    header.classList.toggle('is-on-dark', event.detail.slide.hasAttribute('data-dark'));
  });
})();
