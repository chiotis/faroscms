/* Marquee: a pause button for the scrolling band (moving content must be stoppable). The band itself runs on CSS alone. */
(function () {
  document.querySelectorAll('[data-marquee]').forEach(function (band) {
    var pauseLabel = band.getAttribute('data-label-pause') || 'Pause';
    var playLabel = band.getAttribute('data-label-play') || 'Play';
    var icon = function (path) {
      return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' + path + '</svg>';
    };
    var pauseIcon = icon('<path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/>');
    var playIcon = icon('<path d="M8 5v14l11-7z"/>');
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'marquee-pause';
    var paused = false;

    function draw() {
      band.classList.toggle('is-paused', paused);
      button.setAttribute('aria-pressed', paused ? 'true' : 'false');
      button.setAttribute('aria-label', paused ? playLabel : pauseLabel);
      button.innerHTML = paused ? playIcon : pauseIcon;
    }

    button.addEventListener('click', function () {
      paused = !paused;
      draw();
    });
    draw();

    var controls = document.createElement('div');
    controls.className = 'marquee-controls';
    controls.appendChild(button);
    band.parentNode.insertBefore(controls, band.nextSibling);
  });
})();
