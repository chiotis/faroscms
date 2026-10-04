/*
 * Admin > Media: files dropped on the upload line go into its file field (and the line says how many are chosen), a click on a file opens
 * its details in a window (copied from the file's template, so its forms are the plain forms they are), an address can be copied, and
 * selecting files shows the bar to tag or delete them together. Without this script the files are listed and the upload line works;
 * only the details window, the copy buttons and the selection bar need it.
 */
(function () {
  'use strict';

  var input = document.getElementById('media-upload-input');
  var zone = document.getElementById('media-upload-form');
  if (zone && input) {
    var note = zone.querySelector('.md-upload-text small');
    var original = note ? note.textContent : '';
    var describe = function () {
      zone.classList.toggle('has-files', input.files && input.files.length > 0);
      if (note) {
        note.textContent = input.files && input.files.length ? (input.files.length === 1 ? input.files[0].name : input.files.length + ' files chosen') : original;
      }
    };
    ['dragenter', 'dragover'].forEach(function (name) {
      zone.addEventListener(name, function (event) {
        if (!event.dataTransfer || !Array.prototype.slice.call(event.dataTransfer.types || []).includes('Files')) { return; }
        event.preventDefault();
        zone.classList.add('is-dragover');
      });
    });
    ['dragleave', 'dragend'].forEach(function (name) {
      zone.addEventListener(name, function (event) { if (!zone.contains(event.relatedTarget)) { zone.classList.remove('is-dragover'); } });
    });
    zone.addEventListener('drop', function (event) {
      zone.classList.remove('is-dragover');
      if (!event.dataTransfer || !event.dataTransfer.files || event.dataTransfer.files.length === 0) { return; }
      event.preventDefault();
      input.files = event.dataTransfer.files;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    input.addEventListener('change', describe);
  }

  // The details of a file, in a window.
  var modal = document.getElementById('media-detail');
  var body = modal ? modal.querySelector('[data-md-body]') : null;
  document.addEventListener('click', function (event) {
    var open = event.target.closest ? event.target.closest('[data-md-open]') : null;
    if (open && modal && body) {
      var tpl = document.querySelector('template[data-md-detail="' + open.getAttribute('data-md-open') + '"]');
      if (!tpl) { return; }
      body.textContent = '';
      body.appendChild(tpl.content.cloneNode(true));
      modal.removeAttribute('hidden');
      var first = body.querySelector('input[name="alt"], input[name="tags"]');
      if (first) { first.focus(); }
      return;
    }
    var copy = event.target.closest ? event.target.closest('[data-copy-url]') : null;
    if (copy) {
      var value = copy.getAttribute('data-copy-url') || '';
      if (!value) { return; }
      var done = function () { if (window.adminToast) { window.adminToast('URL copied', 'success'); } };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(value).then(done, function () { /* nothing to do */ });
      } else {
        var tmp = document.createElement('input');
        tmp.value = value;
        document.body.appendChild(tmp);
        tmp.select();
        try { document.execCommand('copy'); done(); } catch (e) { /* not allowed */ }
        tmp.remove();
      }
    }
  });

  // Selecting files, and the bar for what is selected.
  var count = document.getElementById('media-selected-count');
  var panel = document.getElementById('media-bulk-panel');
  var all = document.getElementById('media-select-all');
  var everything = document.getElementById('media-apply-all-filtered');
  var boxes = Array.prototype.slice.call(document.querySelectorAll('.media-select-item'));
  var holders = Array.prototype.slice.call(document.querySelectorAll('[data-selected-ids]'));
  var flags = Array.prototype.slice.call(document.querySelectorAll('[data-apply-all-input]'));
  var forms = [document.getElementById('media-bulk-tags-form'), document.getElementById('media-bulk-delete-form')].filter(Boolean);
  var selected = function () { return boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; }).filter(Boolean); };

  function sync() {
    var ids = selected();
    if (count) { count.textContent = String(ids.length); }
    if (panel) { panel.classList.toggle('hidden', ids.length === 0); }
    if (ids.length === 0 && everything) {
      everything.checked = false;
      flags.forEach(function (f) { f.value = '0'; });
    }
    if (all) {
      all.checked = boxes.length > 0 && ids.length === boxes.length;
      all.indeterminate = ids.length > 0 && ids.length < boxes.length;
    }
    boxes.forEach(function (b) {
      var holder = b.closest('.md-tile') || b.closest('tr');
      if (holder) { holder.classList.toggle('is-selected', b.checked); }
    });
  }

  if (all) { all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); sync(); }); }
  boxes.forEach(function (b) { b.addEventListener('change', sync); });
  if (everything) { everything.addEventListener('change', function () { flags.forEach(function (f) { f.value = everything.checked ? '1' : '0'; }); }); }
  forms.forEach(function (form) {
    form.addEventListener('submit', function (event) {
      var ids = selected();
      if (!(everything && everything.checked) && ids.length === 0) {
        event.preventDefault();
        event.stopImmediatePropagation();
        if (window.adminToast) { window.adminToast('Select at least one media item.', 'warning'); }
        return;
      }
      holders.forEach(function (holder) {
        if (holder.closest('form') !== form) { return; }
        holder.textContent = '';
        ids.forEach(function (id) {
          var hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = 'selected_ids[]';
          hidden.value = id;
          holder.appendChild(hidden);
        });
      });
    });
  });
  sync();
})();
