/*
 * Drag and drop reordering for the admin: the blocks of the block builder, the rows of a repeater inside a block, the short texts of
 * an inline repeater. One small module, no library. It uses pointer events, so a mouse, a finger and a pen all work, in every browser.
 *
 *   var list = AdminSortable.create({
 *     container: node,                    the element that holds the items
 *     items: '.card',                     selector of an item (its own items only: items of a list nested inside are not its own)
 *     handle: '.grip',                    selector of the grip button inside an item (drag with the pointer; Space or Enter picks up)
 *     surface: '.head',                   optional: pressing anywhere on this part of an item (not on a field or a link) drags it too, mouse and pen only
 *     surfaceActive: function (item) {},  optional: false lets one item refuse a surface drag (an open block, say); the grip still works
 *     ghost: '.head',                     optional: the part of an item shown lifted under the pointer (the whole item by default)
 *     label: function (item, index) {},   what the screen reader says for an item ("Block Text")
 *     group: 'blocks',                    optional: lists of one group exchange items; a list without a group is on its own
 *     onSort: function (info) {}          called once after a drop: {item, from, to, fromList, toList, via: 'pointer' | 'keyboard'}
 *   });
 *
 * The module never moves an item in the page: it shows where the item would land and calls onSort, and the caller changes its data
 * and draws the list again (that is how the builder works, and how a saved page keeps one order). `from` is the place the item
 * had in fromList and `to` the place it takes in toList, counted after it left its old place; fromList and toList are the lists
 * (the same one for a plain reorder, two lists of one group for an item that moved between them).
 *
 * Lists can be nested (a list inside an item of another list); the pointer drops into the innermost list under it, and an item
 * cannot be dropped into itself.
 *
 * Keyboard and screen readers: the grip is a button. Space or Enter picks the item up, the arrow keys (and Home and End) move it,
 * Space or Enter drops it, Escape puts it back. Every step is said through one polite live region. Moving between lists is for the
 * pointer; the keyboard moves an item within its own list.
 */
