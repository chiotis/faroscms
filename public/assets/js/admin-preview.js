/*
 * The live preview of the Theme screen: the real site in a frame beside the settings (Branding, Header, Footer), which follows
 * every choice that is not saved yet. The frame can be shown at a computer, tablet or phone width, in light or dark, and the
 * page can be reloaded. The settings are plain fields, so the form works without this script.
 *
 * Two ways to follow the choices, set by data-mode on the root ([data-preview]):
 *   css   (Branding) the unsaved choices go to the server (data-endpoint), which answers with the CSS and the attributes the
 *         pages would get; they are put into the frame's document at once, with no reload.
 *   page  (Header, Footer) the choices change what the page is made of, so the server draws the page that is in the frame
 *         with them (nothing is stored, no form is processed) and the frame shows that. Where it was scrolled is kept, and
 *         when the visitor follows a link inside the frame the next page is drawn the same way.
 */
(function () {
  'use strict';

  function init(root) {
    var form = root.closest('form');
    var frame = root.querySelector('[data-bp-frame]');
    var stage = root.querySelector('[data-bp-stage]');
    var endpoint = root.getAttribute('data-endpoint');
    var mode = root.getAttribute('data-mode') === 'page' ? 'page' : 'css';
    if (!form || !frame || !stage || !endpoint) { return; }

    var latest = null;       // css: the last answer of the server
    var dirty = false;       // page: a choice was changed, so what the frame shows must be drawn by the server
    var path = '';           // page: the address of the page in the frame
    var sequence = 0;
    var timer = null;
    var scrollTo = 0;
    var originals = typeof WeakMap === 'function' ? new WeakMap() : null;

    function frameDocument() {
      try { return frame.contentDocument; } catch (e) { return null; }
    }

    function selected(name) {
      var el = root.querySelector('input[name="' + name + '"]:checked');
      return el ? el.value : '';
    }

    function applyMode(doc) {
      var forced = selected('bp_mode');
      var attributes = latest ? latest.attributes : {};
      var colour = forced || attributes['data-mode'] || doc.documentElement.getAttribute('data-mode') || 'system';
      var dark = colour === 'dark' || (colour === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
      doc.documentElement.setAttribute('data-mode', colour);
      doc.documentElement.classList.toggle('dark', dark);
      doc.documentElement.classList.toggle('light', !dark);
    }

    function node(doc, tag, attributes, text) {
      var el = doc.createElement(tag);
      Object.keys(attributes || {}).forEach(function (key) { el.setAttribute(key, attributes[key]); });
      if (text) { el.textContent = text; }
      return el;
    }

    function applyBrand(doc) {
      var brand = doc.querySelector('.site-brand');
      if (!brand || !latest || !originals) { return; }
      if (!originals.has(brand)) { originals.set(brand, brand.innerHTML); }
      if (latest.logo) {
        brand.textContent = '';
        brand.appendChild(node(doc, 'img', { 'class': 'site-brand-logo' + (latest.logo_dark ? ' logo-on-light' : ''), alt: '', src: latest.logo }));
        if (latest.logo_dark) { brand.appendChild(node(doc, 'img', { 'class': 'site-brand-logo logo-on-dark', alt: '', src: latest.logo_dark })); }
        if (latest.show_name) { brand.appendChild(node(doc, 'span', { 'class': 'site-brand-title' }, latest.name)); }
      } else {
        brand.innerHTML = originals.get(brand);
      }
    }

    function formData() {
      var data = new FormData();
      new FormData(form).forEach(function (value, key) {
        if (key.indexOf('theme_settings[') === 0 || key === '_csrf') { data.append(key, value); }
      });
      return data;
    }

    function request() {
      var data = formData();
      if (mode === 'page') { data.append('preview_path', path); }
      var mine = ++sequence;
      fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (answer) {
          if (!answer || mine !== sequence) { return; }
          if (mode === 'page') {
            path = answer.path || path;
            try { scrollTo = frame.contentWindow.scrollY || 0; } catch (e) { scrollTo = 0; }
            frame.srcdoc = answer.html;
          } else {
            latest = answer;
            apply();
          }
        })
        .catch(function () { /* the preview is a convenience: a failed request leaves the last picture */ });
    }

    function apply() {
      var doc = frameDocument();
      if (!doc || !doc.documentElement || !doc.head) { return; }
      if (mode === 'css' && latest) {
        var style = doc.getElementById('faros-branding');
        if (!style) {
          style = doc.createElement('style');
          style.id = 'faros-branding';
          doc.head.appendChild(style);
        }
        style.textContent = latest.css;
        Object.keys(latest.attributes).forEach(function (key) { doc.documentElement.setAttribute(key, latest.attributes[key]); });
        applyBrand(doc);
      }
      applyMode(doc);
      // The page may have been drawn at another width: let it lay itself out again (sticky header, slider).
      try { frame.contentWindow.dispatchEvent(new Event('resize')); } catch (e) { /* the frame went somewhere else */ }
    }

    function onLoad() {
      var address = '';
      try { address = frame.contentWindow.location.href; } catch (e) { address = ''; }
      if (mode === 'page') {
        if (address && address.indexOf('about:') !== 0) {
          // The frame went to a page of the site (a link was followed, or Reload): that is the page to draw from now on.
          try { var url = new URL(address); path = url.pathname + url.search; } catch (e) { /* keep the last */ }
          if (dirty) { request(); return; }
        } else if (scrollTo > 0) {
          try { frame.contentWindow.scrollTo(0, scrollTo); } catch (e) { /* nothing to scroll */ }
        }
      }
      apply();
    }

    function schedule() {
      dirty = true;
      clearTimeout(timer);
      timer = setTimeout(request, mode === 'page' ? 400 : 250);
    }

    function relevant(event) {
      return event.target.name && event.target.name.indexOf('theme_settings[') === 0;
    }
    root.addEventListener('input', function (event) { if (relevant(event)) { schedule(); } });
    root.addEventListener('change', function (event) { if (relevant(event)) { schedule(); } });

    // The frame shows the page at a computer, tablet or phone width, scaled down to fit the space.
    function fit() {
      var width = parseInt(selected('bp_size'), 10) || 1280;
      var space = stage.clientWidth;
      var height = stage.clientHeight;
      if (!space || !height) { return; }
      var scale = Math.min(1, space / width);
      frame.style.width = width + 'px';
      frame.style.height = Math.ceil(height / scale) + 'px';
      frame.style.transform = 'scale(' + scale + ')';
      frame.style.left = Math.max(0, Math.floor((space - width * scale) / 2)) + 'px';
    }

    root.querySelectorAll('[data-bp-size]').forEach(function (input) { input.addEventListener('change', fit); });
    root.querySelectorAll('[data-bp-mode]').forEach(function (input) {
      input.addEventListener('change', function () { var doc = frameDocument(); if (doc && doc.documentElement) { applyMode(doc); } });
    });
    root.querySelector('[data-bp-reload]').addEventListener('click', function () {
      if (mode === 'page' && dirty) { request(); } else { frame.contentWindow.location.reload(); }
    });
    frame.addEventListener('load', onLoad);
    window.addEventListener('resize', fit);
    if (typeof ResizeObserver === 'function') { new ResizeObserver(fit).observe(stage); }
    fit();
  }

  document.querySelectorAll('[data-preview]').forEach(init);
})();
