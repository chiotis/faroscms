/*
 * Theme > Branding > Colour: the palette studio. It adds to the plain form (hidden fields hold the site palettes and the one in
 * use) a list of the palettes made on this site, a generator that makes a whole palette from two colours (the server does the
 * work: PaletteGenerator), the Duo colour pairings (fetched by the server when the picker is opened) and contrast feedback.
 * The live preview (admin-preview.js) follows because every change fires an input event on the hidden field.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-pl]');
  if (!root) { return; }
  var bp = root.closest('[data-bp]');
  var form = root.closest('form');
  var active = root.querySelector('[data-pl-active]');
  var store = root.querySelector('[data-pl-store]');
  var endpoint = root.getAttribute('data-pl-endpoint');
  var csrf = root.getAttribute('data-pl-csrf');
  var $ = function (name) { return root.querySelector('[data-pl-' + name + ']'); };
  var chips = $('chips'), none = $('none'), studio = $('studio'), edit = $('edit'), result = $('result');
  var HEX = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i;
  var ROLES = [['accent', 'Accent'], ['background', 'Background'], ['surface', 'Surface'], ['text', 'Text'], ['muted', 'Muted'], ['border', 'Border']];
  var palettes = [];
  var draft = null;       // the palette being made in the studio (id "draft"); it is in the preview but never saved
  var replaces = '';      // the id of the palette the draft will take the place of
  var sequence = 0;
  var timer = null;

  try { palettes = JSON.parse(store.value || '[]'); } catch (e) { palettes = []; }
  if (!Array.isArray(palettes)) { palettes = []; }

  // ---- colours and contrast (the same rules as Contrast.php)
  function norm(value) {
    value = String(value || '').trim().replace(/^#/, '');
    if (!HEX.test(value)) { return ''; }
    if (value.length === 3) { value = value.replace(/(.)/g, '$1$1'); }
    return '#' + value.toLowerCase();
  }
  function lum(hex) {
    var v = [1, 3, 5].map(function (i) {
      var c = parseInt(hex.substr(i, 2), 16) / 255;
      return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
  }
  function ratio(a, b) {
    var x = lum(a), y = lum(b);
    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
  }
  function level(r) {
    r = Math.round(r * 100) / 100;
    return r >= 7 ? 'AAA' : r >= 4.5 ? 'AA' : r >= 3 ? 'AA large' : 'fail';
  }
  var WORDS = { 'AAA': 'AAA', 'AA': 'AA', 'AA large': 'Large text only', 'fail': 'Fails AA' };
  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) { node.className = cls; }
    if (text != null) { node.textContent = text; }
    return node;
  }
  function badge(a, b) {
    var r = ratio(a, b);
    var l = level(r);
    var node = el('span', 'pl-badge', (Math.round(r * 10) / 10).toFixed(1) + ' ' + WORDS[l]);
    node.setAttribute('data-level', l);
    node.title = 'Contrast ' + r.toFixed(2) + ':1';
    return node;
  }
  function fire(node) { node.dispatchEvent(new Event('input', { bubbles: true })); }

  // ---- the stored state
  function persist() {
    var all = palettes.slice();
    if (draft) { all.push(draft); }
    store.value = all.length ? JSON.stringify(all) : '';
    fire(store);
  }
  function setActive(id) {
    active.value = id;
    fire(active);
    var builtin = bp ? bp.querySelector('.bp-pal') : null;
    if (builtin) { builtin.classList.toggle('pl-off', id !== ''); }
  }
  function find(id) {
    if (draft && id === 'draft') { return draft; }
    for (var i = 0; i < palettes.length; i++) { if (palettes[i].id === id) { return palettes[i]; } }
    return null;
  }
  function slug(name) {
    var base = String(name).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 24) || 'palette';
    var id = base, n = 1;
    while (palettes.some(function (p) { return p.id === id; }) || id === 'draft') { n++; id = base + '-' + n; }
    return id;
  }
  // The draft never goes to the server with the form.
  form.addEventListener('submit', function () {
    if (draft) {
      draft = null;
      if (active.value === 'draft') { active.value = replaces || ''; }
      store.value = palettes.length ? JSON.stringify(palettes) : '';
    }
  });

  // ---- the chips and the editor of the palette in use
  function render() {
    chips.textContent = '';
    var all = palettes.slice();
    if (draft) { all.push(draft); }
    all.forEach(function (p) {
      var on = active.value === p.id;
      var b = el('button', 'pl-chip');
      b.type = 'button';
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.setAttribute('data-id', p.id);
      var dots = el('span', 'pl-dots');
      dots.setAttribute('aria-hidden', 'true');
      [p.light && p.light.background, p.light && p.light.accent, p.dark && p.dark.background, p.dark && p.dark.accent].forEach(function (c) {
        var d = el('i'); d.style.background = c || '#94a3b8'; dots.appendChild(d);
      });
      b.appendChild(dots);
      b.appendChild(el('span', 'pl-chip-name', p.name + (p.id === 'draft' ? ' (not saved yet)' : '')));
      b.addEventListener('click', function () {
        if (p.id === 'draft') { return; }
        setActive(on ? '' : p.id);
        render();
      });
      chips.appendChild(b);
    });
    none.hidden = all.length > 0;
    var current = find(active.value);
    var editing = current && current.id !== 'draft' ? current : null;
    edit.hidden = !editing || !!draft;
    if (editing && !draft) { drawEditor(editing); }
  }

  function colourBox(label, value, optional, onChange) {
    var box = el('span', 'lc-colour');
    var picker = el('input'); picker.type = 'color'; picker.tabIndex = -1; picker.setAttribute('aria-label', label + ', pick a colour');
    var text = el('input', 'lc-input'); text.type = 'text'; text.maxLength = 7; text.spellcheck = false; text.autocomplete = 'off';
    text.setAttribute('aria-label', label + ', hex code'); text.placeholder = optional ? 'None' : '';
    picker.value = norm(value) || '#ffffff'; text.value = value || '';
    picker.addEventListener('input', function () { text.value = picker.value; onChange(norm(text.value)); });
    text.addEventListener('input', function () {
      var c = norm(text.value);
      if (c) { picker.value = c; onChange(c); } else if (optional && text.value.trim() === '') { onChange(''); }
    });
    box.appendChild(picker); box.appendChild(text);
    return box;
  }

  function drawEditor(p) {
    var body = edit.querySelector('tbody');
    body.textContent = '';
    var rename = $('rename');
    if (document.activeElement !== rename) { rename.value = p.name; }
    var rows = ROLES.map(function (r) { return { key: r[0], label: r[1] }; });
    rows.forEach(function (row) {
      var tr = el('tr');
      var th = el('th', null, row.label); th.scope = 'row'; tr.appendChild(th);
      ['light', 'dark'].forEach(function (mode) {
        var td = el('td');
        var value = (p[mode] || {})[row.key] || '';
        var badgeHost = el('span', 'pl-badge-host');
        var paint = function () {
          badgeHost.textContent = '';
          var c = (p[mode] || {})[row.key], bg = (p[mode] || {}).background;
          if (c && bg && (row.key === 'accent' || row.key === 'text' || row.key === 'muted')) { badgeHost.appendChild(badge(c, bg)); }
        };
        td.appendChild(colourBox(row.label + ', ' + mode, value, false, function (c) {
          if (!c) { return; }
          p[mode] = p[mode] || {}; p[mode][row.key] = c;
          persist(); paint();
          if (row.key === 'background') { drawEditorBadges(); }
        }));
        td.appendChild(badgeHost);
        badgeHost.setAttribute('data-role', row.key); badgeHost.setAttribute('data-mode', mode);
        tr.appendChild(td);
        paint();
      });
      body.appendChild(tr);
    });
    // Secondary: optional, one colour for each mode.
    var trs = el('tr');
    var ths = el('th', null, 'Secondary'); ths.scope = 'row'; trs.appendChild(ths);
    ['light', 'dark'].forEach(function (mode) {
      var td = el('td');
      var host = el('span', 'pl-badge-host');
      var paintS = function () {
        host.textContent = '';
        var c = (p.secondary || {})[mode], bg = (p[mode] || {}).background;
        if (c && bg) { host.appendChild(badge(c, bg)); }
      };
      td.appendChild(colourBox('Secondary, ' + mode, (p.secondary || {})[mode] || '', true, function (c) {
        p.secondary = p.secondary || {};
        if (c) { p.secondary[mode] = c; } else { delete p.secondary[mode]; }
        if (!Object.keys(p.secondary).length) { delete p.secondary; }
        persist(); paintS();
      }));
      td.appendChild(host); paintS(); trs.appendChild(td);
    });
    body.appendChild(trs);
    var tri = el('tr');
    var thi = el('th', null, 'Dark panels'); thi.scope = 'row'; tri.appendChild(thi);
    var tdi = el('td'); tdi.colSpan = 2;
    tdi.appendChild(colourBox('Dark panels and footer', p.ink || '', true, function (c) { if (c) { p.ink = c; } else { delete p.ink; } persist(); }));
    tri.appendChild(tdi); body.appendChild(tri);
    $('regen').hidden = !p.from;
  }
  function drawEditorBadges() {
    var p = find(active.value);
    if (p) { drawEditor(p); }
  }

  // ---- the studio
  function box(name) { return studio.querySelector('input[name="' + name + '"]'); }
  var inputs = { accent: box('pl_accent'), base: box('pl_base'), secondary: box('pl_secondary') };
  inputs.secondary.placeholder = 'None';
  // The generator's boxes are not part of the form that is saved.
  Object.keys(inputs).forEach(function (k) { inputs[k].removeAttribute('name'); });
  var cleared = studio.querySelectorAll('.lc-colour-x');
  function role() { var r = studio.querySelector('[data-pl-role]:checked'); return r ? r.value : 'background'; }
  function setBox(input, value) {
    input.value = value || '';
    var c = norm(value);
    var picker = input.parentNode.querySelector('input[type="color"]');
    if (c && picker) { picker.value = c; }
    input.parentNode.toggleAttribute('data-empty', !c);
  }
  function effective(name) {
    var field = form.querySelector('[name="theme_settings[design][' + name + ']"]');
    return field ? (norm(field.value) || norm(field.placeholder)) : '';
  }
  function openStudio(values) {
    studio.hidden = false;
    $('new').setAttribute('aria-expanded', 'true');
    var a = values && values.accent || norm(inputs.accent.value) || effective('light_accent') || '#4338ca';
    var b = values && values.base || norm(inputs.base.value) || effective('light_background') || '#ffffff';
    setBox(inputs.accent, a); setBox(inputs.base, b);
    if (values && values.secondary !== undefined) { setBox(inputs.secondary, values.secondary); }
    if (values && values.role) { studio.querySelectorAll('[data-pl-role]').forEach(function (r) { r.checked = r.value === values.role; }); }
    generate(true);
  }
  function closeStudio() {
    studio.hidden = true;
    $('new').setAttribute('aria-expanded', 'false');
  }
  function schedule() { clearTimeout(timer); timer = setTimeout(function () { generate(false); }, 220); }
  [inputs.accent, inputs.base, inputs.secondary].forEach(function (i) { i.addEventListener('input', schedule); });
  cleared.forEach(function (x) { x.addEventListener('click', function () { setTimeout(schedule, 0); }); });
  studio.querySelectorAll('[data-pl-role]').forEach(function (r) { r.addEventListener('change', schedule); });
  $('swap').addEventListener('click', function () {
    var a = inputs.accent.value, b = inputs.base.value;
    setBox(inputs.accent, b); setBox(inputs.base, a);
    generate(true);
  });
  $('new').addEventListener('click', function () {
    if (!studio.hidden) { closeStudio(); return; }
    replaces = '';
    openStudio();
  });
  $('cancel').addEventListener('click', function () {
    draft = null;
    if (active.value === 'draft') { setActive(replaces); }
    replaces = '';
    closeStudio(); persist(); render(); drawResult();
  });
  $('name').addEventListener('input', function () { if (draft) { draft.name = $('name').value.trim() || 'New palette'; persist(); render(); } });
  $('save').addEventListener('click', function () {
    if (!draft) { return; }
    var name = $('name').value.trim() || ('Palette ' + (palettes.length + 1));
    var saved = JSON.parse(JSON.stringify(draft));
    saved.name = name.slice(0, 40);
    if (replaces && find(replaces)) {
      saved.id = replaces;
      palettes = palettes.map(function (p) { return p.id === replaces ? saved : p; });
    } else {
      saved.id = slug(name);
      palettes.push(saved);
    }
    draft = null; replaces = '';
    closeStudio();
    setActive(saved.id);
    persist(); render();
  });

  function generate(now) {
    var accent = norm(inputs.accent.value), base = norm(inputs.base.value);
    if (!accent || !base) { return; }
    var data = new FormData();
    data.append('_csrf', csrf); data.append('accent', accent); data.append('base', base); data.append('base_role', role());
    data.append('secondary', norm(inputs.secondary.value));
    var mine = ++sequence;
    fetch(endpoint + '?palette=generate', { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (a) {
        if (!a || mine !== sequence) { return; }
        if (!a.ok) { result.textContent = a.message || ''; return; }
        var keep = $('name').value.trim();
        draft = { id: 'draft', name: keep || 'New palette', light: a.light, dark: a.dark, ink: a.ink, from: { accent: accent, base: base, role: role() } };
        if (a.secondary) { draft.secondary = a.secondary; }
        if (!replaces && active.value !== 'draft') { replaces = active.value && active.value !== 'draft' ? active.value : ''; }
        active.value = 'draft';
        var builtin = bp ? bp.querySelector('.bp-pal') : null;
        if (builtin) { builtin.classList.add('pl-off'); }
        persist(); render(); drawResult(a);
      })
      .catch(function () { /* the studio is a convenience: the form still works without it */ });
  }

  function drawResult(a) {
    result.textContent = '';
    if (!draft) { return; }
    var wrap = el('div', 'pl-modes');
    ['light', 'dark'].forEach(function (mode) {
      var c = draft[mode];
      var card = el('div', 'pl-mode');
      card.style.background = c.background; card.style.color = c.text; card.style.borderColor = c.border;
      card.appendChild(el('strong', 'pl-mode-title', mode === 'light' ? 'Light' : 'Dark'));
      var sample = el('div', 'pl-sample');
      sample.style.background = c.surface; sample.style.borderColor = c.border;
      sample.appendChild(el('span', null, 'Text on a surface'));
      var muted = el('span', null, 'Muted text'); muted.style.color = c.muted; sample.appendChild(muted);
      var link = el('span', null, 'A link'); link.style.color = c.accent; link.style.textDecoration = 'underline'; sample.appendChild(link);
      card.appendChild(sample);
      var chipsRow = el('div', 'pl-swatches');
      ROLES.forEach(function (r) {
        var s = el('span', 'pl-sw'); s.style.background = c[r[0]];
        s.title = r[1] + ' ' + c[r[0]];
        var hex = el('span', 'pl-sw-hex', c[r[0]]); hex.style.color = ratio(c[r[0]], '#ffffff') >= ratio(c[r[0]], '#0b1220') ? '#ffffff' : '#0b1220';
        s.appendChild(hex); chipsRow.appendChild(s);
      });
      card.appendChild(chipsRow);
      var ratios = el('div', 'pl-ratios');
      [['Text', c.text, c.background], ['Surface', c.text, c.surface], ['Muted', c.muted, c.background], ['Link', c.accent, c.background]].forEach(function (t) {
        var row = el('span', 'pl-ratio'); row.appendChild(el('span', null, t[0] + ' ')); row.appendChild(badge(t[1], t[2])); ratios.appendChild(row);
      });
      var best = ratio('#ffffff', c.accent) >= ratio('#0b1220', c.accent) ? '#ffffff' : '#0b1220';
      var rowA = el('span', 'pl-ratio'); rowA.appendChild(el('span', null, 'On accent ')); rowA.appendChild(badge(best, c.accent)); ratios.appendChild(rowA);
      card.appendChild(ratios);
      wrap.appendChild(card);
    });
    result.appendChild(wrap);
  }

  // ---- edit, rename, delete
  $('rename').addEventListener('input', function () {
    var p = find(active.value);
    if (!p || p.id === 'draft') { return; }
    p.name = $('rename').value.trim().slice(0, 40) || 'Palette';
    persist();
    var chip = chips.querySelector('[data-id="' + p.id + '"] .pl-chip-name');
    if (chip) { chip.textContent = p.name; }
  });
  $('delete').addEventListener('click', function () {
    var p = find(active.value);
    if (!p || p.id === 'draft' || !window.confirm('Delete the palette “' + p.name + '”?')) { return; }
    palettes = palettes.filter(function (x) { return x.id !== p.id; });
    setActive('');
    persist(); render();
  });
  $('regen').addEventListener('click', function () {
    var p = find(active.value);
    if (!p || !p.from) { return; }
    replaces = p.id;
    $('name').value = p.name;
    openStudio({ accent: p.from.accent, base: p.from.base, role: p.from.role, secondary: (p.secondary && p.secondary.light) || '' });
  });

  // A ready-made palette chosen beside them puts the site palette aside.
  if (bp) {
    bp.querySelectorAll('input[name="theme_settings[appearance][palette]"]').forEach(function (r) {
      r.addEventListener('change', function () { if (active.value !== '') { setActive(''); render(); } });
    });
  }

  // ---- the two disclosures (buttons, not nested details, so the cards stay plain)
  root.querySelectorAll('[data-pl-disc]').forEach(function (button) {
    button.addEventListener('click', function () {
      var open = button.getAttribute('aria-expanded') !== 'true';
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      document.getElementById(button.getAttribute('aria-controls')).hidden = !open;
    });
  });

  // ---- Duo
  var duoBox = $('duo-box');
  var duoLoaded = false;
  if (duoBox) {
    var grid = $('grid'), note = $('duo-note'), aa = $('aa');
    var pairs = [];
    var drawPairs = function () {
      grid.textContent = '';
      pairs.forEach(function (pair) {
        if (aa.checked && !pair.aa) { return; }
        var a = pair.colors[0], b = pair.colors[1];
        var r = ratio(a, b);
        var btn = el('button', 'pl-pair-btn');
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Use ' + a + ' and ' + b + '. Contrast ' + r.toFixed(1) + ' to 1, ' + WORDS[level(r)] + (pair.aa ? '. Marked AA by Duo' : ''));
        var sw = el('span', 'pl-pair-sw'); sw.setAttribute('aria-hidden', 'true');
        var s1 = el('i'); s1.style.background = a; var s2 = el('i'); s2.style.background = b;
        sw.appendChild(s1); sw.appendChild(s2); btn.appendChild(sw);
        var meta = el('span', 'pl-pair-meta'); meta.setAttribute('aria-hidden', 'true');
        meta.appendChild(badge(a, b)); btn.appendChild(meta);
        btn.addEventListener('click', function () {
          if (!draft) { replaces = ''; }
          openStudio({ accent: a, base: b, role: 'background' });
          studio.scrollIntoView({ block: 'nearest' });
          inputs.accent.focus();
        });
        grid.appendChild(btn);
      });
      note.textContent = grid.children.length ? 'Pick a pair to fill the generator: the first colour is the accent, the second the base.' : 'No pairs match.';
    };
    aa.addEventListener('change', drawPairs);
    var duoButton = duoBox.querySelector('[data-pl-disc]');
    duoButton.addEventListener('click', function () {
      if (duoButton.getAttribute('aria-expanded') !== 'true' || duoLoaded) { return; }
      duoLoaded = true;
      note.textContent = 'Loading…';
      fetch(endpoint + '?palette=duo', { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (a) {
          if (!a || !a.ok || !Array.isArray(a.pairs)) {
            grid.hidden = true; aa.parentNode.hidden = true;
            note.textContent = (a && a.message) || 'Colour pairings are not available right now.';
            return;
          }
          pairs = a.pairs; drawPairs();
        })
        .catch(function () { grid.hidden = true; aa.parentNode.hidden = true; note.textContent = 'Colour pairings are not available right now.'; });
    });
  }

  // ---- contrast of what the preview shows (so an inherited colour is shown as the colour it gets)
  var contrastHost = $('contrast-rows'), contrastNote = $('contrast-note');
  var CHECKS = {
    light: [['Text on background', '--n-text', '--n-bg'], ['Text on surface', '--n-text', '--n-surface'], ['Muted text on background', '--n-muted', '--n-bg'], ['Accent text', '--accent-contrast-l', '--accent-l'], ['Links', '--accent-l', '--n-bg'], ['Secondary text', '--secondary-contrast-l', '--secondary-l'], ['Secondary on background', '--secondary-l', '--n-bg']],
    dark: [['Text on background', '--d-text', '--d-bg'], ['Text on surface', '--d-text', '--d-surface'], ['Muted text on background', '--d-muted', '--d-bg'], ['Accent text', '--accent-contrast-d', '--accent-d'], ['Links', '--accent-d', '--d-bg'], ['Secondary text', '--secondary-contrast-d', '--secondary-d'], ['Secondary on background', '--secondary-d', '--d-bg']]
  };
  function drawContrast() {
    var frame = bp ? bp.querySelector('[data-bp-frame]') : null;
    var computed;
    try { computed = frame && frame.contentDocument && frame.contentDocument.documentElement ? frame.contentWindow.getComputedStyle(frame.contentDocument.documentElement) : null; } catch (e) { computed = null; }
    if (!computed) { return; }
    contrastHost.textContent = '';
    var shown = 0;
    ['light', 'dark'].forEach(function (mode) {
      var col = el('div', 'pl-col');
      col.appendChild(el('h4', 'pl-col-title', mode === 'light' ? 'Light mode' : 'Dark mode'));
      var list = el('ul', 'pl-list');
      CHECKS[mode].forEach(function (c) {
        var fg = norm(computed.getPropertyValue(c[1]).trim()), bgc = norm(computed.getPropertyValue(c[2]).trim());
        if (!fg || !bgc) { return; }
        shown++;
        var li = el('li', 'pl-item');
        var sample = el('span', 'pl-aa-sample', 'Aa'); sample.style.color = fg; sample.style.background = bgc; sample.setAttribute('aria-hidden', 'true');
        li.appendChild(sample);
        var label = el('span', 'pl-item-label'); label.appendChild(el('span', null, c[0])); label.appendChild(el('small', null, fg + ' on ' + bgc));
        li.appendChild(label); li.appendChild(badge(fg, bgc));
        list.appendChild(li);
      });
      col.appendChild(list);
      contrastHost.appendChild(col);
    });
    contrastNote.textContent = shown ? 'The colours the site gets now, including those that come from the palette.' : 'The preview has not been drawn yet.';
  }
  if (bp) { bp.addEventListener('preview:drawn', drawContrast); }
  var frameNode = bp ? bp.querySelector('[data-bp-frame]') : null;
  if (frameNode) { frameNode.addEventListener('load', function () { setTimeout(drawContrast, 50); }); }

  // ---- first draw
  if (active.value !== '' && !find(active.value)) { active.value = ''; }
  var builtinGroup = bp ? bp.querySelector('.bp-pal') : null;
  if (builtinGroup) { builtinGroup.classList.toggle('pl-off', active.value !== ''); }
  render();
  drawContrast();
})();
