/* Gallery viewer: progressive enhancement over plain links to the full images. */
(function () {
  if (typeof HTMLDialogElement !== 'function') return;

  document.querySelectorAll('[data-gallery]').forEach(function (gallery) {
    var links = Array.prototype.slice.call(gallery.querySelectorAll('[data-gallery-item]'));
    if (links.length === 0) return;

    var dialog = document.createElement('dialog');
    dialog.className = 'gallery-dialog';
    dialog.setAttribute('aria-label', gallery.getAttribute('data-gallery-label') || 'Image viewer');
    dialog.innerHTML =
      '<figure class="gallery-dialog-figure"><img alt=""><figcaption></figcaption></figure>' +
      '<p class="gallery-dialog-count" aria-live="polite"></p>' +
      '<button type="button" class="gallery-dialog-btn is-close"><span aria-hidden="true">×</span></button>' +
      '<button type="button" class="gallery-dialog-btn is-prev"><span aria-hidden="true">‹</span></button>' +
      '<button type="button" class="gallery-dialog-btn is-next"><span aria-hidden="true">›</span></button>';
    document.body.appendChild(dialog);

    var img = dialog.querySelector('img');
    var caption = dialog.querySelector('figcaption');
    var count = dialog.querySelector('.gallery-dialog-count');
    var closeBtn = dialog.querySelector('.is-close');
    var prevBtn = dialog.querySelector('.is-prev');
    var nextBtn = dialog.querySelector('.is-next');
    closeBtn.setAttribute('aria-label', gallery.getAttribute('data-label-close') || 'Close');
    prevBtn.setAttribute('aria-label', gallery.getAttribute('data-label-prev') || 'Previous image');
    nextBtn.setAttribute('aria-label', gallery.getAttribute('data-label-next') || 'Next image');
    if (links.length < 2) {
      prevBtn.hidden = true;
      nextBtn.hidden = true;
    }

    var index = 0;
    var opener = null;

    function show(i) {
      index = (i + links.length) % links.length;
      var link = links[index];
      var thumb = link.querySelector('img');
      img.src = link.getAttribute('href');
      img.alt = thumb ? thumb.alt : '';
      caption.textContent = link.getAttribute('data-caption') || '';
      caption.hidden = caption.textContent === '';
      count.textContent = links.length > 1 ? (index + 1) + ' / ' + links.length : '';
    }

    links.forEach(function (link, i) {
      link.addEventListener('click', function (event) {
        if (event.metaKey || event.ctrlKey || event.shiftKey) return;
        event.preventDefault();
        opener = link;
        show(i);
        dialog.showModal();
        closeBtn.focus();
      });
    });

    prevBtn.addEventListener('click', function () { show(index - 1); });
    nextBtn.addEventListener('click', function () { show(index + 1); });
    closeBtn.addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowLeft') { event.preventDefault(); show(index - 1); }
      if (event.key === 'ArrowRight') { event.preventDefault(); show(index + 1); }
    });
    dialog.addEventListener('close', function () {
      img.removeAttribute('src');
      if (opener) opener.focus();
    });
  });
})();
