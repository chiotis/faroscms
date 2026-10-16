/*
 * Theme > Branding: export, import with a preview, undo, and the saved reset of a section.
 * The server does the work (POST /admin/theme?branding=preview|import|undo|reset); this script only asks, shows the answer and reloads.
 * Nothing here touches the unsaved choices of the form, and the page is reloaded after a change, so it asks first when the form was edited.
 */
(function () {
  'use strict';
  var root = document.querySelector('[data-bp-transfer]');
  if (!root) { return; }
  var endpoint = root.getAttribute('data-endpoint');
  var file = root.querySelector('[data-bt-file]');
  var preview = root.querySelector('[data-bt-preview]');
  var status = root.querySelector('[data-bt-status]');
  var dirty = false;
  var form = root.closest('form');
  if (form) { form.addEventListener('input', function () { dirty = true; }); }

  function token() {
    var field = document.querySelector('input[name="_csrf"]');
    return field ? field.value : '';
  }
  function say(text, bad) {
    status.textContent = text;
    status.className = 'bt-status' + (bad ? ' is-error' : '');
  }
  function post(action, body) {
    body.append('_csrf', token());
    return fetch(endpoint + '?branding=' + action, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-CSRF-Token': token() } })
      .then(function (response) { return response.json().then(function (data) { data.status = response.status; return data; }); });
  }
  function proceed() {
    return !dirty || window.confirm('Your unsaved choices on this screen will be lost. Continue?');
  }
  function reload(note) {
    var url = new URL(window.location.href);
    url.searchParams.set('tab', 'branding');
    url.searchParams.set('branding_done', note);
    window.location.href = url.toString();
  }

  function cell(text, label) {
    var td = document.createElement('td');
    td.textContent = text === '' ? '—' : text;
    if (label) { td.setAttribute('data-label', label); }
    return td;
  }
  function fileBody() {
    var body = new FormData();
    body.append('branding_file', file.files[0]);
    return body;
  }

  file.addEventListener('change', function () {
    if (!file.files.length) { return; }
    say('Reading the file…');
    post('preview', fileBody()).then(function (answer) {
      if (!answer.ok) { preview.hidden = true; say(answer.error || 'The file could not be read.', true); return; }
      var rows = root.querySelector('[data-bt-changes]');
      rows.textContent = '';
      answer.changes.forEach(function (change) {
        var tr = document.createElement('tr');
        var th = document.createElement('th');
        th.scope = 'row';
        th.textContent = change.label + (change.section === 'appearance' ? ' (look)' : '');
        tr.appendChild(th);
        tr.appendChild(cell(change.from));
        tr.appendChild(cell(change.to));
        rows.appendChild(tr);
      });
      var files = answer.files.filter(function (item) { return item.status !== 'same'; });
      root.querySelector('[data-bt-summary]').textContent = answer.changes.length === 0 && files.length === 0
        ? 'Nothing would change: this site already has these settings.'
        : answer.changes.length + ' setting' + (answer.changes.length === 1 ? '' : 's') + ' would change' + (files.length ? ', and ' + files.length + ' font file' + (files.length === 1 ? '' : 's') + ' would be added or replaced.' : '.');
      var extra = [];
      if (files.length) { extra.push('Files: ' + files.map(function (item) { return item.path + ' (' + item.status + ')'; }).join(', ') + '.'); }
      if (answer.skipped.length) { extra.push('Not on this site, skipped: ' + answer.skipped.join(', ') + '.'); }
      root.querySelector('[data-bt-extra]').textContent = extra.join(' ');
      root.querySelector('[data-bt-apply]').disabled = answer.changes.length === 0 && files.length === 0;
      preview.hidden = false;
      say('');
    }).catch(function () { say('The file could not be sent.', true); });
  });

  root.querySelector('[data-bt-cancel]').addEventListener('click', function () {
    preview.hidden = true;
    file.value = '';
    say('');
  });
  root.querySelector('[data-bt-apply]').addEventListener('click', function () {
    if (!proceed()) { return; }
    post('import', fileBody()).then(function (answer) {
      if (!answer.ok) { say(answer.error || 'The import failed.', true); return; }
      reload('imported');
    });
  });
  var undo = root.querySelector('[data-bt-undo]');
  if (undo) {
    undo.addEventListener('click', function () {
      if (!proceed()) { return; }
      post('undo', new FormData()).then(function (answer) {
        if (!answer.ok) { say('There is nothing to undo.', true); return; }
        reload('undone');
      });
    });
  }
  root.querySelectorAll('[data-bt-reset]').forEach(function (button) {
    button.addEventListener('click', function () {
      if (!window.confirm('Reset “' + button.textContent + '” to the theme defaults and save? You can undo it right after.') || !proceed()) { return; }
      var body = new FormData();
      body.append('section', button.getAttribute('data-bt-reset'));
      post('reset', body).then(function (answer) {
        if (!answer.ok) { say('That section could not be reset.', true); return; }
        reload(answer.changed ? 'reset' : 'unchanged');
      });
    });
  });

  var done = new URL(window.location.href).searchParams.get('branding_done');
  var notes = { imported: 'Imported. The previous settings are kept: use Undo to put them back.', undone: 'Put back as before.', reset: 'Reset to the defaults and saved. Use Undo to put the previous settings back.', unchanged: 'That section already had its defaults.' };
  if (done && notes[done]) {
    root.open = true;
    say(notes[done]);
  }
})();
