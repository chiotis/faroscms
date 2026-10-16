/*
 * Theme > Branding > Colour: the palette, the one place where the colours of the site are set. The plain form holds the ready-made
 * palettes (radio buttons) and two hidden fields (the palettes made on this site, as JSON, and the one in use). This script draws
 * on top of them:
 *   - one row of chips (a radio group): the ready-made palettes, the palettes of the site, and "+ Custom";
 *   - a ready-made palette read-only, with Customise (a copy that becomes a palette of the site);
 *   - the editor of a palette of the site: every colour role for light and dark, rename, delete;
 *   - "+ Custom": a palette from two colours (the server does the work: PaletteGenerator), from a Duo pair, or a copy of a
 *     ready-made one;
 *   - the contrast panel, with Fix: the nearest shade that reaches AA (the server does the work: Contrast::fix).
 * The live preview (admin-preview.js) follows because every change fires an input event on a field of the form.
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
  var DEFAULT = root.getAttribute('data-pl-default') || 'slate';
  var $ = function (name) { return root.querySelector('[data-pl-' + name + ']'); };
  var view = function (name) { return root.querySelector('[data-pl-view="' + name + '"]'); };
  var chips = $('chips'), studio = view('create'), editView = view('edit'), builtinView = view('builtin'), result = $('result');
  var HEX = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i;
  var ROLES = [['accent', 'Accent'], ['background', 'Background'], ['surface', 'Surface'], ['text', 'Text'], ['muted', 'Muted text'], ['border', 'Border']];
  var TOKENS = {
    light: { accent: '--accent-l', background: '--n-bg', surface: '--n-surface', text: '--n-text', muted: '--n-muted', border: '--n-border', secondary: '--secondary-l', ink: '--ink' },
    dark: { accent: '--accent-d', background: '--d-bg', surface: '--d-surface', text: '--d-text', muted: '--d-muted', border: '--d-border', secondary: '--secondary-d', ink: '--ink' }
  };
  var BUILTIN = {};
  try { BUILTIN = JSON.parse(root.getAttribute('data-pl-builtin') || '{}') || {}; } catch (e) { BUILTIN = {}; }
  if (Array.isArray(BUILTIN)) { BUILTIN = {}; }
  var palettes = [];
  var draft = null;       // the palette being made in "+ Custom" (id "draft"); it is in the preview but never saved
  var replaces = '';      // the id of the palette the draft will take the place of
  var before = '';        // what was in use when "+ Custom" was opened
  var creating = false;
  var sequence = 0;
  var timer = null;
  var refocus = '';

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
  function ink(hex) { return ratio(hex, '#ffffff') >= ratio(hex, '#0b1220') ? '#ffffff' : '#0b1220'; }
  function post(what, fields) {
    var data = new FormData();
    data.append('_csrf', csrf);
    Object.keys(fields).forEach(function (k) {
      [].concat(fields[k]).forEach(function (v) { data.append(Array.isArray(fields[k]) ? k + '[]' : k, v); });
    });
    return fetch(endpoint + '?palette=' + what, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.ok ? r.json() : null; });
  }

  // ---- the ready-made palettes are the radio buttons of the form
  var nativeBox = $('native');
  var radios = nativeBox ? [].slice.call(nativeBox.querySelectorAll('input[type="radio"]')) : [];
  var builtins = radios.map(function (r) {
    var label = r.closest('label');
    var dot = label && label.querySelector('i');
    return { id: r.value, name: ((label && label.textContent) || r.value).trim(), dot: dot ? dot.style.background : '#64748b', radio: r };
  });
  function builtin(id) { return builtins.filter(function (b) { return b.id === id; })[0] || null; }
  function currentBuiltin() {
    var on = radios.filter(function (r) { return r.checked; })[0];
    return on ? on.value : DEFAULT;
  }
  // Checks a ready-made palette in the form; "loud" also tells the preview.
  function setBuiltin(id, loud) {
    var b = builtin(id);
    if (!b || b.radio.checked) { return; }
    b.radio.checked = true;
    if (loud) { b.radio.dispatchEvent(new Event('input', { bubbles: true })); b.radio.dispatchEvent(new Event('change', { bubbles: true })); }
  }
  if (nativeBox) { nativeBox.hidden = true; }

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
      if (active.value === 'draft') { active.value = replaces || before || ''; }
      store.value = palettes.length ? JSON.stringify(palettes) : '';
    }
  });

  // ---- what the preview gives a role now (a colour the palette leaves out is the ready-made palette's)
  function computed() {
    var frame = bp ? bp.querySelector('[data-bp-frame]') : null;
    try { return frame && frame.contentDocument && frame.contentDocument.documentElement ? frame.contentWindow.getComputedStyle(frame.contentDocument.documentElement) : null; } catch (e) { return null; }
  }
  function inherited(mode, role) {
    var style = computed();
    return style ? norm(style.getPropertyValue(TOKENS[mode][role]).trim()) : '';
  }

  // ---- which view is open
  function select(kind, id) {
    if (draft) { discardDraft(); }
    creating = false;
    refocus = id;
    if (kind === 'builtin') {
      setActive('');
      setBuiltin(id, true);
    } else {
      var p = find(id);
      if (p && p.source && builtin(p.source)) { setBuiltin(p.source, true); }
      setActive(id);
    }
    render();
  }
  function discardDraft() {
    var wasEditing = replaces;
    draft = null;
    if (active.value === 'draft') { active.value = wasEditing || before || ''; fire(active); }
    replaces = '';
    persist();
  }

  // ---- the chips
  function dots(light, dark, fallback) {
    var d = el('span', 'pl-dots');
    d.setAttribute('aria-hidden', 'true');
    var list = light ? [light.background, light.accent, dark && dark.background, dark && dark.accent] : [fallback];
    list.forEach(function (c) { var i = el('i'); i.style.background = c || '#94a3b8'; d.appendChild(i); });
    return d;
  }
  function chip(kind, id, name, light, dark, fallback, on) {
    var b = el('button', 'pl-chip');
    b.type = 'button';
    b.setAttribute('role', 'radio');
    b.setAttribute('aria-checked', on ? 'true' : 'false');
    b.tabIndex = on ? 0 : -1;
    b.setAttribute('data-id', id);
    b.setAttribute('data-kind', kind);
    b.appendChild(dots(light, dark, fallback));
    b.appendChild(el('span', 'pl-chip-name', name));
    b.addEventListener('click', function () { if (id !== 'draft') { select(kind, id); } });
    return b;
  }
  function selection() {
    if (active.value !== '' && find(active.value)) { return { kind: draft && active.value === 'draft' ? 'draft' : 'site', id: active.value }; }
    return { kind: 'builtin', id: currentBuiltin() };
  }
  function drawChips() {
    var now = selection();
    chips.textContent = '';
    builtins.forEach(function (b) {
      var data = BUILTIN[b.id];
      chips.appendChild(chip('builtin', b.id, b.name.replace(/ \(.*$/, ''), data && data.light, data && data.dark, b.dot, now.kind === 'builtin' && now.id === b.id));
    });
    var all = palettes.slice();
    if (draft) { all.push(draft); }
    all.forEach(function (p) {
      chips.appendChild(chip(p.id === 'draft' ? 'draft' : 'site', p.id, p.name + (p.id === 'draft' ? ' (not saved yet)' : ''), p.light, p.dark, '', now.kind !== 'builtin' && now.id === p.id));
    });
    if (!chips.querySelector('[tabindex="0"]')) { var first = chips.querySelector('.pl-chip'); if (first) { first.tabIndex = 0; } }
    var focus = refocus && chips.querySelector('[data-id="' + refocus + '"]');
    if (focus) { focus.focus(); }
    refocus = '';
    $('new').setAttribute('aria-expanded', creating ? 'true' : 'false');
  }
  // Arrow keys move through the group and choose, like any radio group.
  chips.addEventListener('keydown', function (event) {
    var keys = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1, Home: 'first', End: 'last' };
    if (!(event.key in keys)) { return; }
    var list = [].slice.call(chips.querySelectorAll('.pl-chip'));
    var at = list.indexOf(document.activeElement);
    if (at < 0) { return; }
    var next = keys[event.key] === 'first' ? 0 : keys[event.key] === 'last' ? list.length - 1 : (at + keys[event.key] + list.length) % list.length;
    event.preventDefault();
    var target = list[next];
    if (target.getAttribute('data-kind') === 'draft') { target.focus(); return; }
    select(target.getAttribute('data-kind'), target.getAttribute('data-id'));
  });

  function render() {
    drawChips();
    var now = selection();
    builtinView.hidden = creating || now.kind !== 'builtin';
    studio.hidden = !creating;
    var editing = !creating && now.kind === 'site' ? find(now.id) : null;
    editView.hidden = !editing;
    if (!builtinView.hidden) { drawBuiltin(now.id); }
    if (editing) { drawEditor(editing); }
    drawContrast();
  }

  // ---- a ready-made palette, read-only
  function swatches(colours, ink) {
    var list = el('ul', 'pl-ro-list');
    ROLES.concat(ink ? [['ink', 'Dark panels']] : []).forEach(function (r) {
      var hex = r[0] === 'ink' ? ink : colours[r[0]];
      if (!hex) { return; }
      var li = el('li', 'pl-ro-item');
      var sw = el('span', 'pl-ro-sw'); sw.style.background = hex; sw.setAttribute('aria-hidden', 'true');
      li.appendChild(sw);
      var text = el('span', 'pl-ro-text');
      text.appendChild(el('span', null, r[1]));
      text.appendChild(el('code', null, hex));
      li.appendChild(text);
      list.appendChild(li);
    });
    return list;
  }
  function drawBuiltin(id) {
    var data = BUILTIN[id];
    var b = builtin(id);
    var host = $('ro');
    host.textContent = '';
    $('builtin-note').textContent = (b ? b.name.replace(/ \(.*$/, '') : id) + ' is a ready-made palette and cannot be changed. Customise makes a copy of it that you can.';
    $('customise').hidden = !data;
    if (!data) { return; }
    [['Light', data.light, ''], ['Dark', data.dark, data.ink]].forEach(function (m) {
      var group = el('div', 'pl-ro-mode');
      group.setAttribute('role', 'group');
      group.setAttribute('aria-label', m[0] + ' colours of ' + data.name);
      group.appendChild(el('h4', 'pl-col-title', m[0]));
      group.appendChild(swatches(m[1], m[2]));
      host.appendChild(group);
    });
  }
  $('customise').addEventListener('click', function () { copyOf(selection().id); });
  function copyOf(id) {
    post('copy', { builtin: id, taken: palettes.map(function (p) { return p.id; }) }).then(function (a) {
      if (!a || !a.ok || !a.palette) { return; }
      var p = a.palette;
      if (draft) { discardDraft(); }
      creating = false;
      palettes.push(p);
      setBuiltin(p.source, true);
      setActive(p.id);
      persist();
      refocus = '';
      render();
      var rename = $('rename');
      rename.focus(); rename.select();
    }).catch(function () { /* the form still works without it */ });
  }

  // ---- the editor of a palette of the site
  function colourBox(label, value, placeholder, onChange) {
    var box = el('span', 'lc-colour');
    var picker = el('input'); picker.type = 'color'; picker.tabIndex = -1; picker.setAttribute('aria-label', label + ', pick a colour');
    var text = el('input', 'lc-input'); text.type = 'text'; text.maxLength = 7; text.spellcheck = false; text.autocomplete = 'off';
    text.setAttribute('aria-label', label + ', hex code'); text.placeholder = placeholder || '';
    picker.value = norm(value) || norm(placeholder) || '#ffffff'; text.value = value || '';
    picker.addEventListener('input', function () { text.value = picker.value; onChange(norm(text.value)); });
    text.addEventListener('input', function () {
      var c = norm(text.value);
      if (c) { picker.value = c; onChange(c); } else if (text.value.trim() === '') { onChange(''); }
    });
    box.appendChild(picker); box.appendChild(text);
    return box;
  }
  function setRole(p, mode, role, hex) {
    if (role === 'secondary') {
      p.secondary = p.secondary || {};
      if (hex) { p.secondary[mode] = hex; } else { delete p.secondary[mode]; }
      if (!Object.keys(p.secondary).length) { delete p.secondary; }
    } else {
      p[mode] = p[mode] || {};
      if (hex) { p[mode][role] = hex; } else { delete p[mode][role]; }
    }
  }
  function getRole(p, mode, role) {
    return role === 'secondary' ? ((p.secondary || {})[mode] || '') : ((p[mode] || {})[role] || '');
  }
  function drawEditor(p) {
    var body = editView.querySelector('tbody');
    body.textContent = '';
    var rename = $('rename');
    if (document.activeElement !== rename) { rename.value = p.name; }
    ROLES.concat([['secondary', 'Secondary']]).forEach(function (row) {
      var tr = el('tr');
      var th = el('th', null, row[1]); th.scope = 'row'; tr.appendChild(th);
      ['light', 'dark'].forEach(function (mode) {
        var td = el('td');
        var host = el('span', 'pl-badge-host');
        var paint = function () {
          host.textContent = '';
          var c = getRole(p, mode, row[0]) || inherited(mode, row[0]), bg = getRole(p, mode, 'background') || inherited(mode, 'background');
          if (c && bg && ['accent', 'text', 'muted', 'secondary'].indexOf(row[0]) !== -1) { host.appendChild(badge(c, bg)); }
        };
        td.appendChild(colourBox(row[1] + ', ' + mode, getRole(p, mode, row[0]), row[0] === 'secondary' ? 'None' : (inherited(mode, row[0]) || 'Theme'), function (c) {
          setRole(p, mode, row[0], c);
          persist(); paint();
          if (row[0] === 'background') { drawEditor(p); }
        }));
        td.appendChild(host); paint(); tr.appendChild(td);
      });
      body.appendChild(tr);
    });
    var tri = el('tr');
    var thi = el('th', null, 'Dark panels'); thi.scope = 'row'; tri.appendChild(thi);
    var tdi = el('td'); tdi.colSpan = 2;
    tdi.appendChild(colourBox('Dark panels and footer', p.ink || '', inherited('dark', 'ink') || 'None', function (c) { if (c) { p.ink = c; } else { delete p.ink; } persist(); }));
    tri.appendChild(tdi); body.appendChild(tri);
    $('regen').hidden = !p.from;
  }
  $('rename').addEventListener('input', function () {
    var p = find(active.value);
    if (!p || p.id === 'draft') { return; }
    p.name = $('rename').value.trim().slice(0, 40) || 'Palette';
    persist();
    var chipName = chips.querySelector('[data-id="' + p.id + '"] .pl-chip-name');
    if (chipName) { chipName.textContent = p.name; }
  });
  $('delete').addEventListener('click', function () {
    var p = find(active.value);
    if (!p || p.id === 'draft' || !window.confirm('Delete the palette “' + p.name + '”? The site goes back to ' + ((builtin(p.source || DEFAULT) || { name: 'the default palette' }).name.replace(/ \(.*$/, '')) + '.')) { return; }
    palettes = palettes.filter(function (x) { return x.id !== p.id; });
    setActive('');
    setBuiltin(builtin(p.source || '') ? p.source : DEFAULT, true);
    persist();
    refocus = currentBuiltin();
    render();
  });
  $('regen').addEventListener('click', function () {
    var p = find(active.value);
    if (!p || !p.from) { return; }
    openCreate();
    replaces = p.id;
    $('name').value = p.name;
    $('save').textContent = 'Replace palette';
    showPane('colours');
    openStudio({ accent: p.from.accent, base: p.from.base, role: p.from.role, secondary: (p.secondary && p.secondary.light) || '' });
  });

  // ---- "+ Custom"
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
  function showPane(name) {
    studio.querySelectorAll('[data-pl-start]').forEach(function (r) { r.checked = r.value === name; });
    studio.querySelectorAll('[data-pl-pane]').forEach(function (pane) { pane.hidden = pane.getAttribute('data-pl-pane') !== name; });
    if (name === 'duo') { loadDuo(); }
  }
  function openCreate() {
    if (creating) { return; }
    before = selection().kind === 'site' ? active.value : '';
    creating = true;
    replaces = '';
    $('save').textContent = 'Create palette';
    render();
  }
  function openStudio(values) {
    var now = find(before);
    var a = (values && values.accent) || norm(inputs.accent.value) || (now && now.light && now.light.accent) || inherited('light', 'accent') || '#4338ca';
    var b = (values && values.base) || norm(inputs.base.value) || (now && now.light && now.light.background) || inherited('light', 'background') || '#ffffff';
    setBox(inputs.accent, a); setBox(inputs.base, b);
    if (values && values.secondary !== undefined) { setBox(inputs.secondary, values.secondary); }
    if (values && values.role) { studio.querySelectorAll('[data-pl-role]').forEach(function (r) { r.checked = r.value === values.role; }); }
    generate();
  }
  function closeCreate() {
    creating = false;
    discardDraft();
    result.textContent = '';
    render();
  }
  function schedule() { clearTimeout(timer); timer = setTimeout(generate, 220); }
  [inputs.accent, inputs.base, inputs.secondary].forEach(function (i) { i.addEventListener('input', schedule); });
  cleared.forEach(function (x) { x.addEventListener('click', function () { setTimeout(schedule, 0); }); });
  studio.querySelectorAll('[data-pl-role]').forEach(function (r) { r.addEventListener('change', schedule); });
  studio.querySelectorAll('[data-pl-start]').forEach(function (r) {
    r.addEventListener('change', function () {
      showPane(r.value);
      if (r.value === 'colours' && !draft) { openStudio(); }
    });
  });
  $('swap').addEventListener('click', function () {
    var a = inputs.accent.value, b = inputs.base.value;
    setBox(inputs.accent, b); setBox(inputs.base, a);
    generate();
  });
  $('new').addEventListener('click', function () {
    if (creating) { closeCreate(); return; }
    openCreate();
    showPane('colours');
    openStudio();
    inputs.accent.focus();
  });
  $('cancel').addEventListener('click', function () { closeCreate(); $('new').focus(); });
  $('name').addEventListener('input', function () { if (draft) { draft.name = $('name').value.trim() || 'New palette'; persist(); drawChips(); } });
  $('save').addEventListener('click', function () {
    if (!draft) { return; }
    var name = $('name').value.trim() || ('Palette ' + (palettes.length + 1));
    var saved = JSON.parse(JSON.stringify(draft));
    saved.name = name.slice(0, 40);
    if (replaces && find(replaces)) {
      var old = find(replaces);
      saved.id = replaces;
      if (old.source) { saved.source = old.source; }
      palettes = palettes.map(function (p) { return p.id === replaces ? saved : p; });
    } else {
      saved.id = slug(name);
      saved.source = currentBuiltin();
      palettes.push(saved);
    }
    draft = null; replaces = ''; creating = false;
    $('name').value = '';
    result.textContent = '';
    setActive(saved.id);
    persist(); refocus = saved.id; render();
    $('rename').focus();
  });

  function generate() {
    var accent = norm(inputs.accent.value), base = norm(inputs.base.value);
    if (!accent || !base || !creating) { return; }
    var mine = ++sequence;
    post('generate', { accent: accent, base: base, base_role: role(), secondary: norm(inputs.secondary.value) })
      .then(function (a) {
        if (!a || mine !== sequence || !creating) { return; }
        if (!a.ok) { result.textContent = a.message || ''; return; }
        var keep = $('name').value.trim();
        draft = { id: 'draft', name: keep || 'New palette', light: a.light, dark: a.dark, ink: a.ink, from: { accent: accent, base: base, role: role() } };
        if (a.secondary) { draft.secondary = a.secondary; }
        setActive('draft');
        persist(); render(); drawResult();
      })
      .catch(function () { /* the studio is a convenience: the form still works without it */ });
  }

  function drawResult() {
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
        var hex = el('span', 'pl-sw-hex', c[r[0]]); hex.style.color = ink(c[r[0]]);
        s.appendChild(hex); chipsRow.appendChild(s);
      });
      card.appendChild(chipsRow);
      var ratios = el('div', 'pl-ratios');
      [['Text', c.text, c.background], ['Surface', c.text, c.surface], ['Muted', c.muted, c.background], ['Link', c.accent, c.background]].forEach(function (t) {
        var row = el('span', 'pl-ratio'); row.appendChild(el('span', null, t[0] + ' ')); row.appendChild(badge(t[1], t[2])); ratios.appendChild(row);
      });
      var rowA = el('span', 'pl-ratio'); rowA.appendChild(el('span', null, 'On accent ')); rowA.appendChild(badge(ink(c.accent), c.accent)); ratios.appendChild(rowA);
      card.appendChild(ratios);
      wrap.appendChild(card);
    });
    result.appendChild(wrap);
  }

  // ---- a copy of a ready-made palette
  var copyHost = $('copy');
  builtins.forEach(function (b) {
    var data = BUILTIN[b.id];
    if (!data) { return; }
    var btn = el('button', 'pl-chip');
    btn.type = 'button';
    btn.appendChild(dots(data.light, data.dark, b.dot));
    btn.appendChild(el('span', 'pl-chip-name', b.name.replace(/ \(.*$/, '')));
    btn.addEventListener('click', function () { copyOf(b.id); });
    copyHost.appendChild(btn);
  });

  // ---- disclosure of the Duo settings (a button, not a nested details, so the cards stay plain)
  root.querySelectorAll('[data-pl-disc]').forEach(function (button) {
    button.addEventListener('click', function () {
      var open = button.getAttribute('aria-expanded') !== 'true';
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      document.getElementById(button.getAttribute('aria-controls')).hidden = !open;
    });
  });

  // ---- Duo
  var duoBox = $('duo-box');
  var duoToggle = form.querySelector('input[type="checkbox"][name$="[duo_picker]"]');
  var duoState = '';       // '' not asked, 'loading', 'done'
  var grid = $('grid'), note = $('duo-note'), aa = $('aa');
  var pairs = [];
  function duoOn() { return !duoToggle || duoToggle.checked; }
  function drawPairs() {
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
        showPane('colours');
        openStudio({ accent: a, base: b, role: 'background' });
        inputs.accent.focus();
      });
      grid.appendChild(btn);
    });
    note.textContent = grid.children.length ? 'Pick a pair: the first colour becomes the accent, the second the base. You can change both before you create the palette.' : 'No pairs match.';
  }
  function loadDuo() {
    var on = duoOn();
    duoBox.hidden = false;
    grid.hidden = !on; aa.parentNode.hidden = !on;
    if (!on) { note.textContent = 'Colour pairings from Duo are switched off. Turn them on in Settings below.'; return; }
    if (duoState !== '') { if (duoState === 'done') { drawPairs(); } return; }
    duoState = 'loading';
    note.textContent = 'Loading…';
    fetch(endpoint + '?palette=duo', { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (a) {
        if (!a || !a.ok || !Array.isArray(a.pairs)) {
          duoState = '';
          grid.hidden = true; aa.parentNode.hidden = true;
          note.textContent = (a && a.message) || 'Colour pairings are not available right now.';
          return;
        }
        duoState = 'done'; pairs = a.pairs; drawPairs();
      })
      .catch(function () { duoState = ''; grid.hidden = true; aa.parentNode.hidden = true; note.textContent = 'Colour pairings are not available right now.'; });
  }
  aa.addEventListener('change', drawPairs);
  if (duoToggle) { duoToggle.addEventListener('change', loadDuo); }

  // ---- contrast of what the preview shows (so an inherited colour is shown as the colour it gets), with Fix
  var contrastHost = $('contrast-rows'), contrastNote = $('contrast-note'), fixAll = $('fixall');
  // [label, text token, background token, the role to change, the backgrounds it must read on]
  var CHECKS = {
    light: [['Text on background', 'text', 'background', 'text', ['background', 'surface']], ['Text on surface', 'text', 'surface', 'text', ['background', 'surface']], ['Muted text on background', 'muted', 'background', 'muted', ['background', 'surface']], ['Accent text', '--accent-contrast-l', '--accent-l'], ['Links', 'accent', 'background', 'accent', ['background', 'surface']], ['Secondary text', '--secondary-contrast-l', '--secondary-l'], ['Secondary on background', 'secondary', 'background', 'secondary', ['background']]],
    dark: [['Text on background', 'text', 'background', 'text', ['background', 'surface']], ['Text on surface', 'text', 'surface', 'text', ['background', 'surface']], ['Muted text on background', 'muted', 'background', 'muted', ['background', 'surface']], ['Accent text', '--accent-contrast-d', '--accent-d'], ['Links', 'accent', 'background', 'accent', ['background', 'surface']], ['Secondary text', '--secondary-contrast-d', '--secondary-d'], ['Secondary on background', 'secondary', 'background', 'secondary', ['background']]]
  };
  function tokenColour(style, mode, name) {
    var token = name.indexOf('--') === 0 ? name : TOKENS[mode][name];
    return norm(style.getPropertyValue(token).trim());
  }
  function editable() { var s = selection(); return s.kind === 'site' && !creating ? find(s.id) : null; }
  function applyFix(p, mode, roleName, hex) {
    setRole(p, mode, roleName, hex);
    persist();
    drawEditor(p);
  }
  function fixOne(p, item) {
    return post('fix', { colour: item.fg, against: item.against.join(','), min: 4.5 }).then(function (a) {
      return a && a.ok ? { item: item, answer: a } : null;
    });
  }
  function proposal(li, p, item, a) {
    var box = el('div', 'pl-fix');
    var from = el('span', 'pl-aa-sample', 'Aa'); from.style.color = a.before; from.style.background = item.bg; from.setAttribute('aria-hidden', 'true');
    var to = el('span', 'pl-aa-sample', 'Aa'); to.style.color = a.after; to.style.background = item.bg; to.setAttribute('aria-hidden', 'true');
    var text = el('span', 'pl-fix-text');
    text.appendChild(el('span', null, a.before + ' → ' + a.after));
    text.appendChild(el('small', null, 'for ' + item.role + ', ' + item.mode + ' mode only · ' + a.ratio_before.toFixed(1) + ' → ' + a.ratio_after.toFixed(1) + ' ' + a.level));
    var apply = el('button', 'pl-btn pl-primary', 'Apply'); apply.type = 'button';
    apply.setAttribute('aria-label', 'Apply: change ' + item.role + ' in ' + item.mode + ' mode from ' + a.before + ' to ' + a.after);
    var cancel = el('button', 'pl-btn', 'Cancel'); cancel.type = 'button';
    apply.addEventListener('click', function () { applyFix(p, item.mode, item.role, a.after); });
    cancel.addEventListener('click', function () { drawContrast(); });
    box.appendChild(from); box.appendChild(to); box.appendChild(text); box.appendChild(apply); box.appendChild(cancel);
    li.appendChild(box);
    apply.focus();
  }
  function drawContrast() {
    var style = computed();
    if (!style) { return; }
    var p = editable();
    contrastHost.textContent = '';
    var shown = 0, failing = [];
    ['light', 'dark'].forEach(function (mode) {
      var col = el('div', 'pl-col');
      col.appendChild(el('h4', 'pl-col-title', mode === 'light' ? 'Light mode' : 'Dark mode'));
      var list = el('ul', 'pl-list');
      CHECKS[mode].forEach(function (c) {
        var fg = tokenColour(style, mode, c[1]), bgc = tokenColour(style, mode, c[2]);
        if (!fg || !bgc) { return; }
        shown++;
        var li = el('li', 'pl-item');
        var row = el('div', 'pl-item-row');
        var sample = el('span', 'pl-aa-sample', 'Aa'); sample.style.color = fg; sample.style.background = bgc; sample.setAttribute('aria-hidden', 'true');
        row.appendChild(sample);
        var label = el('span', 'pl-item-label'); label.appendChild(el('span', null, c[0])); label.appendChild(el('small', null, fg + ' on ' + bgc));
        row.appendChild(label); row.appendChild(badge(fg, bgc));
        var fails = ratio(fg, bgc) < 4.495;
        if (fails && p && c[3]) {
          var item = { mode: mode, role: c[3], fg: fg, bg: bgc, against: c[4].map(function (n) { return tokenColour(style, mode, n); }).filter(Boolean), label: c[0] };
          failing.push(item);
          var btn = el('button', 'pl-btn pl-fixbtn', 'Fix'); btn.type = 'button';
          btn.setAttribute('aria-label', 'Fix ' + c[0] + ' in ' + mode + ' mode: find the nearest shade that reads at AA');
          btn.addEventListener('click', function () {
            btn.disabled = true;
            fixOne(p, item).then(function (r) {
              btn.disabled = false;
              if (!r) { btn.textContent = 'No fix'; return; }
              btn.hidden = true;
              proposal(li, p, item, r.answer);
            }).catch(function () { btn.disabled = false; });
          });
          row.appendChild(btn);
        }
        li.appendChild(row);
        list.appendChild(li);
      });
      col.appendChild(list);
      contrastHost.appendChild(col);
    });
    fixAll.hidden = failing.length === 0;
    fixAll.onclick = function () {
      // One change for each colour, mode by mode, reading on every background it sits on.
      var byRole = {};
      failing.forEach(function (item) { byRole[item.mode + ':' + item.role] = byRole[item.mode + ':' + item.role] || item; });
      fixAll.disabled = true;
      Promise.all(Object.keys(byRole).map(function (k) { return fixOne(p, byRole[k]); })).then(function (answers) {
        fixAll.disabled = false;
        var done = 0;
        answers.forEach(function (r) { if (r) { setRole(p, r.item.mode, r.item.role, r.answer.after); done++; } });
        if (done) { persist(); drawEditor(p); }
        contrastNote.textContent = done ? 'Fixed ' + done + (done === 1 ? ' colour' : ' colours') + ' of “' + p.name + '”. Checking the preview…' : 'No shade could be found.';
      }).catch(function () { fixAll.disabled = false; });
    };
    contrastNote.textContent = shown ? (p ? 'The colours the site gets now. “Fix” changes only that colour, in that mode.' : 'The colours the site gets now. Customise this palette to change its colours.') : 'The preview has not been drawn yet.';
  }
  if (bp) { bp.addEventListener('preview:drawn', function () { drawContrast(); var p = editable(); if (p && !editView.hidden) { refreshPlaceholders(p); } }); }
  var frameNode = bp ? bp.querySelector('[data-bp-frame]') : null;
  if (frameNode) { frameNode.addEventListener('load', function () { setTimeout(drawContrast, 50); }); }
  // The colours a palette leaves empty show what they inherit; those come from the preview, so they are filled in once it is drawn.
  function refreshPlaceholders(p) {
    var inputsText = editView.querySelectorAll('tbody input[type="text"]');
    var rows = ROLES.map(function (r) { return r[0]; }).concat(['secondary']);
    var n = 0;
    rows.forEach(function (name) {
      ['light', 'dark'].forEach(function (mode) {
        var t = inputsText[n++];
        if (t && t.value === '' && name !== 'secondary') { t.placeholder = inherited(mode, name) || 'Theme'; }
      });
    });
  }

  // ---- first draw
  if (active.value !== '' && !find(active.value)) { active.value = ''; }
  var first = find(active.value);
  if (first && first.source && builtin(first.source)) { setBuiltin(first.source, false); }
  render();
})();
