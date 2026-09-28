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
  var media = data.media || [];
  var nextId = 1;
  var blocks = (data.blocks || []).map(function (block) { return withId(block, false); });
  var lastRemoved = null;

  var CLS = {
    input: 'w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-slate-500 focus:ring-2 focus:ring-slate-300',
    label: 'mb-1 block text-sm font-medium text-slate-700',
    help: 'mt-1 block text-xs text-slate-400',
    btn: 'inline-flex shrink-0 items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40',
    btnPrimary: 'inline-flex shrink-0 items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700',
    icon: 'inline-grid h-8 w-8 place-items-center rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 disabled:opacity-30 disabled:hover:bg-transparent',
    iconDanger: 'inline-grid h-8 w-8 place-items-center rounded-md text-slate-500 transition hover:bg-red-50 hover:text-red-600'
  };

  var ICONS = {
    up: '<path d="m6 15 6-6 6 6"/>',
    down: '<path d="m6 9 6 6 6-6"/>',
    copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    chevron: '<path d="m9 6 6 6-6 6"/>',
    eyeOff: '<path d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 5.1A9.8 9.8 0 0 1 12 5c5 0 9 5 9 7a9.6 9.6 0 0 1-2.5 3.5M6.2 6.2C4.3 7.5 3 9.5 3 12c0 2 4 7 9 7a9.3 9.3 0 0 0 4.3-1"/>',
    image: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>'
  };

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

  function fieldControl(field, value, onChange, idBase) {
    var id = idBase + '-' + field.key;
    var type = field.type;
    var control;

    if (type === 'repeater') return repeaterControl(field, Array.isArray(value) ? value : [], onChange, id);

    if (type === 'toggle') {
      var checkbox = el('input', { type: 'checkbox', id: id, class: 'h-4 w-4 rounded border-slate-300', checked: !!value });
      checkbox.addEventListener('change', function () { onChange(checkbox.checked); });
      return el('label', { class: 'flex items-start justify-between gap-4 rounded-md border border-slate-100 px-3 py-2.5 ' + (field.span === 'full' ? 'col-span-full' : ''), for: id }, [
        el('span', {}, [
          el('span', { class: 'block text-sm font-medium text-slate-800', text: field.label }),
          field.help ? el('span', { class: 'block text-xs text-slate-400', text: field.help }) : null
        ]),
        checkbox
      ]);
    }

    if (type === 'select') {
      control = el('select', { id: id, class: CLS.input });
      (field.options || []).forEach(function (pair) {
        control.appendChild(el('option', { value: pair[0], text: pair[1], selected: String(value) === pair[0] }));
      });
      control.addEventListener('change', function () { onChange(control.value); });
    } else if (type === 'textarea' || type === 'markdown') {
      control = el('textarea', {
        id: id,
        rows: type === 'markdown' ? 6 : 3,
        placeholder: field.placeholder || '',
        class: CLS.input + (type === 'markdown' ? ' font-mono text-[13px] leading-6' : '')
      });
      control.value = value == null ? '' : String(value);
      control.addEventListener('input', function () { onChange(control.value); });
    } else {
      var inputType = type === 'number' ? 'number' : (type === 'email' ? 'email' : 'text');
      control = el('input', {
        id: id,
        type: inputType,
        placeholder: field.placeholder || '',
        min: type === 'number' && field.min !== null ? field.min : null,
        max: type === 'number' && field.max !== null ? field.max : null,
        inputmode: type === 'decimal' ? 'decimal' : null,
        class: CLS.input
      });
      control.value = value == null ? '' : String(value);
      control.addEventListener('input', function () {
        var raw = control.value;
        onChange(type === 'number' && raw !== '' ? Number(raw) : raw);
      });
    }

    var wide = field.span === 'full' || type === 'textarea' || type === 'markdown' || type === 'image';
    var labelText = field.label + (type === 'markdown' ? ' (Markdown)' : '') + (field.required ? ' *' : '');
    var wrapper = el('div', { class: wide ? 'col-span-full' : '' }, [
      el('label', { class: CLS.label, for: id, text: labelText })
    ]);

    if (type === 'image') {
      var preview = el('img', { alt: '', class: 'h-16 w-24 shrink-0 rounded border border-slate-200 bg-slate-50 object-cover' + (value ? '' : ' hidden') });
      if (value) preview.src = value;
      var sync = function () {
        preview.classList.toggle('hidden', !control.value);
        if (control.value) preview.src = control.value;
      };
      control.addEventListener('input', sync);
      var choose = el('button', { type: 'button', class: CLS.btn, html: svg('image') + '<span>Library</span>' });
      choose.addEventListener('click', function () {
        openMediaPicker(function (url) {
          control.value = url;
          onChange(url);
          sync();
        });
      });
      wrapper.appendChild(el('div', { class: 'flex items-center gap-2' }, [preview, control, choose]));
    } else {
      wrapper.appendChild(control);
    }
    if (field.help) wrapper.appendChild(el('span', { class: CLS.help, text: field.help }));
    return wrapper;
  }

  function repeaterControl(field, rows, onChange, id) {
    var list = el('div', { class: 'space-y-2' });
    var wrapper = el('div', { class: 'col-span-full rounded-md border border-slate-200 bg-slate-50/60 p-3' }, [
      el('div', { class: 'mb-2 flex items-center justify-between gap-2' }, [
        el('span', { class: 'text-sm font-medium text-slate-700', text: field.label }),
        el('span', { class: 'text-xs text-slate-400', text: rows.length + (field.max ? ' / ' + field.max : '') })
      ]),
      list
    ]);

    function commit() {
      onChange(rows);
      var fresh = repeaterControl(field, rows, onChange, id);
      wrapper.replaceWith(fresh);
      return fresh;
    }

    rows.forEach(function (row, index) {
      var rowId = id + '-' + index;
      var title = '';
      (field.fields || []).some(function (sub) {
        var v = row[sub.key];
        if (typeof v === 'string' && v.trim() !== '' && sub.type !== 'image' && sub.type !== 'select') {
          title = v.trim().replace(/\s+/g, ' ');
          return true;
        }
        return false;
      });
      var body = el('div', { class: 'grid grid-cols-1 gap-3 p-3 sm:grid-cols-2' });
      (field.fields || []).forEach(function (sub) {
        body.appendChild(fieldControl(sub, row[sub.key], function (value) {
          row[sub.key] = value;
          onChange(rows);
        }, rowId));
      });
      var moveUp = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Move ' + (field.item_label || 'item') + ' up', disabled: index === 0, html: svg('up') });
      var moveDown = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Move ' + (field.item_label || 'item') + ' down', disabled: index === rows.length - 1, html: svg('down') });
      var remove = el('button', { type: 'button', class: CLS.iconDanger, 'aria-label': 'Remove ' + (field.item_label || 'item'), html: svg('trash') });
      moveUp.addEventListener('click', function () {
        rows.splice(index - 1, 0, rows.splice(index, 1)[0]);
        var fresh = commit();
        var target = fresh.querySelectorAll('[aria-label^="Move"]')[Math.max(0, (index - 1) * 2)];
        if (target) target.focus();
      });
      moveDown.addEventListener('click', function () {
        rows.splice(index + 1, 0, rows.splice(index, 1)[0]);
        var fresh = commit();
        var target = fresh.querySelectorAll('[aria-label^="Move"]')[(index + 1) * 2 + 1];
        if (target) target.focus();
      });
      remove.addEventListener('click', function () {
        rows.splice(index, 1);
        commit();
      });
      list.appendChild(el('div', { class: 'rounded-md border border-slate-200 bg-white' }, [
        el('div', { class: 'flex items-center gap-1 border-b border-slate-100 px-3 py-1.5' }, [
          el('span', { class: 'min-w-0 flex-1 truncate text-xs font-medium text-slate-500', text: (field.item_label || 'Item') + ' ' + (index + 1) + (title ? ' · ' + title : '') }),
          moveUp, moveDown, remove
        ]),
        body
      ]));
    });

    var full = field.max && rows.length >= field.max;
    var add = el('button', { type: 'button', class: CLS.btn + ' mt-2', disabled: !!full, html: svg('plus') + '<span>Add ' + (field.item_label || 'item').toLowerCase() + '</span>' });
    add.addEventListener('click', function () {
      rows.push(defaultsFor(field.fields));
      var fresh = commit();
      var inputs = fresh.querySelectorAll('input, textarea, select');
      var firstOfNew = fresh.querySelectorAll('.space-y-2 > div');
      var last = firstOfNew[firstOfNew.length - 1];
      var focusable = last ? last.querySelector('input, textarea, select') : inputs[0];
      if (focusable) focusable.focus();
    });
    wrapper.appendChild(add);
    return wrapper;
  }

  /* Blocks ------------------------------------------------------------------ */

  function renderBlock(block, index) {
    var def = definitions[block.type];
    var idBase = 'blk-' + block._id;
    var card = el('section', { class: 'rounded-lg border ' + (block.hidden ? 'border-dashed border-slate-300 bg-slate-50' : 'border-slate-200 bg-white'), 'data-block-card': block._id });

    var label = def ? def.label : 'Unknown block: ' + block.type;
    var summary = def ? summaryOf(block) : 'Kept as is. Its definition is not installed.';
    var toggle = el('button', {
      type: 'button',
      class: 'flex min-w-0 flex-1 items-center gap-2 rounded-md px-1 py-1 text-left hover:bg-slate-50',
      'aria-expanded': block._open ? 'true' : 'false',
      'aria-controls': idBase + '-body',
      html: '<span class="shrink-0 transition ' + (block._open ? 'rotate-90' : '') + '">' + svg('chevron') + '</span>'
    });
    toggle.appendChild(el('span', { class: 'shrink-0 text-sm font-semibold text-slate-900', text: (index + 1) + '. ' + label }));
    if (def && block.variant && def.common) {
      var variantField = def.common.filter(function (f) { return f.key === 'variant'; })[0];
      if (variantField && variantField.options.length > 1) {
        toggle.appendChild(el('span', { class: 'shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-500', text: optionLabel(variantField, block.variant) }));
      }
    }
    if (block.hidden) {
      toggle.appendChild(el('span', { class: 'inline-flex shrink-0 items-center gap-1 rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-700', html: svg('eyeOff', 'h-3 w-3') + 'Hidden' }));
    }
    if (summary) toggle.appendChild(el('span', { class: 'min-w-0 truncate text-sm text-slate-400', text: summary }));
    toggle.addEventListener('click', function () {
      block._open = !block._open;
      render(block._id, 'toggle');
    });

    var actions = el('div', { class: 'flex shrink-0 items-center' });
    var up = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Move block up', disabled: index === 0, html: svg('up'), 'data-action': 'up' });
    var down = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Move block down', disabled: index === blocks.length - 1, html: svg('down'), 'data-action': 'down' });
    var insert = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Add a block below', html: svg('plus'), 'data-action': 'insert' });
    var duplicate = el('button', { type: 'button', class: CLS.icon, 'aria-label': 'Duplicate block', html: svg('copy'), disabled: !def, 'data-action': 'duplicate' });
    var remove = el('button', { type: 'button', class: CLS.iconDanger, 'aria-label': 'Remove block', html: svg('trash'), 'data-action': 'remove' });
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
    [up, down, insert, duplicate, remove].forEach(function (b) { actions.appendChild(b); });

    card.appendChild(el('div', { class: 'flex items-center gap-2 px-2 py-1.5' }, [toggle, actions]));

    if (block._open && def) {
      var body = el('div', { id: idBase + '-body', class: 'space-y-4 border-t border-slate-100 p-4' });
      var layout = el('div', { class: 'grid grid-cols-1 gap-3 rounded-md bg-slate-50 p-3 sm:grid-cols-2 lg:grid-cols-5' });
      def.common.forEach(function (field) {
        if (field.key === 'variant' && field.options.length < 2) return;
        layout.appendChild(fieldControl(Object.assign({}, field, { span: '' }), block[field.key], function (value) {
          block[field.key] = value;
          serialize();
          if (field.key === 'hidden' || field.key === 'variant') render(block._id, 'keep');
        }, idBase));
      });
      body.appendChild(layout);
      if (def.description) body.appendChild(el('p', { class: 'text-xs text-slate-400', text: def.description }));
      var fields = el('div', { class: 'grid grid-cols-1 gap-3 sm:grid-cols-2' });
      def.fields.forEach(function (field) {
        fields.appendChild(fieldControl(field, block[field.key], function (value) {
          block[field.key] = value;
          serialize();
        }, idBase));
      });
      body.appendChild(fields);
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

  var list = el('div', { class: 'space-y-2', 'data-block-list': '' });
  var empty = el('div', { class: 'rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center' }, [
    el('p', { class: 'text-sm font-medium text-slate-700', text: 'This page has no blocks yet.' }),
    el('p', { class: 'mt-1 text-xs text-slate-400', text: 'Without blocks the page shows its Markdown text as before. Add blocks to build the page from sections.' })
  ]);
  var undoBar = el('div', { class: 'hidden flex items-center justify-between gap-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800', role: 'status' });

  function showUndo(label) {
    undoBar.innerHTML = '';
    undoBar.appendChild(el('span', { text: label + ' removed.' }));
    var undo = el('button', { type: 'button', class: 'font-semibold underline', text: 'Undo' });
    undo.addEventListener('click', function () {
      if (!lastRemoved) return;
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

  var picker = el('div', { class: 'hidden rounded-lg border border-slate-200 bg-white p-3 shadow-sm', 'data-block-picker': '' });
  var pickerTarget = blocks.length;
  var pickerReturn = null;

  function openPicker(position, returnTo) {
    pickerTarget = position;
    pickerReturn = returnTo || null;
    picker.innerHTML = '';
    picker.appendChild(el('div', { class: 'mb-3 flex items-center justify-between' }, [
      el('span', { class: 'text-sm font-semibold text-slate-900', text: 'Add a block' + (position < blocks.length ? ' at position ' + (position + 1) : '') }),
      el('button', { type: 'button', class: CLS.btn, text: 'Cancel', onclick: closePicker })
    ]));
    var grid = el('div', { class: 'grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3' });
    Object.keys(definitions).forEach(function (type) {
      var def = definitions[type];
      var option = el('button', { type: 'button', class: 'rounded-md border border-slate-200 px-3 py-2.5 text-left transition hover:border-blue-300 hover:bg-blue-50/50' }, [
        el('span', { class: 'block text-sm font-semibold text-slate-900', text: def.label + (def.origin === 'custom' ? ' (custom)' : '') }),
        el('span', { class: 'mt-0.5 block text-xs text-slate-500', text: def.description })
      ]);
      option.addEventListener('click', function () {
        var block = newBlock(type);
        blocks.splice(pickerTarget, 0, block);
        closePicker(true);
        render(block._id, 'toggle');
      });
      grid.appendChild(option);
    });
    picker.appendChild(grid);
    picker.classList.remove('hidden');
    var first = grid.querySelector('button');
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

  var mediaDialog = null;
  var mediaCallback = null;

  function openMediaPicker(callback) {
    mediaCallback = callback;
    if (!mediaDialog) {
      mediaDialog = el('dialog', { class: 'w-[min(92vw,56rem)] rounded-lg border border-slate-200 p-0 shadow-xl backdrop:bg-slate-900/40', 'aria-label': 'Choose an image' });
      var search = el('input', { type: 'search', placeholder: 'Filter by name…', class: CLS.input, 'aria-label': 'Filter images by name' });
      var grid = el('div', { class: 'grid max-h-[60vh] grid-cols-2 gap-3 overflow-y-auto p-4 sm:grid-cols-3 md:grid-cols-5' });
      var fill = function () {
        var term = search.value.trim().toLowerCase();
        grid.innerHTML = '';
        var shown = media.filter(function (item) { return !term || item.name.toLowerCase().indexOf(term) !== -1; });
        if (shown.length === 0) {
          grid.appendChild(el('p', { class: 'col-span-full text-sm text-slate-500', text: media.length ? 'No images match.' : 'The media library has no images yet. Upload them in Media.' }));
        }
        shown.forEach(function (item) {
          var button = el('button', { type: 'button', class: 'overflow-hidden rounded-md border border-slate-200 bg-white text-left transition hover:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500' }, [
            el('img', { src: item.thumb || item.url, alt: '', class: 'h-24 w-full object-cover', loading: 'lazy' }),
            el('span', { class: 'block truncate px-2 py-1 text-[11px] text-slate-600', text: item.name })
          ]);
          button.setAttribute('aria-label', item.name + (item.alt ? ': ' + item.alt : ''));
          button.addEventListener('click', function () {
            mediaDialog.close();
            if (mediaCallback) mediaCallback(item.url);
          });
          grid.appendChild(button);
        });
      };
      search.addEventListener('input', fill);
      mediaDialog.appendChild(el('div', { class: 'flex items-center gap-3 border-b border-slate-200 p-4' }, [
        el('h2', { class: 'shrink-0 text-sm font-semibold text-slate-900', text: 'Choose an image' }),
        search,
        el('button', { type: 'button', class: CLS.btn, text: 'Close', onclick: function () { mediaDialog.close(); } })
      ]));
      mediaDialog.appendChild(grid);
      document.body.appendChild(mediaDialog);
      mediaDialog._fill = fill;
      mediaDialog._search = search;
    }
    mediaDialog._search.value = '';
    mediaDialog._fill();
    mediaDialog.showModal();
    mediaDialog._search.focus();
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
  root.appendChild(el('div', { class: 'flex flex-wrap items-center justify-between gap-2' }, [
    el('p', { class: 'text-xs text-slate-500', text: 'Sections of this page, top to bottom. An opening Hero becomes the page title. Changes are saved with the page.' }),
    el('div', { class: 'flex gap-2' }, [expandAll, addButton])
  ]));
  root.appendChild(undoBar);
  root.appendChild(picker);
  root.appendChild(empty);
  root.appendChild(list);

  flag.value = '1';
  render(null);
})();
