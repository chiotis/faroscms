/*
 * Admin > SEO: the pictures that follow the form. The Search tab draws a search result (the title in the format the form
 * says, the description, how much of each a search engine would show), the Social tab draws a shared link, and the
 * Identity tab shows the address fields only for a local business. The form itself is plain fields and works without this.
 */
(function () {
  'use strict';

  function field(form, name) { return form ? form.elements[name] : null; }
  function value(form, name) { var el = field(form, name); return el && el.value ? el.value.trim() : ''; }
  function checked(form, name) {
    var list = form.querySelectorAll('input[name="' + name + '"]');
    for (var i = 0; i < list.length; i++) { if (list[i].checked) { return list[i].value; } }
    return '';
  }
  function clip(text, max) { return text.length > max ? text.slice(0, Math.max(0, max - 1)).replace(/\s+$/, '') + '…' : text; }
  function escapeRe(text) { return text.replace(/[.*+?^${}()|[\]\\\/-]/g, '\\$&'); }

  /* The same rules as SeoSettings::title() in PHP: the words are filled in and a gap left by an empty one is closed. */
  function title(format, sep, page, site, tagline) {
    var out = format.replace(/\{title\}/g, page).replace(/\{site\}/g, site).replace(/\{tagline\}/g, tagline).replace(/\{sep\}/g, sep);
    var q = escapeRe(sep);
    out = out.replace(new RegExp('(?:\\s*' + q + '\\s*){2,}', 'g'), ' ' + sep + ' ');
    out = out.replace(new RegExp('^\\s*' + q + '\\s*|\\s*' + q + '\\s*$', 'g'), '').trim();
    return out || page;
  }

  function host(url) { return url.replace(/^https?:\/\//, '').replace(/\/$/, '') || 'example.com'; }

  function search(root) {
    var form = root.closest('form');
    var site = root.getAttribute('data-site') || '';
    var tagline = root.getAttribute('data-tagline') || '';
    var url = root.getAttribute('data-url') || '';
    var defaultFormat = root.getAttribute('data-default-format') || '{title} {sep} {site}';
    var titleLimit = parseInt(root.getAttribute('data-title-limit'), 10) || 60;
    var descLimit = parseInt(root.getAttribute('data-desc-limit'), 10) || 160;
    var kind = root.querySelector('[data-sp-kind]');
    var sample = root.querySelector('[data-sp-sample]');
    var wrap = root.querySelector('[data-sp-sample-wrap]');
    var card = root.querySelector('[data-sp-card]');
    var out = { title: root.querySelector('[data-sp-title]'), desc: root.querySelector('[data-sp-desc]'), meter: root.querySelector('[data-sp-meter]'), url: root.querySelector('[data-sp-url]') };

    function draw() {
      var type = kind.value;
      var sep = checked(form, 'separator') || '|';
      var page = sample.value.trim() || 'Page title';
      var full;
      var description;
      if (type === '') {
        full = value(form, 'home_title') || site;
        description = value(form, 'home_description') || value(form, 'default_description') || tagline;
      } else {
        var own = value(form, 'types[' + type + '][title_format]');
        full = title(own || value(form, 'title_format') || defaultFormat, sep, page, site, tagline);
        description = value(form, 'default_description') || tagline;
      }
      wrap.style.visibility = type === '' ? 'hidden' : 'visible';
      out.title.textContent = clip(full, titleLimit + 10);
      out.desc.textContent = clip(description, descLimit);
      out.url.textContent = host(url) + (type === '' ? '' : ' › ' + (type === 'pages' ? 'page' : type));
      out.meter.textContent = 'Title ' + full.length + ' of about ' + titleLimit + ' characters · Description ' + description.length + ' of ' + descLimit;
      out.meter.style.color = full.length > titleLimit || description.length > descLimit ? '#b45309' : '';
    }

    form.addEventListener('input', draw);
    form.addEventListener('change', draw);
    kind.addEventListener('change', draw);
    root.querySelectorAll('[data-sp-device]').forEach(function (input) {
      input.addEventListener('change', function () { card.classList.toggle('is-phone', input.value === 'phone' && input.checked); });
    });
    // The words to put in the format go where the cursor is.
    form.querySelectorAll('[data-insert]').forEach(function (button) {
      button.addEventListener('click', function () {
        var input = field(form, 'title_format');
        var text = button.getAttribute('data-insert');
        var start = input.selectionStart == null ? input.value.length : input.selectionStart;
        var end = input.selectionEnd == null ? start : input.selectionEnd;
        input.value = input.value.slice(0, start) + text + input.value.slice(end);
        input.focus();
        input.setSelectionRange(start + text.length, start + text.length);
        draw();
      });
    });
    draw();
  }

  function social(root) {
    var form = root.closest('form');
    var site = root.getAttribute('data-site') || '';
    var tagline = root.getAttribute('data-tagline') || '';
    var hostName = host(root.getAttribute('data-url') || '');
    var brand = root.getAttribute('data-brand-image') || '';
    var card = root.querySelector('[data-ss-card]');
    var image = root.querySelector('[data-ss-image]');
    root.querySelector('[data-ss-host]').textContent = hostName;
    root.querySelector('[data-ss-title]').textContent = site;
    root.querySelector('[data-ss-desc]').textContent = tagline;

    function draw() {
      var src = value(form, 'share_image') || brand;
      image.style.backgroundImage = src ? 'url("' + src.replace(/["\\]/g, '') + '")' : '';
      image.classList.toggle('has-image', !!src);
      var x = checked(root, 'ss-net') === 'x';
      var mode = checked(form, 'twitter_card');
      var small = mode === 'summary' || (mode === 'auto' && !src);
      card.classList.toggle('is-x', x);
      card.classList.toggle('is-small', x && small);
    }
    form.addEventListener('input', draw);
    form.addEventListener('change', draw);
    draw();
  }

  function identity(root) {
    var form = root.closest('form');
    var parts = root.querySelectorAll('[data-when-type]');
    function draw() {
      var type = checked(form, 'identity_type');
      parts.forEach(function (part) { part.hidden = part.getAttribute('data-when-type') !== type; });
    }
    form.addEventListener('change', draw);
    draw();
  }

  document.querySelectorAll('[data-seo-preview="search"]').forEach(search);
  document.querySelectorAll('[data-seo-preview="social"]').forEach(social);
  document.querySelectorAll('[data-seo-identity]').forEach(identity);
})();
