/*
 * FarosCMS block editor (Admin > Edit > Blocks).
 *
 * Reads block definitions and the page's blocks from #block-editor-data, renders a form per block
 * from its block.yaml fields, and writes the result as JSON into [data-block-editor-output] before
 * the page is saved. The server checks every value again (BlockRegistry::sanitizeForStorage).
 * Rendering happens only on structural changes (add, move, remove, open); typing updates state
 * directly so the field keeps focus.
 */
(function () {
  'use strict';

  var dataEl = document.getElementById('block-editor-data');
  var root = document.querySelector('[data-block-editor]');
  var output = document.querySelector('[data-block-editor-output]');
  var flag = document.querySelector('[data-block-editor-flag]');
  if (!dataEl || !root || !output || !flag) return;

  var data;
  try {
    data = JSON.parse(dataEl.textContent);
  } catch (e) {
    return;
  }

  var definitions = {};
  (data.definitions || []).forEach(function (def) { definitions[def.type] = def; });
  var presets = data.presets || [];
  var selected = {};
  var nextId = 1;
  var blocks = (data.blocks || []).map(function (block) { return withId(block, false); });
  var lastRemoved = null;

  var CLS = {
    input: 'lc-input',
    btn: 'bk-btn',
    btnPrimary: 'bk-btn is-primary',
    icon: 'bk-icon',
    iconDanger: 'bk-icon is-danger'
  };


  var ICONS = {
    up: '<path d="m6 15 6-6 6 6"/>',
    down: '<path d="m6 9 6 6 6-6"/>',
    copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    chevron: '<path d="m9 6 6 6-6 6"/>',
    eyeOff: '<path d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 5.1A9.8 9.8 0 0 1 12 5c5 0 9 5 9 7a9.6 9.6 0 0 1-2.5 3.5M6.2 6.2C4.3 7.5 3 9.5 3 12c0 2 4 7 9 7a9.3 9.3 0 0 0 4.3-1"/>',
    image: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>',
    eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    close: '<path d="M6 6l12 12M18 6L6 18"/>'
  };

  /* The wireframe a block shows in the picker: the markup of its preview.svg (see BlockRegistry::previewMarkup), or a plain
     stack of boxes for a block that has none. */
  var PREVIEW_FALLBACK = '<rect x="8" y="8" width="48" height="12" rx="3"/><rect x="8" y="24" width="48" height="16" rx="3" fill="currentColor" fill-opacity=".14"/>';

  function thumb(markup) {
    return el('span', {
      class: 'bk-pthumb',
      html: '<svg viewBox="0 0 64 48" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + (markup || PREVIEW_FALLBACK) + '</svg>'
    });
  }

  function svg(name, cls) {
    return '<svg class="' + (cls || 'h-4 w-4') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[name] + '</svg>';
  }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      if (key === 'class') node.className = attrs[key];
      else if (key === 'text') node.textContent = attrs[key];
      else if (key === 'html') node.innerHTML = attrs[key];
      else if (key.indexOf('on') === 0) node.addEventListener(key.slice(2), attrs[key]);
      else if (attrs[key] !== null && attrs[key] !== undefined && attrs[key] !== false) node.setAttribute(key, attrs[key] === true ? '' : attrs[key]);
    });
    (children || []).forEach(function (child) {
      if (child) node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    });
    return node;
  }

  function withId(block, open) {
    var copy = JSON.parse(JSON.stringify(block));
    Object.defineProperty(copy, '_id', { value: nextId++, enumerable: false, writable: true });
    Object.defineProperty(copy, '_open', { value: !!open, enumerable: false, writable: true });
    Object.defineProperty(copy, '_tab', { value: 'content', enumerable: false, writable: true });
    return copy;
  }

  function defaultsFor(fields) {
    var values = {};
    (fields || []).forEach(function (field) {
      values[field.key] = field.type === 'repeater' ? [] : field['default'];
    });
    return values;
  }

  function newBlock(type) {
    var def = definitions[type];
    var block = { type: type };
    Object.assign(block, defaultsFor(def.common), defaultsFor(def.fields));
    return withId(block, true);
  }

  function serialize() {
    output.value = JSON.stringify(blocks);
    var count = document.querySelector('[data-block-count]');
    if (count) count.textContent = blocks.length ? String(blocks.length) : '';
  }

  function summaryOf(block) {
    var keys = ['heading', 'eyebrow', 'text', 'intro', 'body'];
    for (var i = 0; i < keys.length; i++) {
      var value = block[keys[i]];
      if (typeof value === 'string' && value.trim() !== '') {
        var text = value.replace(/\s+/g, ' ').trim();
        return text.length > 70 ? text.slice(0, 70) + '…' : text;
      }
    }
    return '';
  }

  function optionLabel(field, value) {
    var match = (field.options || []).filter(function (pair) { return pair[0] === String(value); })[0];
    return match ? match[1] : String(value);
  }

  /* Fields ------------------------------------------------------------------ */

  // A field a person fills in is a small label over a compact control; short fields sit two to a row.
  var WIDE = { textarea: 1, markdown: 1, image: 1, video: 1, repeater: 1 };

  /** A choice of a few short words is a row of buttons; a longer list is a menu. */
  function isShortChoice(field) {
    var options = field.options || [];
    return options.length > 1 && options.length <= 4 && options.every(function (pair) { return String(pair[1]).length <= 14; });
  }

  function choiceButtons(field, value, onChange, cls, labelOf) {
    var group = el('div', { class: cls, role: 'group', 'aria-label': field.label });
    var buttons = [];
    (field.options || []).forEach(function (pair) {
      var on = String(value) === pair[0];
      var button = el('button', { type: 'button', 'aria-pressed': on ? 'true' : 'false', 'data-value': pair[0] });
      if (labelOf) { labelOf(button, pair); } else { button.textContent = pair[1]; }
      button.addEventListener('click', function () {
        buttons.forEach(function (b) { b.setAttribute('aria-pressed', b === button ? 'true' : 'false'); });
        onChange(pair[0]);
      });
      buttons.push(button);
      group.appendChild(button);
    });
    return group;
  }

  /** The help of a field: short help is shown under it; long help opens from a small button beside the label. */
  function helpNode(field, id) {
    if (!field.help) return null;
    return el('span', { class: 'bk-help', id: id + '-help', text: field.help });
  }

  function fieldControl(field, value, onChange, idBase) {
    var id = idBase + '-' + field.key;
    var type = field.type;
    var control;

    if (type === 'repeater') return repeaterControl(field, Array.isArray(value) ? value : [], onChange, id);

    if (type === 'toggle') {
      var checkbox = el('input', { type: 'checkbox', id: id, checked: !!value });
      checkbox.addEventListener('change', function () { onChange(checkbox.checked); });
      return el('div', { class: 'bk-field bk-field-toggle' + (field.span === 'full' ? ' is-wide' : '') }, [
        el('label', { class: 'lc-chip', title: field.help || null }, [checkbox, el('span', { text: field.label })]),
        field.help && field.help.length > 60 ? helpNode(field, id) : null
      ]);
    }

    var labelText = field.label + (type === 'markdown' ? ' (Markdown)' : '') + (field.required ? ' *' : '');
    var wide = field.span === 'full' || WIDE[type] === 1;
    var wrapper = el('div', { class: 'bk-field' + (wide ? ' is-wide' : '') }, [
      el('label', { class: 'bk-label', for: id, text: labelText })
    ]);

    if ((type === 'select') && isShortChoice(field)) {
      control = choiceButtons(field, value == null ? '' : value, onChange, 'bk-seg');
      control.id = id;
      wrapper.querySelector('label').removeAttribute('for');
      wrapper.querySelector('label').setAttribute('id', id + '-label');
      control.setAttribute('aria-labelledby', id + '-label');
      wrapper.appendChild(control);
    } else if (type === 'select' || type === 'icon') {
      // An icon is a select the icon picker (admin-icons.js) turns into a popup of small pictures.
      control = el('select', { id: id, class: 'lc-input lc-select' });
      if (type === 'icon') control.setAttribute('data-icon-picker', '');
      (field.options || []).forEach(function (pair) {
        control.appendChild(el('option', { value: pair[0], text: pair[1], selected: String(value) === pair[0] }));
      });
      control.addEventListener('change', function () { onChange(control.value); });
      wrapper.appendChild(control);
    } else if (type === 'textarea' || type === 'markdown') {
      control = el('textarea', {
        id: id,
        rows: type === 'markdown' ? 5 : 2,
        placeholder: field.placeholder || '',
        class: 'lc-input bk-area' + (type === 'markdown' ? ' font-mono text-[12px] leading-5' : '')
      });
      control.value = value == null ? '' : String(value);
      control.addEventListener('input', function () { onChange(control.value); });
      wrapper.appendChild(control);
    } else if (type === 'image') {
      control = el('input', { id: id, type: 'text', placeholder: field.placeholder || '/uploads/…', class: 'lc-input' });
      control.value = value == null ? '' : String(value);
      var thumbBox = el('span', { class: 'bk-thumb' }, [el('img', { alt: '' }), el('span', { html: svg('image'), class: 'bk-thumb-empty' })]);
      var img = thumbBox.querySelector('img');
      var sync = function () {
        var has = control.value.trim() !== '';
        thumbBox.classList.toggle('has-image', has);
        if (has) img.src = control.value; else img.removeAttribute('src');
      };
      control.addEventListener('input', function () { onChange(control.value); sync(); });
      var choose = el('button', { type: 'button', class: CLS.btn, html: svg('image') + '<span>Library</span>' });
      choose.addEventListener('click', function () {
        openMediaPicker(function (url) { control.value = url; onChange(url); sync(); });
      });
      var clear = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Clear ' + field.label, title: 'Clear', html: svg('close') });
      clear.addEventListener('click', function () { control.value = ''; onChange(''); sync(); });
      sync();
      wrapper.appendChild(el('div', { class: 'bk-image' }, [thumbBox, control, choose, clear]));
    } else if (type === 'video') {
      // A video: its address (the library, a YouTube or Vimeo link, a file elsewhere) with a Library button for the videos.
      control = el('input', { id: id, type: 'text', placeholder: field.placeholder || '', class: 'lc-input' });
      control.value = value == null ? '' : String(value);
      control.addEventListener('input', function () { onChange(control.value); });
      var chooseVideo = el('button', { type: 'button', class: CLS.btn, html: svg('image') + '<span>Library</span>' });
      chooseVideo.setAttribute('aria-label', 'Choose a video from the media library');
      chooseVideo.addEventListener('click', function () {
        openMediaPicker(function (url) { control.value = url; onChange(url); }, 'video');
      });
      wrapper.appendChild(el('div', { class: 'bk-image' }, [control, chooseVideo]));
    } else {
      var inputType = type === 'number' ? 'number' : (type === 'email' ? 'email' : 'text');
      control = el('input', {
        id: id,
        type: inputType,
        placeholder: field.placeholder || '',
        min: type === 'number' && field.min !== null ? field.min : null,
        max: type === 'number' && field.max !== null ? field.max : null,
        inputmode: type === 'decimal' ? 'decimal' : null,
        class: 'lc-input'
      });
      control.value = value == null ? '' : String(value);
      control.addEventListener('input', function () {
        var raw = control.value;
        onChange(type === 'number' && raw !== '' ? Number(raw) : raw);
      });
      wrapper.appendChild(control);
    }
    if (control && field.help) control.setAttribute('aria-describedby', id + '-help');
    var help = helpNode(field, id);
    if (help) wrapper.appendChild(help);
    return wrapper;
  }

  /* A list of items (people, plans, steps…): each is one compact line that opens to its fields. */
  var rowOpen = new WeakMap();

  function repeaterControl(field, rows, onChange, id) {
    var item = field.item_label || 'Item';
    var list = el('div', { class: 'bk-rows' });
    var count = el('span', { class: 'bk-count', text: rows.length + (field.max ? ' / ' + field.max : '') });
    var allOpen = rows.length > 0 && rows.every(function (r) { return rowOpen.get(r); });
    var toggleAll = rows.length > 1 ? el('button', { type: 'button', class: 'bk-link', text: allOpen ? 'Collapse all' : 'Expand all' }) : null;
    var wrapper = el('div', { class: 'bk-field bk-rep' + (rows.length ? ' is-wide' : ' is-empty') }, [
      el('div', { class: 'bk-rep-head' }, [el('span', { class: 'bk-label', text: field.label }), count, toggleAll]),
      list
    ]);

    function commit() {
      onChange(rows);
      var fresh = repeaterControl(field, rows, onChange, id);
      wrapper.replaceWith(fresh);
      return fresh;
    }
    if (toggleAll) {
      toggleAll.addEventListener('click', function () {
        rows.forEach(function (r) { rowOpen.set(r, !allOpen); });
        commit();
      });
    }

    rows.forEach(function (row, index) {
      if (!rowOpen.has(row)) rowOpen.set(row, rows.length === 1);
      var open = rowOpen.get(row);
      var rowId = id + '-' + index;
      var texts = [];
      var picture = '';
      (field.fields || []).forEach(function (sub) {
        var v = row[sub.key];
        if (typeof v !== 'string' || v.trim() === '') return;
        if (sub.type === 'image') { if (!picture) picture = v; return; }
        if (sub.type === 'select' || sub.type === 'icon' || sub.type === 'link' || sub.type === 'video') return;
        texts.push(v.trim().replace(/\s+/g, ' '));
      });

      var head = el('button', { type: 'button', class: 'bk-row-toggle', 'aria-expanded': open ? 'true' : 'false', 'aria-controls': rowId + '-body' }, [
        el('span', { class: 'bk-chev' + (open ? ' is-open' : ''), html: svg('chevron', 'h-3.5 w-3.5') }),
        picture ? el('img', { class: 'bk-row-pic', src: picture, alt: '' }) : null,
        el('span', { class: 'bk-row-n', text: String(index + 1) }),
        el('span', { class: 'bk-row-title', text: texts[0] || item }),
        texts[1] ? el('span', { class: 'bk-row-sub', text: texts[1] }) : null
      ]);
      head.addEventListener('click', function () {
        rowOpen.set(row, !open);
        commit();
      });

      var moveUp = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Move ' + item.toLowerCase() + ' ' + (index + 1) + ' up', disabled: index === 0, html: svg('up') });
      var moveDown = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Move ' + item.toLowerCase() + ' ' + (index + 1) + ' down', disabled: index === rows.length - 1, html: svg('down') });
      var remove = el('button', { type: 'button', class: CLS.iconDanger, 'aria-label': 'Remove ' + item.toLowerCase() + ' ' + (index + 1), html: svg('trash') });
      moveUp.addEventListener('click', function () {
        rows.splice(index - 1, 0, rows.splice(index, 1)[0]);
        var fresh = commit();
        var target = fresh.querySelectorAll('.bk-row')[index - 1];
        if (target) { var b = target.querySelector('[aria-label$=" up"]:not([disabled])') || target.querySelector('.bk-row-toggle'); if (b) b.focus(); }
      });
      moveDown.addEventListener('click', function () {
        rows.splice(index + 1, 0, rows.splice(index, 1)[0]);
        var fresh = commit();
        var target = fresh.querySelectorAll('.bk-row')[index + 1];
        if (target) { var b = target.querySelector('[aria-label$=" down"]:not([disabled])') || target.querySelector('.bk-row-toggle'); if (b) b.focus(); }
      });
      remove.addEventListener('click', function () {
        rows.splice(index, 1);
        commit();
      });

      var card = el('div', { class: 'bk-row' + (open ? ' is-open' : '') }, [
        el('div', { class: 'bk-row-head' }, [head, el('span', { class: 'bk-row-actions' }, [moveUp, moveDown, remove])])
      ]);
      if (open) {
        var body = el('div', { id: rowId + '-body', class: 'bk-grid bk-row-body' });
        var conditional = [];
        var apply = function () {
          conditional.forEach(function (entry) {
            var shown = Object.keys(entry.when).every(function (key) {
              var other = (field.fields || []).filter(function (f) { return f.key === key; })[0];
              var have = row[key] == null ? (other && other.default != null ? other.default : '') : row[key];
              return entry.when[key].indexOf(String(have)) !== -1;
            });
            entry.node.hidden = !shown;
          });
        };
        (field.fields || []).forEach(function (sub) {
          var node = fieldControl(sub, row[sub.key], function (value) {
            row[sub.key] = value;
            onChange(rows);
            apply();
          }, rowId);
          if (sub.when) conditional.push({ node: node, when: sub.when });
          body.appendChild(node);
        });
        apply();
        card.appendChild(body);
      }
      list.appendChild(card);
    });

    var full = field.max && rows.length >= field.max;
    var add = el('button', { type: 'button', class: 'bk-add', disabled: !!full, html: svg('plus') + '<span>Add ' + item.toLowerCase() + '</span>' });
    add.addEventListener('click', function () {
      var fresh = defaultsFor(field.fields);
      rowOpen.set(fresh, true);
      rows.push(fresh);
      var node = commit();
      var rowsNow = node.querySelectorAll('.bk-row');
      var last = rowsNow[rowsNow.length - 1];
      var focusable = last ? last.querySelector('input:not([type=checkbox]), textarea, select') : null;
      if (focusable) focusable.focus();
    });
    if (rows.length === 0) {
      // Nothing yet: the list is one line, with its button beside the name.
      add.className = 'bk-btn';
      wrapper.querySelector('.bk-rep-head').appendChild(add);
      list.remove();
      return wrapper;
    }
    wrapper.appendChild(add);
    return wrapper;
  }

  /* Blocks ------------------------------------------------------------------ */

  var TONE_SWATCH = { 'default': '#ffffff', muted: '#f1f5f9', contrast: '#0f172a', accent: '#2563eb' };

  function designPanel(block, def, idBase) {
    var panel = el('div', { class: 'bk-design', role: 'tabpanel' });
    var field = function (key) { return def.common.filter(function (f) { return f.key === key; })[0]; };
    var change = function (key) {
      return function (value) {
        block[key] = value;
        serialize();
        if (key === 'hidden' || key === 'variant') render(block._id, 'keep');
      };
    };
    var variant = field('variant');
    if (variant && variant.options.length > 1) {
      var layout = el('div', { class: 'bk-field is-wide' }, [el('span', { class: 'bk-label', text: variant.label })]);
      layout.appendChild(choiceButtons(variant, block.variant == null ? variant['default'] : block.variant, change('variant'), 'bk-choices'));
      panel.appendChild(layout);
    }
    var row = el('div', { class: 'bk-grid' });
    var tone = field('tone');
    if (tone) {
      var swatches = el('div', { class: 'bk-field' }, [el('span', { class: 'bk-label', text: tone.label })]);
      swatches.appendChild(choiceButtons(tone, block.tone == null ? tone['default'] : block.tone, change('tone'), 'bk-swatches', function (button, pair) {
        button.appendChild(el('i', { style: 'background:' + (TONE_SWATCH[pair[0]] || '#fff'), 'aria-hidden': 'true' }));
        button.appendChild(el('span', { text: pair[1] }));
      }));
      row.appendChild(swatches);
    }
    var spacing = field('spacing');
    if (spacing) row.appendChild(fieldControl(Object.assign({}, spacing, { span: '' }), block.spacing == null ? spacing['default'] : block.spacing, change('spacing'), idBase));
    ['anchor', 'hidden'].forEach(function (key) {
      var f = field(key);
      if (f) row.appendChild(fieldControl(Object.assign({}, f, { span: '' }), block[key], change(key), idBase));
    });
    def.common.forEach(function (f) {
      if (['variant', 'tone', 'spacing', 'anchor', 'hidden'].indexOf(f.key) === -1) row.appendChild(fieldControl(Object.assign({}, f, { span: '' }), block[f.key], change(f.key), idBase));
    });
    panel.appendChild(row);
    return panel;
  }

  function contentPanel(block, def, idBase) {
    var panel = el('div', { class: 'bk-content', role: 'tabpanel' });
    if (def.description) panel.appendChild(el('p', { class: 'bk-desc', text: def.description }));
    if (!def.fields.length) return panel;
    var fields = el('div', { class: 'bk-grid' });
    // A field with `when: {other: value}` shows only while the other field has one of those values.
    var conditional = [];
    var applyWhen = function () {
      conditional.forEach(function (entry) {
        var shown = Object.keys(entry.when).every(function (key) {
          var other = def.fields.filter(function (f) { return f.key === key; })[0];
          var have = block[key] == null ? (other && other.default != null ? other.default : '') : block[key];
          return entry.when[key].indexOf(String(have)) !== -1;
        });
        entry.node.hidden = !shown;
      });
    };
    def.fields.forEach(function (field) {
      var node = fieldControl(field, block[field.key], function (value) {
        block[field.key] = value;
        serialize();
        applyWhen();
      }, idBase);
      if (field.when) conditional.push({ node: node, when: field.when });
      fields.appendChild(node);
    });
    applyWhen();
    panel.appendChild(fields);
    return panel;
  }

  function renderBlock(block, index) {
    var def = definitions[block.type];
    var idBase = 'blk-' + block._id;
    var card = el('section', { class: 'bk-card' + (block.hidden ? ' is-hidden' : '') + (block._open ? ' is-open' : ''), 'data-block-card': block._id });

    var label = def ? def.label : 'Unknown block: ' + block.type;
    var summary = def ? summaryOf(block) : 'Kept as is. Its definition is not installed.';
    var toggle = el('button', {
      type: 'button',
      class: 'bk-toggle',
      'aria-expanded': block._open ? 'true' : 'false',
      'aria-controls': idBase + '-body',
      html: '<span class="bk-chev' + (block._open ? ' is-open' : '') + '">' + svg('chevron', 'h-3.5 w-3.5') + '</span>'
    });
    if (def) toggle.appendChild(el('span', { class: 'bk-mini', html: '<svg viewBox="0 0 64 48" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">' + (def.preview || PREVIEW_FALLBACK) + '</svg>' }));
    toggle.appendChild(el('span', { class: 'bk-name', text: (index + 1) + '. ' + label }));
    if (def && block.variant && def.common) {
      var variantField = def.common.filter(function (f) { return f.key === 'variant'; })[0];
      if (variantField && variantField.options.length > 1) {
        toggle.appendChild(el('span', { class: 'bk-tag', text: optionLabel(variantField, block.variant) }));
      }
    }
    if (block.hidden) {
      toggle.appendChild(el('span', { class: 'bk-tag is-warn', html: svg('eyeOff', 'h-3 w-3') + 'Hidden' }));
    }
    if (summary) toggle.appendChild(el('span', { class: 'bk-summary', text: summary }));
    toggle.addEventListener('click', function () {
      block._open = !block._open;
      render(block._id, 'toggle');
    });

    var actions = el('div', { class: 'bk-actions' });
    var eye = el('button', { type: 'button', class: CLS.icon, 'aria-label': block.hidden ? 'Show block on the page' : 'Hide block from the page', title: block.hidden ? 'Show on the page' : 'Hide from the page', 'aria-pressed': block.hidden ? 'true' : 'false', html: svg(block.hidden ? 'eyeOff' : 'eye'), disabled: !def, 'data-action': 'hide' });
    var up = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Move block up', title: 'Move up', disabled: index === 0, html: svg('up'), 'data-action': 'up' });
    var down = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Move block down', title: 'Move down', disabled: index === blocks.length - 1, html: svg('down'), 'data-action': 'down' });
    var insert = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Add a block below', title: 'Add a block below', html: svg('plus'), 'data-action': 'insert' });
    var duplicate = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Duplicate block', title: 'Duplicate', html: svg('copy'), disabled: !def, 'data-action': 'duplicate' });
    var remove = el('button', { type: 'button', class: CLS.iconDanger, 'aria-label': 'Remove block', title: 'Remove', html: svg('trash'), 'data-action': 'remove' });
    eye.addEventListener('click', function () {
      block.hidden = !block.hidden;
      render(block._id, 'keep');
    });
    up.addEventListener('click', function () { move(index, index - 1, 'up'); });
    down.addEventListener('click', function () { move(index, index + 1, 'down'); });
    insert.addEventListener('click', function () { openPicker(index + 1, insert); });
    duplicate.addEventListener('click', function () {
      var copy = withId(block, true);
      blocks.splice(index + 1, 0, copy);
      render(copy._id, 'toggle');
    });
    remove.addEventListener('click', function () {
      lastRemoved = { block: block, index: index };
      blocks.splice(index, 1);
      render(null);
      showUndo(label);
    });
    [eye, up, down, insert, duplicate, remove].forEach(function (b) { actions.appendChild(b); });

    var select = el('input', { type: 'checkbox', class: 'bk-select', 'aria-label': 'Select ' + label + ' to save as a section', title: 'Select to save as a section', checked: !!selected[block._id], disabled: !def });
    select.addEventListener('change', function () {
      if (select.checked) selected[block._id] = true;
      else delete selected[block._id];
      syncSaveBar();
    });
    card.appendChild(el('div', { class: 'bk-head' }, [select, toggle, actions]));

    if (block._open && def) {
      var body = el('div', { id: idBase + '-body', class: 'bk-body' });
      var panels = { content: contentPanel(block, def, idBase), design: designPanel(block, def, idBase) };
      var tabs = el('div', { class: 'bk-tabs', role: 'tablist' });
      var show = function (name) {
        block._tab = name;
        Object.keys(panels).forEach(function (key) { panels[key].hidden = key !== name; });
        Array.prototype.forEach.call(tabs.children, function (b) {
          b.setAttribute('aria-selected', b.getAttribute('data-tab') === name ? 'true' : 'false');
          b.tabIndex = b.getAttribute('data-tab') === name ? 0 : -1;
        });
      };
      [['content', 'Content'], ['design', 'Design']].forEach(function (pair) {
        var tab = el('button', { type: 'button', role: 'tab', 'data-tab': pair[0], text: pair[1], 'aria-controls': idBase + '-' + pair[0] });
        tab.addEventListener('click', function () { show(pair[0]); });
        tab.addEventListener('keydown', function (event) {
          if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
            event.preventDefault();
            var other = pair[0] === 'content' ? 'design' : 'content';
            show(other);
            tabs.querySelector('[data-tab="' + other + '"]').focus();
          }
        });
        tabs.appendChild(tab);
      });
      panels.content.id = idBase + '-content';
      panels.design.id = idBase + '-design';
      body.appendChild(tabs);
      body.appendChild(panels.content);
      body.appendChild(panels.design);
      show(block._tab === 'design' ? 'design' : 'content');
      card.appendChild(body);
    }
    return card;
  }

  function move(from, to, direction) {
    if (to < 0 || to >= blocks.length) return;
    var block = blocks.splice(from, 1)[0];
    blocks.splice(to, 0, block);
    render(block._id, direction);
  }

  var list = el('div', { class: 'bk-list', 'data-block-list': '' });
  var empty = el('div', { class: 'bk-empty' }, [
    el('p', { class: 'bk-empty-title', text: 'This page has no blocks yet.' }),
    el('p', { class: 'bk-help', text: 'Without blocks the page shows its Markdown text as before. Add blocks to build the page from sections.' }),
    presets.some(function (p) { return p.kind === 'page'; })
      ? el('button', { type: 'button', class: CLS.btn, text: 'Start from a page layout', onclick: function (event) { pickerTab = 'pages'; openPicker(blocks.length, event.currentTarget); } })
      : null
  ]);
  var undoBar = el('div', { class: 'bk-note hidden', role: 'status' });

  function showUndo(label) {
    undoBar.innerHTML = '';
    undoBar.appendChild(el('span', { text: label + ' removed.' }));
    var undo = el('button', { type: 'button', class: 'bk-link', text: 'Undo' });
    undo.addEventListener('click', function () {
      if (!lastRemoved) return;
      if (lastRemoved.many) {
        blocks.length = 0;
        lastRemoved.many.forEach(function (b) { blocks.push(b); });
        lastRemoved = null;
        undoBar.classList.add('hidden');
        render(blocks.length ? blocks[0]._id : null, 'toggle');
        return;
      }
      blocks.splice(lastRemoved.index, 0, lastRemoved.block);
      var id = lastRemoved.block._id;
      lastRemoved = null;
      undoBar.classList.add('hidden');
      render(id, 'toggle');
    });
    undoBar.appendChild(undo);
    undoBar.classList.remove('hidden');
  }

  function render(focusId, focusWhat) {
    list.innerHTML = '';
    blocks.forEach(function (block, index) { list.appendChild(renderBlock(block, index)); });
    empty.classList.toggle('hidden', blocks.length > 0);
    serialize();
    if (focusId) {
      var card = list.querySelector('[data-block-card="' + focusId + '"]');
      if (!card) return;
      var target = null;
      if (focusWhat === 'up' || focusWhat === 'down') {
        target = card.querySelector('[data-action="' + focusWhat + '"]:not([disabled])') || card.querySelector('[aria-expanded]');
      } else if (focusWhat === 'toggle') {
        target = card.querySelector('[aria-expanded]');
        card.scrollIntoView({ block: 'nearest' });
      }
      if (target) target.focus();
    }
  }

  /* Block picker ------------------------------------------------------------- */

  var picker = el('div', { class: 'bk-picker hidden', 'data-block-picker': '' });
  var pickerTarget = blocks.length;
  var pickerReturn = null;

  var pickerTab = 'blocks';

  function insertBlocks(list, position) {
    var added = list.map(function (b) { return withId(b, false); });
    Array.prototype.splice.apply(blocks, [position, 0].concat(added));
    if (added.length) added[0]._open = true;
    return added;
  }

  function applyTemplate(template) {
    var selectEl = document.querySelector('[data-template-select]');
    if (!selectEl || !template) return;
    var option = selectEl.querySelector('option[value="' + template + '"]');
    if (!option) return;
    selectEl.value = template;
    selectEl.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function presetCard(preset, onChoose) {
    var card = el('div', { class: 'bk-pcard' });
    var first = preset.blocks[0] && definitions[preset.blocks[0].type];
    var choose = el('button', { type: 'button', class: 'bk-pchoose' }, [
      thumb(first ? first.preview : ''),
      el('span', { class: 'bk-ptext' }, [
        el('span', { class: 'bk-pname', text: preset.label }),
        el('span', { class: 'bk-pdesc', text: preset.description || '' }),
        el('span', { class: 'bk-pmeta', text: preset.blocks.map(function (b) { return definitions[b.type] ? definitions[b.type].label : b.type; }).join(' · ') + (preset.origin === 'custom' ? ' · saved on this site' : '') })
      ])
    ]);
    choose.addEventListener('click', function () { onChoose(preset); });
    card.appendChild(choose);
    if (preset.origin === 'custom') {
      var remove = el('button', { type: 'button', class: CLS.iconDanger + ' bk-pdelete', 'aria-label': 'Delete saved section ' + preset.label, html: svg('trash') });
      remove.addEventListener('click', function () {
        remove.disabled = true;
        postPresets({ preset_action: 'delete', id: preset.id }).then(function (res) {
          if (!res.ok) throw new Error('delete');
          presets = presets.filter(function (p) { return p.id !== preset.id; });
          renderPicker();
          if (window.adminToast) window.adminToast('Saved section deleted.', 'success');
        }).catch(function () {
          remove.disabled = false;
          if (window.adminToast) window.adminToast('Could not delete the section.', 'error');
        });
      });
      card.appendChild(remove);
    }
    return card;
  }

  var CATEGORY_ORDER = ['Openers', 'Content', 'Media', 'Showcase', 'Convert'];
  var pickerCategory = '';

  function renderPicker() {
    var position = pickerTarget;
    picker.innerHTML = '';
    var tabs = [['blocks', 'Blocks'], ['sections', 'Ready-made sections'], ['pages', 'Page layouts']];
    var tabBar = el('div', { class: 'bk-pills', role: 'tablist' });
    tabs.forEach(function (tab) {
      var active = pickerTab === tab[0];
      var button = el('button', { type: 'button', role: 'tab', 'aria-selected': active ? 'true' : 'false', text: tab[1] });
      button.addEventListener('click', function () { pickerTab = tab[0]; renderPicker(); var t = picker.querySelector('[aria-selected="true"]'); if (t) t.focus(); });
      tabBar.appendChild(button);
    });
    picker.appendChild(el('div', { class: 'bk-picker-head' }, [
      tabBar,
      el('div', { class: 'bk-picker-side' }, [
        el('span', { class: 'bk-help', text: position < blocks.length ? 'Inserts at position ' + (position + 1) : 'Adds at the end' }),
        el('button', { type: 'button', class: CLS.btn, text: 'Cancel', onclick: closePicker })
      ])
    ]));
    var grid = el('div', { class: 'bk-picker-grid' });

    if (pickerTab === 'blocks') {
      var options = [];
      var categoryOf = function (def) { return def.category || (def.origin === 'custom' ? 'Custom' : 'Other'); };
      var present = [];
      Object.keys(definitions).forEach(function (type) {
        var cat = categoryOf(definitions[type]);
        if (present.indexOf(cat) === -1) present.push(cat);
      });
      present.sort(function (a, b) {
        var ia = CATEGORY_ORDER.indexOf(a), ib = CATEGORY_ORDER.indexOf(b);
        return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib) || a.localeCompare(b);
      });
      Object.keys(definitions).sort(function (x, y) {
        var cx = present.indexOf(categoryOf(definitions[x])), cy = present.indexOf(categoryOf(definitions[y]));
        return cx - cy;
      }).forEach(function (type) {
        var def = definitions[type];
        var option = el('button', { type: 'button', class: 'bk-pcard bk-pchoose' }, [
          thumb(def.preview),
          el('span', { class: 'bk-ptext' }, [
            el('span', { class: 'bk-pname', text: def.label + (def.origin === 'custom' ? ' (custom)' : '') }),
            el('span', { class: 'bk-pdesc', text: def.description })
          ])
        ]);
        option.addEventListener('click', function () {
          var block = newBlock(type);
          blocks.splice(pickerTarget, 0, block);
          closePicker(true);
          render(block._id, 'toggle');
        });
        options.push({ node: option, category: categoryOf(def), text: (def.label + ' ' + def.description + ' ' + type + ' ' + categoryOf(def)).toLowerCase() });
        grid.appendChild(option);
      });
      var none = el('p', { class: 'bk-help hidden', text: 'No block matches.' });
      var search = el('input', { type: 'search', class: 'lc-input bk-search', placeholder: 'Search blocks', 'aria-label': 'Search blocks' });
      var chips = el('div', { class: 'bk-pills is-light', role: 'group', 'aria-label': 'Kinds of block' });
      var apply = function () {
        var term = search.value.trim().toLowerCase();
        var shown = 0;
        options.forEach(function (o) {
          var match = (term === '' || o.text.indexOf(term) !== -1) && (pickerCategory === '' || o.category === pickerCategory);
          o.node.classList.toggle('hidden', !match);
          if (match) shown++;
        });
        none.classList.toggle('hidden', shown > 0);
        Array.prototype.forEach.call(chips.children, function (c) { c.setAttribute('aria-pressed', (c.getAttribute('data-cat') || '') === pickerCategory ? 'true' : 'false'); });
      };
      [''].concat(present).forEach(function (cat) {
        var chip = el('button', { type: 'button', 'data-cat': cat, text: cat === '' ? 'All' : cat });
        chip.addEventListener('click', function () { pickerCategory = cat; apply(); });
        chips.appendChild(chip);
      });
      search.addEventListener('input', apply);
      picker.appendChild(el('div', { class: 'bk-picker-tools' }, [chips, search]));
      grid.appendChild(none);
      apply();
    } else if (pickerTab === 'sections') {
      var sections = presets.filter(function (p) { return p.kind === 'section'; });
      if (!sections.length) grid.appendChild(el('p', { class: 'bk-help', text: 'No sections yet.' }));
      sections.forEach(function (preset) {
        grid.appendChild(presetCard(preset, function () {
          var added = insertBlocks(preset.blocks, pickerTarget);
          closePicker(true);
          render(added[0]._id, 'toggle');
          if (window.adminToast) window.adminToast(preset.label + ' added.', 'success');
        }));
      });
    } else {
      var note = blocks.length
        ? 'This page already has ' + blocks.length + ' block(s). A layout can replace them or be added after them.'
        : 'Pick a layout to start the page. Replace the placeholder text and add images before publishing.';
      picker.appendChild(el('p', { class: 'bk-note-line', text: note }));
      presets.filter(function (p) { return p.kind === 'page'; }).forEach(function (preset) {
        grid.appendChild(presetCard(preset, function (chosen) {
          if (!blocks.length) {
            usePage(chosen, 'replace');
            return;
          }
          // Ask how to combine with existing blocks, inside the picker.
          picker.querySelectorAll('[data-page-choice]').forEach(function (n) { n.remove(); });
          var bar = el('div', { class: 'bk-note', 'data-page-choice': '' }, [
            el('span', { class: 'bk-grow', text: 'Use "' + chosen.label + '":' }),
            el('button', { type: 'button', class: CLS.btn, text: 'Add after existing blocks', onclick: function () { usePage(chosen, 'append'); } }),
            el('button', { type: 'button', class: CLS.btnPrimary, text: 'Replace existing blocks', onclick: function () { usePage(chosen, 'replace'); } })
          ]);
          picker.insertBefore(bar, grid);
          bar.querySelector('button').focus();
        }));
      });
    }
    picker.appendChild(grid);
  }

  function usePage(preset, mode) {
    var removed = null;
    if (mode === 'replace' && blocks.length) {
      removed = blocks.slice();
      blocks.length = 0;
    }
    var added = insertBlocks(preset.blocks, blocks.length);
    applyTemplate(preset.template);
    closePicker(true);
    render(added[0]._id, 'toggle');
    if (removed) {
      lastRemoved = { many: removed };
      showUndo('Previous blocks');
    }
    if (window.adminToast) window.adminToast(preset.label + ' added' + (preset.template ? ' (template: ' + preset.template + ')' : '') + '.', 'success');
  }

  function openPicker(position, returnTo) {
    pickerTarget = position;
    pickerReturn = returnTo || null;
    if (!blocks.length && presets.some(function (p) { return p.kind === 'page'; }) && pickerTab === 'blocks' && returnTo === addButton) {
      pickerTab = 'pages';
    }
    renderPicker();
    picker.classList.remove('hidden');
    var first = picker.querySelector('[aria-selected="true"]');
    if (first) first.focus();
  }

  function closePicker(silent) {
    picker.classList.add('hidden');
    if (silent !== true && pickerReturn) pickerReturn.focus();
  }

  picker.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      closePicker();
    }
  });

  /* Media picker ------------------------------------------------------------- */

  // Choosing a picture is the shared picker (admin-media-picker.js): a dialog that asks the server a page at a time.
  function openMediaPicker(callback, kind) {
    if (window.FarosMediaPicker) window.FarosMediaPicker.open(callback, kind);
  }

  /* Save selected blocks as a reusable section ---------------------------------- */

  function postPresets(fields) {
    var body = new FormData();
    Object.keys(fields).forEach(function (key) { body.append(key, fields[key]); });
    var tokenMeta = document.querySelector('meta[name="csrf-token"]');
    var token = tokenMeta ? tokenMeta.getAttribute('content') : '';
    body.append('_csrf', token);
    return fetch(data.presets_url, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': token, 'Accept': 'application/json' }
    }).then(function (response) { return response.json(); });
  }

  var saveName = el('input', { type: 'text', class: CLS.input + ' bk-save-input', placeholder: 'Section name', 'aria-label': 'Section name' });
  var saveDescription = el('input', { type: 'text', class: CLS.input + ' bk-save-input is-wide', placeholder: 'Short description (optional)', 'aria-label': 'Section description' });
  var saveButton = el('button', { type: 'button', class: CLS.btnPrimary, text: 'Save as section' });
  var clearSelection = el('button', { type: 'button', class: CLS.btn, text: 'Clear selection' });
  var saveCount = el('span', { class: 'bk-save-count' });
  var saveBar = el('div', { class: 'bk-savebar hidden', role: 'region', 'aria-label': 'Save selected blocks as a section' }, [saveCount, saveName, saveDescription, saveButton, clearSelection]);

  function syncSaveBar() {
    var count = blocks.filter(function (b) { return selected[b._id]; }).length;
    saveBar.classList.toggle('hidden', count === 0);
    saveCount.textContent = count + ' selected';
  }

  clearSelection.addEventListener('click', function () {
    selected = {};
    render(null);
    syncSaveBar();
  });

  saveButton.addEventListener('click', function () {
    var chosen = blocks.filter(function (b) { return selected[b._id]; });
    if (!saveName.value.trim()) {
      saveName.focus();
      if (window.adminToast) window.adminToast('Give the section a name.', 'error');
      return;
    }
    saveButton.disabled = true;
    postPresets({
      preset_action: 'save',
      name: saveName.value,
      description: saveDescription.value,
      lang: data.lang || '',
      blocks_json: JSON.stringify(chosen)
    }).then(function (res) {
      saveButton.disabled = false;
      if (!res.ok) {
        if (window.adminToast) window.adminToast(res.message || 'Could not save the section.', 'error');
        return;
      }
      if (res.preset) presets.push(res.preset);
      selected = {};
      saveName.value = '';
      saveDescription.value = '';
      render(null);
      syncSaveBar();
      if (window.adminToast) window.adminToast('Section saved. Find it under Add block → Ready-made sections.', 'success');
    }).catch(function () {
      saveButton.disabled = false;
      if (window.adminToast) window.adminToast('Could not save the section.', 'error');
    });
  });

  /* Template description (Basics tab) ------------------------------------------ */

  var templateSelect = document.querySelector('[data-template-select]');
  var templateDescription = document.querySelector('[data-template-description]');
  if (templateSelect && templateDescription) {
    templateSelect.addEventListener('change', function () {
      var option = templateSelect.options[templateSelect.selectedIndex];
      templateDescription.textContent = option ? option.getAttribute('data-description') || '' : '';
    });
  }

  /* Mount -------------------------------------------------------------------- */

  var addButton = el('button', { type: 'button', class: CLS.btnPrimary, html: svg('plus') + '<span>Add block</span>' });
  addButton.addEventListener('click', function () { openPicker(blocks.length, addButton); });
  var expandAll = el('button', { type: 'button', class: CLS.btn, text: 'Expand all' });
  expandAll.addEventListener('click', function () {
    var open = !blocks.every(function (b) { return b._open; });
    blocks.forEach(function (b) { b._open = open; });
    expandAll.textContent = open ? 'Collapse all' : 'Expand all';
    render(null);
  });

  root.innerHTML = '';
  root.appendChild(el('div', { class: 'bk-bar' }, [
    el('p', { class: 'bk-help', text: 'Sections of this page, top to bottom. An opening Hero becomes the page title. Tick blocks to save them as a reusable section.' }),
    el('div', { class: 'bk-bar-actions' }, [expandAll, addButton])
  ]));
  root.appendChild(undoBar);
  root.appendChild(saveBar);
  root.appendChild(picker);
  root.appendChild(empty);
  root.appendChild(list);

  flag.value = '1';
  render(null);
})();
