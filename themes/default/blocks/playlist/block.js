/* Playlist: in the layout with the list beside the player, choosing a video from the list plays it in the player. The pictures,
   the play button and the large viewer of the other layouts come from the video block (block.js of video).
   Without JavaScript every item is a link to the video on YouTube. */
(function () {
  var roots = Array.prototype.slice.call(document.querySelectorAll('[data-playlist-featured]'));
  roots.forEach(function (root) {
    var stage = root.querySelector('.pl-stage');
    var items = Array.prototype.slice.call(root.querySelectorAll('[data-pl-item]'));
    if (!stage || items.length === 0) return;

    function line(selector, text) {
      var node = stage.querySelector(selector);
      if (node) {
        node.textContent = text;
        node.hidden = text === '';
      }
    }

    function play(item) {
      var media = stage.querySelector('.video-media');
      var frame = document.createElement('iframe');
      var src = item.getAttribute('data-embed');
      frame.src = src + (src.indexOf('?') === -1 ? '?' : '&') + 'autoplay=1';
      frame.title = item.getAttribute('data-title') || 'Video';
      frame.allow = 'autoplay; fullscreen; picture-in-picture; encrypted-media';
      frame.setAttribute('allowfullscreen', '');
      frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
      media.classList.add('is-playing');
      media.innerHTML = '';
      media.appendChild(frame);
      line('[data-pl-title]', item.getAttribute('data-title') || '');
      line('[data-pl-meta]', item.getAttribute('data-meta') || '');
      line('[data-pl-description]', item.getAttribute('data-description') || '');
      items.forEach(function (other) {
        var current = other === item;
        other.classList.toggle('is-current', current);
        if (current) other.setAttribute('aria-current', 'true'); else other.removeAttribute('aria-current');
      });
    }

    items.forEach(function (item) {
      item.addEventListener('click', function (event) {
        if (event.metaKey || event.ctrlKey || event.shiftKey) return;
        event.preventDefault();
        play(item);
        stage.scrollIntoView({ block: 'nearest', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
      });
    });
  });
})();
