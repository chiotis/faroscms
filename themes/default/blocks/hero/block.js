/* Hero with a video behind the text. A video file plays in a <video> that starts by itself (silent, in a loop); a YouTube or
   Vimeo video is put in a frame once the page is open, so nothing is asked of them before. A visitor whose device asks for
   less motion, or for less data, sees the still image, and can start the video with the button. The button pauses it for
   everyone else. The video also rests while it is off screen. */
(function () {
  var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-hero-video]'));
  if (boxes.length === 0) return;

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var saveData = navigator.connection && navigator.connection.saveData === true;

  boxes.forEach(function (box) {
    var provider = box.getAttribute('data-provider');
    var video = box.querySelector('video');
    var holder = box.closest('.block-hero');
    var toggle = (box.parentNode && box.parentNode.querySelector('[data-hero-video-toggle]')) || (holder && holder.querySelector('[data-hero-video-toggle]'));
    var frame = null;
    var wanted = !(reduce || saveData);   // what the visitor wants: false once they paused it, or asked for less motion
    var visible = true;

    function label(playing) {
      if (!toggle) return;
      toggle.setAttribute('aria-pressed', playing ? 'false' : 'true');
      toggle.setAttribute('aria-label', box.getAttribute(playing ? 'data-label-pause' : 'data-label-play') || '');
    }

    function play() {
      if (provider === 'file') {
        if (!video) return;
        var started = video.play();
        if (started && started.then) { started.then(function () { label(true); }, function () { label(false); }); } else { label(true); }
      } else if (!frame) {
        frame = document.createElement('iframe');
        frame.src = box.getAttribute('data-embed');
        frame.title = '';
        frame.setAttribute('aria-hidden', 'true');
        frame.setAttribute('tabindex', '-1');
        frame.setAttribute('allow', 'autoplay; encrypted-media');
        frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        frame.addEventListener('load', function () { box.classList.add('has-frame'); });
        box.appendChild(frame);
        label(true);
      }
    }

    function stop() {
      if (provider === 'file') {
        if (video) video.pause();
      } else if (frame) {
        frame.remove();
        frame = null;
        box.classList.remove('has-frame');
      }
      label(false);
    }

    if (toggle) {
      toggle.hidden = false;
      toggle.addEventListener('click', function () {
        wanted = toggle.getAttribute('aria-pressed') === 'true';
        if (wanted) play(); else stop();
      });
    }

    if (provider === 'file' && video) {
      if (!wanted) { video.removeAttribute('autoplay'); video.pause(); label(false); } else { label(!video.paused); video.addEventListener('playing', function () { label(true); }); }
    } else if (wanted) {
      play();
    } else {
      label(false);
    }

    // It rests while it is not on screen (saves the battery and the data), and goes on when it is back.
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        visible = entries[entries.length - 1].isIntersecting;
        if (!wanted) return;
        if (visible) play(); else if (provider === 'file') stop();
      }).observe(box);
    }
  });
})();
