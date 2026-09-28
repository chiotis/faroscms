/* Video: nothing is loaded from YouTube or Vimeo until the visitor plays a video. The play links work
   without JavaScript (they open the video's own page or file); this script upgrades them. */
(function () {
  var triggers = Array.prototype.slice.call(document.querySelectorAll('[data-video]'));
  if (triggers.length === 0) return;

  function player(trigger) {
    var provider = trigger.getAttribute('data-provider');
    var src = trigger.getAttribute('data-embed');
    var title = trigger.getAttribute('data-title') || 'Video';
    var element;
    if (provider === 'file') {
      element = document.createElement('video');
      element.src = src;
      element.controls = true;
      element.autoplay = true;
      element.playsInline = true;
      element.setAttribute('aria-label', title);
    } else {
      element = document.createElement('iframe');
      element.src = src + (src.indexOf('?') === -1 ? '?' : '&') + 'autoplay=1';
      element.title = title;
      element.allow = 'autoplay; fullscreen; picture-in-picture; encrypted-media';
      element.setAttribute('allowfullscreen', '');
      // YouTube refuses to play when the referrer is stripped completely.
      element.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    }
    return element;
  }

  var dialog = null;
  var stage = null;
  var opener = null;

  function ensureDialog(trigger) {
    if (dialog) return;
    dialog = document.createElement('dialog');
    dialog.className = 'video-dialog';
    dialog.innerHTML =
      '<div class="video-dialog-stage"></div>' +
      '<button type="button" class="video-dialog-close"><span aria-hidden="true">×</span></button>';
    document.body.appendChild(dialog);
    stage = dialog.querySelector('.video-dialog-stage');
    var closeBtn = dialog.querySelector('.video-dialog-close');
    closeBtn.setAttribute('aria-label', trigger.getAttribute('data-label-close') || 'Close video');
    closeBtn.addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('close', function () {
      // Removing the player stops playback.
      stage.innerHTML = '';
      dialog.removeAttribute('aria-label');
      if (opener) opener.focus();
    });
  }

  triggers.forEach(function (trigger) {
    trigger.addEventListener('click', function (event) {
      if (event.metaKey || event.ctrlKey || event.shiftKey) return;
      var inline = trigger.getAttribute('data-mode') === 'inline';
      if (!inline && typeof HTMLDialogElement !== 'function') return;
      event.preventDefault();

      if (inline) {
        var media = trigger.parentNode;
        var el = player(trigger);
        media.classList.add('is-playing');
        media.innerHTML = '';
        media.appendChild(el);
        el.focus();
        return;
      }

      opener = trigger;
      ensureDialog(trigger);
      var frame = player(trigger);
      stage.innerHTML = '';
      stage.appendChild(frame);
      dialog.setAttribute('aria-label', trigger.getAttribute('data-title') || 'Video');
      dialog.showModal();
      dialog.querySelector('.video-dialog-close').focus();
    });
  });
})();
