/* Map: the OpenStreetMap iframe is created only when the visitor asks for it. */
(function () {
  document.querySelectorAll('[data-map]').forEach(function (frame) {
    var button = frame.querySelector('[data-map-load]');
    if (!button) return;
    button.addEventListener('click', function () {
      var iframe = document.createElement('iframe');
      iframe.src = frame.getAttribute('data-map-src');
      iframe.title = frame.getAttribute('data-map-title') || 'Map';
      iframe.setAttribute('referrerpolicy', 'no-referrer-when-downgrade');
      frame.innerHTML = '';
      frame.appendChild(iframe);
      iframe.focus();
    });
  });
})();
