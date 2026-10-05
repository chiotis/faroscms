/*
 * The visual editor's serializer: the page turned back into Markdown (public/assets/js/admin-editor.js). The Markdown is what is
 * stored, so a regression here damages content.
 *
 * The real code is tested, not a copy of it: the part of admin-editor.js from "small helpers" to "keeping the textarea in step"
 * (the DOM to Markdown functions, and load, which draws what the server sends) is cut out of the file and run against a page made by
 * jsdom. Markdown is drawn by the server's own code (render-markdown.php, the site's Markdown environment), so what is checked is
 * what a person gets:
 *
 *   1. untouched      open a text and leave: it is written back exactly as it was, byte for byte;
 *   2. rewritten      every block written again by the serializer reads back as the same page the server draws;
 *   3. stable         the rewritten text, written again, does not change;
 *   4. what a browser makes   the HTML contentEditable leaves behind (div, b, i, nbsp, empty lines) becomes the Markdown it should;
 *   5. edits          one block changed: that block is written again and every other one is kept as it was.
 *
 *   node tests/js/serializer.test.js        (after: npm ci --prefix tests/js)
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

let JSDOM;
try {
  ({ JSDOM } = require('jsdom'));
} catch (error) {
  console.log('FAIL jsdom is not installed: run  npm ci --prefix tests/js');
  process.exit(1);
}

const { SAMPLES, REWRITTEN, DOM_CASES, EDITS } = require('./serializer-samples.js');

let failed = 0;
function check(label, ok, detail) {
  if (!ok) { failed++; }
  console.log((ok ? 'ok   ' : 'FAIL ') + label + (ok ? '' : '  ' + String(detail === undefined ? '' : detail).replace(/\s+/g, ' ').slice(0, 400)));
}
const show = (text) => JSON.stringify(text);

/* ------------------------------------------------------------------ the server's drawing, in batches */

function draw(texts) {
  const run = spawnSync('php', [path.join(__dirname, 'render-markdown.php')], { input: JSON.stringify(texts), encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  if (run.status !== 0) {
    console.log('FAIL the server\'s drawing could not run  ' + (run.stderr || run.error || '').toString().slice(0, 300));
    process.exit(1);
  }
  return JSON.parse(run.stdout);
}
// A line break is one whether it was written as <br> or as a new line (the server draws that as <br />).
const pageOf = (drawn) => drawn.blocks.map((b) => b.html.replace(/<br\s*\/?>\s*/g, '<br>')).join('\n') + (drawn.tail ? '\n[tail] ' + drawn.tail : '');

/* ------------------------------------------------------------------ the serializer, cut out of the editor */

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'admin-editor.js'), 'utf8');
const from = source.indexOf('/* ---------------------------------------------------------------- small helpers */');
const to = source.indexOf('/* ---------------------------------------------------------------- keeping the textarea in step */');
if (from < 0 || to < from) {
  console.log('FAIL the parts of admin-editor.js this test cuts out (small helpers ... keeping the textarea in step) were not found: update the markers in serializer.test.js');
  process.exit(1);
}
const body = source.slice(from, to);
check('the serializer is found in the editor', /function serialize\(full\)/.test(body) && /function load\(markdown\)/.test(body) && /function blockString\(/.test(body));

/** A fresh editor page. `answers` maps a text to what the server drew for it. */
function editor(answers) {
  const dom = new JSDOM('<!doctype html><body><div data-ed-surface contenteditable="true"></div></body>');
  const { window } = dom;
  const surface = window.document.querySelector('[data-ed-surface]');
  const state = { mode: 'visual', loaded: false, dirty: false, sources: [], snaps: [], tail: '', verbatim: true, saved: null };
  const fetchStub = (url, init) => {
    const text = init.body.get('body');
    if (!(text in answers)) { return Promise.reject(new Error('not drawn beforehand: ' + show(text))); }
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(Object.assign({ ok: true }, answers[text])) });
  };
  const make = new Function('document', 'window', 'surface', 'state', 'note', 'csrfField', 'endpoint', 'fetch', 'FormData', 'count',
    '"use strict";\n' + body + '\nreturn { serialize: serialize, load: load };');
  const api = make(window.document, window, surface, state, null, { value: 'token' }, '/admin/markdown-visual', fetchStub, window.FormData, function () {});
  return { surface, state, window, load: api.load, serialize: api.serialize };
}

