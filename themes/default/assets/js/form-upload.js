/* The file picker of a form: a drop zone, a button, the list of chosen files with a way to take one out, and the same checks the server makes
   (kind, size, number), so a mistake is told before anything is sent. The real file control stays in the form: what is chosen is put back into
   it, so the form still posts as an ordinary multipart form. The server checks everything again. */
(function () {
  'use strict';

  var REFUSED = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh', 'bat', 'cmd', 'com', 'exe', 'dll', 'msi', 'scr', 'vbs', 'ps1', 'js', 'mjs', 'jsp', 'jspx', 'asp', 'aspx', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'htaccess', 'htpasswd', 'user', 'ini', 'jar', 'war', 'apk', 'app', 'dmg', 'swf', 'hta', 'lnk'];
  var REFUSED_MIME = ['text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/javascript', 'application/javascript', 'application/x-httpd-php', 'application/x-sh', 'application/x-msdownload', 'application/x-executable'];
  var canTransfer = typeof DataTransfer === 'function' && (function () { try { return !!new DataTransfer().files; } catch (e) { return false; } })();

  function fill(text, tokens) {
    return String(text || '').replace(/\{(\w+)\}/g, function (m, key) { return tokens[key] !== undefined ? tokens[key] : m; });
  }

  function size(bytes) {
    if (bytes >= 1048576) return (Math.round(bytes / 104857.6) / 10) + ' MB';
    if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
    return bytes + ' B';
  }

  function extension(name) {
    var parts = String(name).toLowerCase().split('.');
    return parts.length > 1 ? parts.pop() : '';
  }

  function init(box) {
    var input = box.querySelector('input[type="file"]');
    var zone = box.querySelector('[data-file-zone]');
    var drop = box.querySelector('[data-file-drop]');
    var choose = box.querySelector('[data-file-choose]');
    var list = box.querySelector('[data-file-list]');
    var error = box.querySelector('[data-file-error]');
    var status = box.querySelector('[data-file-status]');
    if (!input || !zone || !choose || !list || !canTransfer) return;

    var d = box.dataset;
    var multiple = d.multiple === '1';
    var max = parseInt(d.max, 10) || 1;
    var min = parseInt(d.min, 10) || 0;
    var maxSize = parseInt(d.maxSize, 10) || 0;
    var maxTotal = parseInt(d.maxTotal, 10) || 0;
    var allowed = String(d.extensions || '').split(',').filter(Boolean);
    var files = [];

    box.classList.add('is-enhanced');
    drop.hidden = false;
    choose.hidden = false;
    // The button is the one control to reach by keyboard; the real control is only used to open the picker.
    input.tabIndex = -1;
    input.setAttribute('aria-hidden', 'true');

    function say(message) {
      status.textContent = '';
      window.setTimeout(function () { status.textContent = message; }, 30);
    }

    function fail(message) {
      // Every problem of one choice is told, not only the last.
      error.textContent = error.hidden || !error.textContent ? message : (error.textContent.indexOf(message) === -1 ? error.textContent + ' ' + message : error.textContent);
      error.hidden = false;
      box.classList.add('has-error');
      input.setAttribute('aria-invalid', 'true');
      choose.setAttribute('aria-describedby', error.id || (error.id = input.id + '-js-error'));
    }

    function clearError() {
      error.hidden = true;
      error.textContent = '';
      box.classList.remove('has-error');
      input.removeAttribute('aria-invalid');
      choose.removeAttribute('aria-describedby');
    }

    function problem(file) {
      var ext = extension(file.name);
      var parts = String(file.name).toLowerCase().split('.').slice(1);
      var tokens = { name: file.name, max: d.sizeText, types: d.typesText, count: String(max), total: d.totalText };
      if (!ext || allowed.indexOf(ext) === -1 || parts.some(function (p) { return REFUSED.indexOf(p) !== -1; }) || REFUSED_MIME.indexOf(file.type) !== -1) {
        return fill(d.msgType, tokens);
      }
      if (maxSize > 0 && file.size > maxSize) return fill(d.msgSize, tokens);
      return '';
    }

    function sync() {
      var transfer = new DataTransfer();
      files.forEach(function (file) { transfer.items.add(file); });
      input.files = transfer.files;
    }

    function render(focusIndex) {
      list.textContent = '';
      files.forEach(function (file) {
        var item = document.createElement('li');
        var name = document.createElement('span');
        name.className = 'form-file-name';
        name.textContent = file.name;
        name.title = file.name;
        var bytes = document.createElement('span');
        bytes.className = 'form-file-size';
        bytes.textContent = size(file.size);
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'form-file-remove';
        remove.setAttribute('aria-label', fill(d.msgRemove, { name: file.name }));
        remove.textContent = '×';
        remove.addEventListener('click', function () {
          var at = files.indexOf(file);
          files.splice(at, 1);
          sync();
          clearError();
          render(Math.min(at, files.length - 1));
          say(fill(d.msgRemoved, { name: file.name }));
        });
        item.appendChild(name);
        item.appendChild(bytes);
        item.appendChild(remove);
        list.appendChild(item);
      });
      list.hidden = files.length === 0;
      if (focusIndex !== undefined) {
        var buttons = list.querySelectorAll('.form-file-remove');
        var target = focusIndex >= 0 && buttons[focusIndex] ? buttons[focusIndex] : choose;
        target.focus();
      }
    }

    function add(incoming) {
      clearError();
      var added = 0;
      var next = multiple ? files.slice() : [];
      var list_ = Array.prototype.slice.call(incoming);
      if (!multiple && list_.length > 1) {
        fail(fill(d.msgMax, { count: '1' }));
        sync();
        say(error.textContent);
        return;
      }
      for (var i = 0; i < list_.length; i++) {
        var file = list_[i];
        var bad = problem(file);
        if (bad) { fail(bad); continue; }
        var same = next.some(function (other) { return other.name === file.name && other.size === file.size && other.lastModified === file.lastModified; });
        if (same) continue;
        if (next.length >= max) { fail(fill(d.msgMax, { count: String(max) })); break; }
        var total = next.reduce(function (sum, f) { return sum + f.size; }, 0) + file.size;
        if (maxTotal > 0 && total > maxTotal) { fail(fill(d.msgTotal, { max: d.totalText })); continue; }
        next.push(file);
        added++;
      }
      if (multiple || added > 0) files = next;
      // What the control holds is always what is in the list (a picker that was refused leaves the control as it was).
      sync();
      render();
      if (!error.hidden) say(error.textContent);
      else if (added > 0) say(fill(d.msgAdded, { count: String(added), total: String(files.length) }));
    }

    choose.addEventListener('click', function () { input.click(); });
    input.addEventListener('change', function () {
      // The picker leaves what was just chosen in the control: it is taken from there into the list.
      add(Array.prototype.slice.call(input.files));
    });

    ['dragenter', 'dragover'].forEach(function (type) {
      zone.addEventListener(type, function (event) {
        if (!event.dataTransfer || Array.prototype.indexOf.call(event.dataTransfer.types || [], 'Files') === -1) return;
        event.preventDefault();
        zone.classList.add('is-dragover');
      });
    });
    ['dragleave', 'dragend'].forEach(function (type) {
      zone.addEventListener(type, function (event) {
        if (event.relatedTarget && zone.contains(event.relatedTarget)) return;
        zone.classList.remove('is-dragover');
      });
    });
    zone.addEventListener('drop', function (event) {
      zone.classList.remove('is-dragover');
      if (!event.dataTransfer || !event.dataTransfer.files || !event.dataTransfer.files.length) return;
      event.preventDefault();
      add(event.dataTransfer.files);
    });

    var form = box.closest('form');
    if (form) {
      form.addEventListener('submit', function (event) {
        var need = Math.max(min, 0);
        if (files.length < need) {
          event.preventDefault();
          fail(need > 1 ? fill(d.msgMin, { count: String(need) }) : d.msgRequired);
          say(error.textContent);
          choose.focus();
        }
      });
      // A form that was sent with a mistake comes back without its files (a browser cannot keep them): the control is empty then.
      form.addEventListener('reset', function () { files = []; clearError(); render(); });
    }
  }

  function start() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-file-field]'), init);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
}());