(function () {
  'use strict';

  var instances = [];
  var uid = 0;
  var state = null;       // the drag or keyboard move in progress (one at a time)
  var live = null;
  var flip = false;
  var line = null;

  var IGNORE = 'input, textarea, select, a[href], label, [contenteditable], button:not([data-drag-surface])';

  /* Pure parts (tested without a layout) --------------------------------------------------------- */

  /** The array with the item at `from` taken out and put back at `to`. */
  function reorder(list, from, to) {
    if (from === to || from < 0 || from >= list.length || to < 0 || to >= list.length) return list;
    list.splice(to, 0, list.splice(from, 1)[0]);
    return list;
  }

  /** True when another item of the list sits beside this one (on the same line), as blocks do that share a row. */
  function besideOthers(rects, index) {
    var r = rects[index];
    var height = r.bottom - r.top;
    return rects.some(function (o, j) {
      if (j === index) return false;
      var overlap = Math.min(r.bottom, o.bottom) - Math.max(r.top, o.top);
      return overlap > Math.min(height, o.bottom - o.top) * 0.5;
    });
  }

  /**
   * Where the pointer says an item goes: a slot from 0 (before the first item) to rects.length (after the last). The item under the
   * pointer (or the nearest one) decides: before it when the pointer is in its first half, after it in its second. The halves are
   * left and right for items that sit side by side, top and bottom otherwise.
   */
  function insertIndex(rects, x, y) {
    if (!rects.length) return 0;
    var best = 0;
    var bestDistance = Infinity;
    rects.forEach(function (r, i) {
      var dx = x < r.left ? r.left - x : (x > r.right ? x - r.right : 0);
      var dy = y < r.top ? r.top - y : (y > r.bottom ? y - r.bottom : 0);
      var d = dx * dx + dy * dy;
      if (d < bestDistance) { bestDistance = d; best = i; }
    });
    var r = rects[best];
    var after = besideOthers(rects, best) ? x > (r.left + r.right) / 2 : y > (r.top + r.bottom) / 2;
    return best + (after ? 1 : 0);
  }

  /** The place an item takes in its list when dropped into slot `slot` (a slot counts the item itself when it is in the list). */
  function slotToIndex(slot, from, same) {
    return same && slot > from ? slot - 1 : slot;
  }

  /** The box of the drop indicator for a slot: a horizontal line between two lines of items, a vertical one between two side by side. */
  function lineBox(rects, slot, box) {
    var t = 3;
    if (!rects.length) return { left: box.left, top: box.top, width: box.right - box.left, height: t };
    var i = Math.min(slot, rects.length - 1);
    var r = rects[i];
    var beside = besideOthers(rects, i);
    if (slot < rects.length) {
      return beside
        ? { left: r.left - t - 1, top: r.top, width: t, height: r.bottom - r.top }
        : { left: r.left, top: r.top - t - 1, width: r.right - r.left, height: t };
    }
    return beside
      ? { left: r.right + 1, top: r.top, width: t, height: r.bottom - r.top }
      : { left: r.left, top: r.bottom + 1, width: r.right - r.left, height: t };
  }

  /* The page ----------------------------------------------------------------------------------- */

  function ownerOf(node) {
    var parent = node.parentElement;
    return parent ? parent.closest('[data-sortable]') : null;
  }

  function rectOf(node) {
    return node.getBoundingClientRect();
  }

  /** Says something through the one live region, for screen readers. */
  function announce(text) {
    if (!live) {
      live = document.createElement('div');
      live.className = 'bk-sr';
      live.setAttribute('aria-live', 'polite');
      live.setAttribute('aria-atomic', 'true');
      document.body.appendChild(live);
    }
    // The same sentence twice in a row would not be read again.
    flip = !flip;
    live.textContent = text + (flip ? '' : ' ');
  }

  function indicator() {
    if (!line) {
      line = document.createElement('div');
      line.className = 'bk-drop-line';
      line.setAttribute('aria-hidden', 'true');
      line.hidden = true;
      document.body.appendChild(line);
    }
    return line;
  }

  function showLine(box) {
    var node = indicator();
    if (!box) { node.hidden = true; return; }
    node.hidden = false;
    node.style.left = box.left + 'px';
    node.style.top = box.top + 'px';
    node.style.width = box.width + 'px';
    node.style.height = box.height + 'px';
  }

  function scrollersOf(node) {
    var found = [];
    for (var p = node.parentElement; p && p !== document.body && p !== document.documentElement; p = p.parentElement) {
      var overflow = window.getComputedStyle(p).overflowY;
      if ((overflow === 'auto' || overflow === 'scroll') && p.scrollHeight > p.clientHeight) found.push(p);
    }
    return found;
  }

  function pull(distance, edge) {
    return Math.round(Math.min(22, 3 + (distance / edge) * 19));
  }

  /* One move at a time: its listeners live on the document and are the same functions for every list ----------- */

  function begin() {
    var s = state;
    var o = s.self.options;
    s.started = true;
    instances = instances.filter(function (inst) { return inst.container.isConnected; });
    var part = o.ghost ? s.item.querySelector(o.ghost) : null;
    var source = part || s.item;
    var rect = rectOf(source);
    var ghost = document.createElement('div');
    ghost.className = 'bk-ghost';
    var copy = source.cloneNode(true);
    Array.prototype.forEach.call(copy.querySelectorAll('[id]'), function (n) { n.removeAttribute('id'); });
    Array.prototype.forEach.call(copy.querySelectorAll('button, input, select, textarea, a'), function (n) { n.setAttribute('tabindex', '-1'); });
    ghost.appendChild(copy);
    ghost.setAttribute('aria-hidden', 'true');
    ghost.setAttribute('inert', '');
    ghost.style.width = Math.min(rect.width || 360, 560) + 'px';
    document.body.appendChild(ghost);
    s.ghost = ghost;
    s.offX = Math.min(Math.max(s.startX - rect.left, 8), Math.max((rect.width || 360) - 8, 8));
    s.offY = Math.min(Math.max(s.startY - rect.top, 4), Math.max((rect.height || 40) - 4, 4));
    s.item.classList.add('is-sorting');
    document.documentElement.classList.add('is-sorting-active');
    try { s.handle.setPointerCapture(s.pointerId); } catch (e) { /* not capturable: the document listeners still see it */ }
    if (window.requestAnimationFrame) s.raf = window.requestAnimationFrame(scrollStep);
  }

  function onPointerMove(event) {
    if (!state || state.via !== 'pointer' || event.pointerId !== state.pointerId) return;
    state.x = event.clientX;
    state.y = event.clientY;
    if (!state.started) {
      if (Math.sqrt(Math.pow(state.x - state.startX, 2) + Math.pow(state.y - state.startY, 2)) < state.self.options.threshold) return;
      begin();
    }
    if (event.cancelable) event.preventDefault();
    update();
  }

  function onPointerUp(event) {
    if (!state || state.via !== 'pointer' || event.pointerId !== state.pointerId) return;
    var s = state;
    if (!s.started) { stop(s); return; }
    s.x = event.clientX;
    s.y = event.clientY;
    update();
    // The click that follows a drag started on a button (a row's title) must not open or close it.
    var swallow = function (e) { e.stopPropagation(); e.preventDefault(); };
    document.addEventListener('click', swallow, true);
    window.setTimeout(function () { document.removeEventListener('click', swallow, true); }, 60);
    finish(s);
  }

  function onPointerCancel(event) {
    if (state && state.via === 'pointer' && event.pointerId === state.pointerId) cancel(false);
  }

  function onScroll() {
    if (state && state.started && state.via === 'pointer') update();
  }

  function onEscape(event) {
    if (state && event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      cancel(true);
    }
  }

  function scrollStep() {
    var s = state;
    if (!s || !s.started || s.via !== 'pointer') return;
    var edge = s.self.options.edge;
    var moved = false;
    scrollersOf(s.self.container).forEach(function (node) {
      var r = rectOf(node);
      if (s.y < r.top - edge || s.y > r.bottom + edge) return;
      var d = 0;
      if (s.y < r.top + edge) d = -pull(r.top + edge - s.y, edge);
      else if (s.y > r.bottom - edge) d = pull(s.y - (r.bottom - edge), edge);
      if (d) { var before = node.scrollTop; node.scrollTop += d; if (node.scrollTop !== before) moved = true; }
    });
    var height = window.innerHeight || document.documentElement.clientHeight;
    var dw = 0;
    if (s.y < edge) dw = -pull(edge - s.y, edge);
    else if (s.y > height - edge) dw = pull(s.y - (height - edge), edge);
    if (dw && window.scrollBy) {
      var top = window.pageYOffset;
      window.scrollBy(0, dw);
      if (window.pageYOffset !== top) moved = true;
    }
    if (moved) update();
    s.raf = window.requestAnimationFrame(scrollStep);
  }

  /** Where the item would land for the pointer: the list under it (the innermost of its group), the slot in that list, the indicator. */
  function update() {
    var s = state;
    if (!s || !s.started) return;
    s.ghost.style.transform = 'translate(' + Math.round(s.x - s.offX) + 'px,' + Math.round(s.y - s.offY) + 'px)';
    var hit = null;
    var area = Infinity;
    instances.forEach(function (inst) {
      if (inst.group !== s.self.group || !inst.container.isConnected || s.item.contains(inst.container)) return;
      var r = rectOf(inst.container);
      if (s.x >= r.left && s.x <= r.right && s.y >= r.top && s.y <= r.bottom && r.width * r.height < area) {
        area = r.width * r.height;
        hit = inst;
      }
    });
    if (!hit) hit = s.target;
    var rects = hit.items().map(rectOf);
    var same = hit === s.self;
    s.target = hit;
    s.slot = insertIndex(rects, s.x, s.y);
    s.to = slotToIndex(s.slot, s.from, same);
    showLine(same && s.to === s.from ? null : lineBox(rects, s.slot, rectOf(hit.container)));
  }

  function stop(s) {
    state = null;
    if (s.raf && window.cancelAnimationFrame) window.cancelAnimationFrame(s.raf);
    if (s.ghost) s.ghost.remove();
    s.item.classList.remove('is-sorting');
    if (s.via === 'keyboard' && s.handle) s.handle.removeAttribute('aria-pressed');
    document.documentElement.classList.remove('is-sorting-active');
    showLine(null);
    document.removeEventListener('pointermove', onPointerMove);
    document.removeEventListener('pointerup', onPointerUp);
    document.removeEventListener('pointercancel', onPointerCancel);
    document.removeEventListener('keydown', onEscape, true);
    window.removeEventListener('scroll', onScroll, true);
  }

  function listen() {
    document.addEventListener('pointermove', onPointerMove);
    document.addEventListener('pointerup', onPointerUp);
    document.addEventListener('pointercancel', onPointerCancel);
    document.addEventListener('keydown', onEscape, true);
    window.addEventListener('scroll', onScroll, true);
  }

  function finish(s) {
    var from = s.self;
    var to = s.target;
    var same = from === to;
    var size = to.items().length + (same ? 0 : 1);
    var info = { item: s.item, from: s.from, to: s.to, fromList: from, toList: to, via: s.via };
    var label = s.label;
    stop(s);
    if (same && s.to === s.from) {
      if (s.via === 'keyboard') announce(label + ' dropped, still at position ' + (s.from + 1) + '.');
      return;
    }
    announce(label + ' moved to position ' + (s.to + 1) + ' of ' + size + (same ? '' : ' in another list') + '.');
    if (to.options.onSort) to.options.onSort(info);
  }

  function cancel(spoken) {
    var s = state;
    if (!s) return;
    var label = s.label;
    var position = s.from + 1;
    stop(s);
    if (spoken) announce('Move cancelled. ' + label + ' is back at position ' + position + '.');
  }

  function create(options) {
    var o = Object.assign({ handle: '', threshold: 4, edge: 56 }, options);
    var container = o.container;
    var self = { container: container, group: o.group || ('sortable-' + (++uid)), options: o };
    container.setAttribute('data-sortable', self.group);
    instances.push(self);

    self.items = function () {
      return Array.prototype.filter.call(container.querySelectorAll(o.items), function (node) { return ownerOf(node) === container; });
    };

    function nameOf(item, index) {
      if (o.label) return o.label(item, index);
      return item.getAttribute('aria-label') || (item.textContent || '').trim().slice(0, 40);
    }

    function itemOf(target) {
      return target && target.closest ? target.closest(o.items) : null;
    }

    function onPointerDown(event) {
      if (state || event.isPrimary === false) return;
      if (event.pointerType === 'mouse' && event.button !== 0) return;
      var target = event.target;
      if (!target || !target.closest) return;
      var handle = o.handle ? target.closest(o.handle) : null;
      var item = itemOf(handle || target);
      if (!item || ownerOf(item) !== container) return;
      if (handle && !item.contains(handle)) return;
      if (!handle) {
        var surface = o.surface ? target.closest(o.surface) : null;
        if (!surface || !item.contains(surface) || event.pointerType === 'touch') return;
        if (o.surfaceActive && !o.surfaceActive(item)) return;
        if (target.closest(IGNORE) && !target.closest('[data-drag-surface]')) return;
      }
      var from = self.items().indexOf(item);
      state = {
        self: self, item: item, from: from, via: 'pointer', started: false, pointerId: event.pointerId,
        startX: event.clientX, startY: event.clientY, x: event.clientX, y: event.clientY,
        handle: handle || target, label: nameOf(item, from), target: self, to: from, slot: from, raf: 0
      };
      listen();
    }

    function pickUp(item) {
      if (state) return;
      var items = self.items();
      var from = items.indexOf(item);
      if (from < 0) return;
      var handle = o.handle ? item.querySelector(o.handle) : null;
      state = { self: self, item: item, from: from, to: from, slot: from, via: 'keyboard', started: true, handle: handle, label: nameOf(item, from), target: self, raf: 0 };
      item.classList.add('is-sorting');
      if (handle) handle.setAttribute('aria-pressed', 'true');
      document.addEventListener('keydown', onEscape, true);
      announce(state.label + ' picked up, position ' + (from + 1) + ' of ' + items.length + '. Use the arrow keys to move it, Space to drop it, Escape to cancel.');
    }

    function stepTo(to) {
      var s = state;
      var items = self.items();
      to = Math.max(0, Math.min(items.length - 1, to));
      if (to === s.to) return;
      s.to = to;
      s.slot = to > s.from ? to + 1 : to;
      showLine(to === s.from ? null : lineBox(items.map(rectOf), s.slot, rectOf(container)));
      if (items[to] && items[to].scrollIntoView) items[to].scrollIntoView({ block: 'nearest' });
      announce(s.label + ' moved to position ' + (to + 1) + ' of ' + items.length);
    }

    function onKeyDown(event) {
      var handle = event.target && event.target.closest ? event.target.closest(o.handle) : null;
      var item = itemOf(handle);
      if (!handle || !item || ownerOf(item) !== container || !state || state.via !== 'keyboard' || state.item !== item) return;
      var key = event.key;
      if (key === ' ' || key === 'Enter') {
        event.preventDefault();
        if (key === ' ') {
          // The click a browser makes when Space is released must not pick the item up again.
          var quiet = function (e) { e.preventDefault(); document.removeEventListener('keyup', quiet, true); };
          document.addEventListener('keyup', quiet, true);
        }
        finish(state);
      } else if (key === 'ArrowUp' || key === 'ArrowLeft') {
        event.preventDefault();
        stepTo(state.to - 1);
      } else if (key === 'ArrowDown' || key === 'ArrowRight') {
        event.preventDefault();
        stepTo(state.to + 1);
      } else if (key === 'Home') {
        event.preventDefault();
        stepTo(0);
      } else if (key === 'End') {
        event.preventDefault();
        stepTo(self.items().length - 1);
      } else if (key === 'Tab') {
        cancel(true);
      }
    }

    function onClick(event) {
      var handle = event.target && event.target.closest ? event.target.closest(o.handle) : null;
      var item = itemOf(handle);
      if (!handle || !item || ownerOf(item) !== container) return;
      // A click with no pointer behind it (detail 0) is a key press or an assistive technology: it picks the item up.
      if (event.detail !== 0 || state) return;
      pickUp(item);
    }

    function onFocusOut(event) {
      if (state && state.via === 'keyboard' && state.self === self && event.target === state.handle) cancel(true);
    }

    if (o.handle) {
      container.addEventListener('keydown', onKeyDown);
      container.addEventListener('click', onClick);
      container.addEventListener('focusout', onFocusOut);
    }
    container.addEventListener('pointerdown', onPointerDown);

    self.pickUp = pickUp;
    self.destroy = function () {
      container.removeEventListener('pointerdown', onPointerDown);
      container.removeEventListener('keydown', onKeyDown);
      container.removeEventListener('click', onClick);
      container.removeEventListener('focusout', onFocusOut);
      container.removeAttribute('data-sortable');
      instances = instances.filter(function (inst) { return inst !== self; });
    };
    return self;
  }

  window.AdminSortable = {
    create: create,
    announce: announce,
    reorder: reorder,
    insertIndex: insertIndex,
    slotToIndex: slotToIndex,
    lineBox: lineBox
  };
})();