/* ------------------------------------------------------------------ run */

(async function main() {
  const texts = Object.keys(SAMPLES).map((name) => SAMPLES[name]);
  const names = Object.keys(SAMPLES);
  const drawn = draw(texts);
  const answers = {};
  texts.forEach((text, i) => { answers[text] = drawn[i]; });

  // 1. untouched, and 2. rewritten
  const rewritten = [];
  for (let i = 0; i < names.length; i++) {
    const ed = editor(answers);
    await ed.load(texts[i]);
    const kept = ed.serialize(false);
    check('untouched: ' + names[i], kept === texts[i], show(kept) + ' expected ' + show(texts[i]));
    rewritten.push(ed.serialize(true));
  }
  const again = draw(rewritten);
  names.forEach((name, i) => {
    const same = pageOf(again[i]) === pageOf(drawn[i]);
    check('rewritten reads back as the same page: ' + name, same, 'wrote ' + show(rewritten[i]) + '\n  was ' + pageOf(drawn[i]) + '\n  now ' + pageOf(again[i]));
  });
  Object.keys(REWRITTEN).forEach((name) => {
    const i = names.indexOf(name);
    check('rewritten in this form: ' + name, i >= 0 && rewritten[i] === REWRITTEN[name], i < 0 ? 'no such sample' : show(rewritten[i]) + ' expected ' + show(REWRITTEN[name]));
  });

  // 3. stable
  const answers2 = {};
  rewritten.forEach((text, i) => { answers2[text] = again[i]; });
  for (let i = 0; i < names.length; i++) {
    const ed = editor(answers2);
    await ed.load(rewritten[i]);
    const twice = ed.serialize(true);
    check('writing it again changes nothing: ' + names[i], twice === rewritten[i], show(twice) + ' expected ' + show(rewritten[i]));
  }

  // 4. what a browser makes
  const wanted = DOM_CASES.map((c) => c.markdown);
  const wantedDrawn = draw(wanted);
  const answers3 = {};
  wanted.forEach((text, i) => { answers3[text] = wantedDrawn[i]; });
  for (let i = 0; i < DOM_CASES.length; i++) {
    const c = DOM_CASES[i];
    const ed = editor(answers3);
    ed.surface.innerHTML = c.html;
    const got = ed.serialize(false);
    check('typed in the browser: ' + c.name, got === c.markdown, show(got) + ' expected ' + show(c.markdown));
    // And what is written is what the page looked like: drawn again, it serializes to itself (or to the form the server makes of it).
    const back = editor(answers3);
    await back.load(c.markdown);
    const readback = c.readback === undefined ? c.markdown : c.readback;
    check('  and it reads back to the same text: ' + c.name, back.serialize(true) === readback, show(back.serialize(true)) + ' expected ' + show(readback));
  }

  // 5. edits
  const edited = EDITS.map((e) => e.markdown);
  const editedDrawn = draw(edited);
  const answers4 = {};
  edited.forEach((text, i) => { answers4[text] = EDITS[i].answer || editedDrawn[i]; });
  for (let i = 0; i < EDITS.length; i++) {
    const e = EDITS[i];
    const ed = editor(answers4);
    await ed.load(e.markdown);
    e.edit(ed.surface, ed.window.document);
    const got = ed.serialize(false);
    check('edited: ' + e.name, got === e.expected, show(got) + ' expected ' + show(e.expected));
  }

  console.log(failed === 0 ? '\nALL PASSED' : '\n' + failed + ' FAILED');
  process.exit(failed === 0 ? 0 : 1);
})().catch((error) => {
  console.log('FAIL the test stopped: ' + (error && error.stack || error));
  process.exit(1);
});
