/* Before and after: without JavaScript the two pictures sit side by side. Here the slider variant becomes one
   frame with a handle. The control is a native range input laid over the picture, so dragging, touch, and the
   arrow, Home, and End keys work without any code of ours. */
(function () {
  document.querySelectorAll('[data-before-after]').forEach(function (root) {
    if (!root.closest('.block--slider')) return;

    var range = document.createElement('input');
    range.type = 'range';
    range.min = '0';
    range.max = '100';
    range.step = '1';
    range.value = '50';
    range.className = 'ba-range';
    range.setAttribute('aria-label', root.getAttribute('data-label') || 'Comparison');

    var handle = document.createElement('span');
    handle.className = 'ba-handle';
    handle.setAttribute('aria-hidden', 'true');

    function update() {
      root.style.setProperty('--pos', range.value + '%');
      range.setAttribute('aria-valuetext', range.value + '% ' + (root.getAttribute('data-before-label') || ''));
    }

    range.addEventListener('input', update);
    root.appendChild(range);
    root.appendChild(handle);
    root.classList.add('is-ready');
    update();
  });
})();
