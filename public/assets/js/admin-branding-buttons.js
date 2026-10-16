/*
 * Theme > Branding: the colours of Links and Button styles. Each colour is a list (the palette's roles, "Custom…") over one hidden
 * field that holds what is stored: a role name, a #rrggbb, or empty. This script keeps the three in step, tells the preview, and
 * draws a contrast badge for each pair: AA is 4.5:1, worked out from the colours the preview page really has (light and dark).
 * The form works without it (the hidden fields keep their values). The server uses a custom colour only while it passes AA.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-bp]');
  if (!root) { return; }
  var HEX = /^#([0-9a-f]{6})$/i;
  var AA = 4.5;
  // Where each role's colour is in the page's tokens: [light, dark].
  var TOKENS = {
    accent: ['--accent-l', '--accent-d'], on_accent: ['--accent-contrast-l', '--accent-contrast-d'], secondary: ['--secondary-l', '--secondary-d'],
    text: ['--n-text', '--d-text'], muted: ['--n-muted', '--d-muted'], background: ['--n-bg', '--d-bg'], surface: ['--n-surface', '--d-surface'],
    border: ['--n-border-strong', '--d-border-strong'], ink: ['--ink', '--ink']
  };
  var FALLBACK = {
    accent: ['#1e293b', '#e2e8f0'], on_accent: ['#ffffff', '#0b1220'], text: ['#0e1726', '#e8edf4'], muted: ['#4a5568', '#a6b3c5'],
    background: ['#ffffff', '#0b1220'], surface: ['#f5f6f8', '#111a2c'], border: ['#c3cad5', '#33445f'], ink: ['#0b1220', '#0b1220']
  };
  var STYLE_DEFAULT = { primary: ['accent', 'on_accent'], secondary: ['none', 'text'], text: ['none', 'accent'] };

  function fire(el) { el.dispatchEvent(new Event('input', { bubbles: true })); }
  function normalize(value) {
    value = String(value).trim().replace(/^#/, '');
    if (value.length === 3) { value = value.replace(/(.)/g, '$1$1'); }
    return '#' + value.toLowerCase();
  }
  function rgb(hex) { return [parseInt(hex.substr(1, 2), 16), parseInt(hex.substr(3, 2), 16), parseInt(hex.substr(5, 2), 16)]; }
  function luminance(hex) {
    var c = rgb(hex).map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }
  function ratio(a, b) { var x = luminance(a), y = luminance(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
  function mix(a, b, amount) {
    var x = rgb(a), y = rgb(b), out = '#';
    for (var i = 0; i < 3; i++) { out += ('0' + Math.round(x[i] + (y[i] - x[i]) * amount).toString(16)).slice(-2); }
    return out;
  }

  // ---- the colours the preview page has now
  function tokens() {
    var computed = null;
    try {
      var frame = root.querySelector('[data-bp-frame]');
      var doc = frame && frame.contentDocument;
      if (doc && doc.documentElement) { computed = frame.contentWindow.getComputedStyle(doc.documentElement); }
    } catch (e) { computed = null; }
    return function (role, mode) {
      var i = mode === 'dark' ? 1 : 0;
      if (role === 'white') { return '#ffffff'; }
      if (role === 'none') { return null; }
      var names = TOKENS[role === 'secondary' && !(computed && HEX.test((computed.getPropertyValue(TOKENS.secondary[i]) || '').trim())) ? 'accent' : role];
      var value = computed && names ? computed.getPropertyValue(names[i]).trim() : '';
      return HEX.test(value) ? value.toLowerCase() : ((FALLBACK[role] || FALLBACK.accent)[i]);
    };
  }

  function valueOf(box) { var input = box && box.querySelector('[data-tone-value]'); return input ? input.value : ''; }
  function colourOf(value, fallbackRole, mode, lookup) {
    var v = value || fallbackRole;
    if (!v) { return null; }
    if (HEX.test(v)) { return v.toLowerCase(); }
    return lookup(v, mode);
  }
  function badge(parts) {
    return parts.map(function (part) {
      var pass = part.ratio >= AA;
      return '<span class="bt-badge ' + (pass ? 'bt-pass' : 'bt-fail') + '" title="' + part.mode + ': ' + part.ratio.toFixed(1) + ':1, ' + (pass ? 'passes' : 'does not reach') + ' AA (4.5:1)">' +
        '<span class="bt-mode">' + (part.mode === 'light' ? 'L' : 'D') + '</span>' + part.ratio.toFixed(1) + (pass ? ' <span aria-hidden="true">✓</span><span class="sr-only">passes AA</span>' : ' <span aria-hidden="true">✗</span><span class="sr-only">fails AA</span>') + '</span>';
    }).join('');
  }

  function draw() {
    var lookup = tokens();
    // Links: the colour on the page, in each mode (the dark box that is empty follows the light one).
    root.querySelectorAll('[data-bt-kind="links"]').forEach(function (card) {
      var light = valueOf(card.querySelector('[data-tone-label="Link colour, light"]'));
      var dark = valueOf(card.querySelector('[data-tone-label="Link colour, dark"]')) || light;
      ['light', 'dark'].forEach(function (mode) {
        var target = card.querySelector('[data-bt-badge="link"][data-bt-mode="' + mode + '"]');
        var fg = colourOf(mode === 'light' ? light : dark, 'accent', mode, lookup);
        var bg = lookup('background', mode);
        target.innerHTML = fg && bg ? badge([{ mode: mode, ratio: ratio(fg, bg) }]) : '';
      });
    });
    // Buttons: the text on the background, normal and hover (an unset hover background is worked out as the server does).
    root.querySelectorAll('[data-bt-style]').forEach(function (section) {
      var style = section.getAttribute('data-bt-style');
      var get = function (name) {
        var input = section.querySelector('input[name$="[btn_' + style + '_' + name + ']"]');
        return input ? input.value : '';
      };
      var parts = { normal: [], hover: [] };
      ['light', 'dark'].forEach(function (mode) {
        var page = lookup('background', mode);
        var bgValue = get('bg');
        var bg = colourOf(bgValue, STYLE_DEFAULT[style][0], mode, lookup) || page;
        var text = colourOf(get('text'), STYLE_DEFAULT[style][1], mode, lookup) || page;
        parts.normal.push({ mode: mode, ratio: ratio(bg, text) });
        var hoverBg = get('hover_bg') ? colourOf(get('hover_bg'), '', mode, lookup) : (bgValue ? mix(bg, lookup('text', mode), 0.14) : (style === 'primary' ? lookup('accent', mode) : bg));
        var hoverText = colourOf(get('hover_text'), '', mode, lookup) || text;
        parts.hover.push({ mode: mode, ratio: ratio(hoverBg || page, hoverText) });
      });
      ['normal', 'hover'].forEach(function (state) {
        var target = section.querySelector('[data-bt-badge="button"][data-bt-state="' + state + '"]');
        if (target) { target.innerHTML = badge(parts[state]); }
      });
    });
    // A custom colour that fails is marked; the server leaves it out.
    root.querySelectorAll('[data-tone]').forEach(function (box) {
      var hex = box.querySelector('[data-tone-hex]');
      var custom = box.querySelector('[data-tone-value]').value;
      hex.setAttribute('aria-invalid', custom !== '' && !HEX.test(custom) && !box.querySelector('[data-tone-role]').value ? 'true' : 'false');
    });
  }

  // ---- the tone controls
  root.querySelectorAll('[data-tone]').forEach(function (box) {
    var select = box.querySelector('[data-tone-role]');
    var custom = box.querySelector('.bt-custom');
    var picker = box.querySelector('[data-tone-picker]');
    var hex = box.querySelector('[data-tone-hex]');
    var stored = box.querySelector('[data-tone-value]');
    function set(value) {
      if (stored.value === value) { return; }
      stored.value = value;
      fire(stored);
    }
    function fromSelect() {
      if (select.value === 'custom') {
        custom.hidden = false;
        var current = HEX.test(hex.value) ? normalize(hex.value) : normalize(picker.value);
        hex.value = current;
        set(current);
      } else {
        custom.hidden = true;
        set(select.value);
      }
      draw();
    }
    select.addEventListener('change', fromSelect);
    picker.addEventListener('input', function () { hex.value = picker.value; set(normalize(picker.value)); draw(); });
    hex.addEventListener('input', function () {
      if (/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.test(hex.value.trim())) { var value = normalize(hex.value); picker.value = value; set(value); draw(); }
    });
    box.bpTone = {
      reset: function () { select.value = ''; custom.hidden = true; hex.value = ''; set(''); }
    };
  });

  // ---- reset a card's colours (its choices are reset by admin-branding.js, which handles the same button)
  root.querySelectorAll('[data-bp-reset="links"], [data-bp-reset="button-styles"]').forEach(function (button) {
    button.addEventListener('click', function () {
      var card = button.closest('.bp-card');
      card.querySelectorAll('[data-tone]').forEach(function (box) { box.bpTone.reset(); });
      draw();
    });
  });

  root.addEventListener('preview:drawn', draw);
  draw();
})();
