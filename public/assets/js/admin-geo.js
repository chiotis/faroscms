/*
 * Location fields: a position typed or pasted ("35.2012, 26.2744", as Google Maps and OpenStreetMap copy it), checked as it is
 * typed, with a button that opens a map to click the place on. The value is written the one way the site reads it.
 *
 *   <input data-location-field>     gets the check and the Map button automatically
 *
 * The map is Leaflet from the theme, fetched only when the button is pressed.
 */
(function () {
  'use strict';

  var meta = document.querySelector('meta[name="geo-leaflet"]');
  var leafletJs = meta ? meta.getAttribute('data-js') : '';
  var leafletCss = meta ? meta.getAttribute('data-css') : '';
  var tiles = meta ? meta.getAttribute('data-tiles') : 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
  var CLS = 'inline-flex shrink-0 items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-500/30';

  function parse(text) {
    var m = /^\s*(?:geo:)?\s*(-?\d{1,3}(?:\.\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:\.\d+)?)/.exec(text || '');
    if (!m) return null;
    var lat = parseFloat(m[1]);
    var lng = parseFloat(m[2]);
    if (Math.abs(lat) > 90 || Math.abs(lng) > 180 || (lat === 0 && lng === 0)) return null;
    return { lat: lat, lng: lng };
  }

  function format(lat, lng) {
    var trim = function (n) { return String(Math.round(n * 1e6) / 1e6); };
    return trim(lat) + ', ' + trim(lng);
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  var loading = null;
  function leaflet() {
    if (window.L) return Promise.resolve();
    if (loading) return loading;
    if (leafletCss && !document.querySelector('link[href="' + leafletCss + '"]')) {
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = leafletCss;
      document.head.appendChild(link);
    }
    loading = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = leafletJs;
      s.onload = resolve;
      s.onerror = reject;
      document.head.appendChild(s);
    });
    return loading;
  }

  var dialog = null;
  var map = null;
  var marker = null;
  var chosen = null;
  var onUse = null;
  var useButton = null;
  var readout = null;

  function build() {
    dialog = document.createElement('dialog');
    dialog.className = 'geo-picker rounded-lg border border-slate-200 p-0 shadow-xl';
    dialog.setAttribute('aria-label', 'Choose a place on the map');
    var head = el('div', 'flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3');
    head.appendChild(el('h2', 'm-0 text-base font-semibold text-slate-900', 'Choose a place on the map'));
    var close = el('button', CLS, 'Close');
    close.type = 'button';
    close.addEventListener('click', function () { dialog.close(); });
    head.appendChild(close);
    var body = el('div', 'p-4');
    var canvas = el('div', 'geo-picker-map rounded-md border border-slate-200 bg-slate-100');
    canvas.id = 'geo-picker-map';
    canvas.setAttribute('role', 'application');
    canvas.setAttribute('aria-label', 'Map: click to place the marker');
    body.appendChild(canvas);
    readout = el('p', 'mt-2 mb-0 text-sm text-slate-600', 'Click the map to place the marker. Zoom in for a more exact place.');
    body.appendChild(readout);
    var foot = el('div', 'flex justify-end gap-2 border-t border-slate-200 px-4 py-3');
    useButton = el('button', 'inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 disabled:opacity-40', 'Use this place');
    useButton.type = 'button';
    useButton.disabled = true;
    useButton.addEventListener('click', function () {
      if (chosen && onUse) onUse(chosen);
      dialog.close();
    });
    foot.appendChild(useButton);
    dialog.appendChild(head);
    dialog.appendChild(body);
    dialog.appendChild(foot);
    document.body.appendChild(dialog);
  }

  function open(start, callback) {
    if (!dialog) build();
    onUse = callback;
    chosen = start;
    useButton.disabled = !start;
    readout.textContent = start ? format(start.lat, start.lng) : 'Click the map to place the marker. Zoom in for a more exact place.';
    dialog.showModal();
    leaflet().then(function () {
      var L = window.L;
      var canvas = document.getElementById('geo-picker-map');
      if (!map) {
        map = L.map(canvas, { zoomControl: true });
        L.tileLayer(tiles, { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
        map.on('click', function (event) {
          chosen = { lat: event.latlng.lat, lng: event.latlng.lng };
          place(chosen);
        });
      }
      if (marker) { map.removeLayer(marker); marker = null; }
      if (start) {
        place(start);
        map.setView([start.lat, start.lng], 15);
      } else {
        map.setView([38.2, 24.5], 6);
      }
      setTimeout(function () { map.invalidateSize(); }, 50);
    }).catch(function () {
      readout.textContent = 'The map could not be loaded. Type the coordinates instead.';
    });
  }

  function place(position) {
    var L = window.L;
    if (marker) marker.setLatLng([position.lat, position.lng]);
    else marker = L.marker([position.lat, position.lng]).addTo(map);
    useButton.disabled = false;
    readout.textContent = format(position.lat, position.lng);
  }

  function enhance(input) {
    if (input.dataset.locationReady === '1') return;
    input.dataset.locationReady = '1';
    var wrap = el('span', 'flex items-center gap-2');
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    input.classList.add('min-w-0', 'flex-1');
    var button = el('button', CLS, 'Map');
    button.type = 'button';
    button.setAttribute('aria-label', 'Choose the place on a map');
    wrap.appendChild(button);
    var note = el('span', 'mt-1 block text-xs text-slate-400');
    note.setAttribute('aria-live', 'polite');
    wrap.parentNode.appendChild(note);

    var check = function (final) {
      var value = input.value.trim();
      if (value === '') { note.textContent = ''; input.setCustomValidity(''); return; }
      var position = parse(value);
      if (!position) {
        note.textContent = 'This is not a position. Write the latitude and the longitude, for example 35.2012, 26.2744.';
        note.className = 'mt-1 block text-xs text-red-600';
        input.setCustomValidity('Not a position');
        return;
      }
      input.setCustomValidity('');
      note.className = 'mt-1 block text-xs text-slate-400';
      note.textContent = '';
      if (final) input.value = format(position.lat, position.lng);
    };
    input.addEventListener('input', function () { check(false); });
    input.addEventListener('change', function () { check(true); });
    button.addEventListener('click', function () {
      open(parse(input.value), function (position) {
        input.value = format(position.lat, position.lng);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.focus();
      });
    });
    check(false);
  }

  function scan(root) {
    if (root.nodeType !== 1) return;
    if (root.matches && root.matches('input[data-location-field]')) enhance(root);
    if (root.querySelectorAll) root.querySelectorAll('input[data-location-field]').forEach(enhance);
  }

  scan(document.body);
  new MutationObserver(function (records) {
    records.forEach(function (record) { record.addedNodes.forEach(scan); });
  }).observe(document.body, { childList: true, subtree: true });
}());
