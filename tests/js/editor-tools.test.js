/*
 * The visual editor's toolbar, run as it is shipped (public/assets/js/admin-editor.js, the whole file) in jsdom: the picture dialog and
 * the library it opens, the alignment buttons, and what they leave in the Markdown. jsdom has no editing commands, so the two the
 * editor asks the browser for here (insertHTML and formatBlock) are recorded and done by hand.
 *
 *   node tests/js/editor-tools.test.js        (after: npm ci --prefix tests/js)
 */
'use strict';

const fs = require('fs');
const path = require('path');

let JSDOM;
try {
  ({ JSDOM } = require('jsdom'));
} catch (error) {
  console.log('FAIL jsdom is not installed: run  npm ci --prefix tests/js');
  process.exit(1);
}

let failed = 0;
function check(label, ok, detail) {
  if (!ok) { failed++; }
  console.log((ok ? 'ok   ' : 'FAIL ') + label + (ok ? '' : '  ' + String(detail === undefined ? '' : detail).replace(/\s+/g, ' ').slice(0, 300)));
}
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'admin-editor.js'), 'utf8');

/** The server's drawing of the text, as the endpoint gives it. */
const DRAWN = {
  ok: true, tail: '', verbatim: true,
  blocks: [
    { html: '<p>Hello world</p>', source: 'Hello world' },
    { html: '<h2>Title</h2>', source: '## Title' },
    { html: '<ul>\n<li>item</li>\n</ul>', source: '- item' },
  ],
};

async function page() {
  const dom = new JSDOM('<!doctype html><body><form><input type="hidden" name="_csrf" value="t"><section>'
    + '<button type="button" data-ed-mode="visual" hidden>Visual</button><button type="button" data-ed-mode="markdown" hidden>Markdown</button>'
    + '<div data-editor data-endpoint="/admin/markdown-visual" data-raw-html="0"><div data-ed-tools hidden></div><div data-md-toolbar hidden></div>'
    + '<div data-ed-surface contenteditable="true" hidden></div><textarea name="body" data-md-body>Hello world\n\n## Title\n\n- item</textarea>'
    + '<span data-ed-words></span><span data-ed-note></span></div></section></form></body>', { runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/' });
  const { window } = dom;
  const calls = [];
  // jsdom does not know contentEditable, which the editor looks for before it starts.
  Object.defineProperty(window.HTMLElement.prototype, 'contentEditable', { get() { return this.getAttribute('contenteditable') || 'inherit'; }, set(v) { this.setAttribute('contenteditable', v); }, configurable: true });
  window.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(DRAWN) });
  window.document.execCommand = function (name, ui, value) { calls.push([name, value]); return true; };
  const library = { callback: null, open(callback) { this.callback = callback; const d = window.document.createElement('dialog'); d.setAttribute('open', ''); d.innerHTML = '<button type="button" data-pick>A picture</button>'; window.document.body.appendChild(d); this.dialog = d; } };
  window.FarosMediaPicker = library;
  window.eval(source);
  await wait(50);
  const $ = (selector) => window.document.querySelector(selector);
  const surface = $('[data-ed-surface]');
  const click = (node) => { node.dispatchEvent(new window.MouseEvent('mousedown', { bubbles: true, cancelable: true })); node.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true })); };
  const caretIn = (node) => { const range = window.document.createRange(); range.selectNodeContents(node); range.collapse(true); const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range); };
  return { window, $, surface, click, caretIn, calls, library };
}

(async function main() {
  let t = await page();
  check('the editor starts, with the toolbar and the text drawn', t.surface.children.length === 3 && t.$('[data-cmd=image]') !== null, t.surface.innerHTML);
  check('and four alignment buttons', ['alignleft', 'aligncenter', 'alignright', 'alignjustify'].every((c) => t.$('[data-cmd=' + c + ']') !== null));

  // ---- the picture dialog
  t.caretIn(t.surface.children[0]);
  t.click(t.$('[data-cmd=image]'));
  check('the picture button opens the dialog, with the library', t.$('.ed-pop') !== null && Array.from(t.window.document.querySelectorAll('.ed-pop button')).some((b) => /Library/.test(b.textContent)));
  Array.from(t.window.document.querySelectorAll('.ed-pop button')).find((b) => /Library/.test(b.textContent)).click();
  check('the library opens', t.library.dialog !== undefined);
  t.click(t.library.dialog.querySelector('[data-pick]'));
  check('a click in the library does not close the dialog it was opened from', t.$('.ed-pop') !== null);
  t.library.callback('/uploads/media/pic.png', { alt: 'A cat' });
  const inputs = t.window.document.querySelectorAll('.ed-pop input[type=text]');
  check('the picture chosen is in the dialog, with its description', inputs[0].value === '/uploads/media/pic.png' && inputs[1].value === 'A cat', [inputs[0].value, inputs[1].value]);
  t.$('.ed-pop').dispatchEvent(new t.window.Event('submit', { bubbles: true, cancelable: true }));
  const inserted = t.calls.find((c) => c[0] === 'insertHTML');
  check('Insert puts the picture in the text', inserted !== undefined && inserted[1] === '<img src="/uploads/media/pic.png" alt="A cat">', t.calls);
  check('and closes the dialog', t.$('.ed-pop') === null);
  t.click(t.$('[data-cmd=image]'));
  check('a click anywhere else closes it', (() => { t.click(t.window.document.body); return t.$('.ed-pop') === null; })());
  t.click(t.$('[data-cmd=image]'));
  t.click(t.$('.ed-pop input'));
  check('a click inside it does not', t.$('.ed-pop') !== null);

  // ---- alignment
  t = await page();
  const paragraph = t.surface.children[0];
  t.caretIn(paragraph);
  t.click(t.$('[data-cmd=aligncenter]'));
  check('a paragraph is centered', paragraph.getAttribute('align') === 'center', paragraph.outerHTML);
  check('and the button says so', t.$('[data-cmd=aligncenter]').getAttribute('aria-pressed') === 'true');
  await wait(400);
  check('the Markdown has it, in the line before the paragraph, and the other blocks as they were', t.$('textarea').value === '{align=center}\nHello world\n\n## Title\n\n- item', JSON.stringify(t.$('textarea').value));
  t.click(t.$('[data-cmd=alignright]'));
  check('another choice replaces it', paragraph.getAttribute('align') === 'right' && t.$('[data-cmd=aligncenter]').getAttribute('aria-pressed') === 'false', paragraph.outerHTML);
  t.click(t.$('[data-cmd=alignjustify]'));
  check('justified too', paragraph.getAttribute('align') === 'justify');
  t.click(t.$('[data-cmd=alignleft]'));
  await wait(400);
  check('left is the usual, and takes the choice away', !paragraph.hasAttribute('align') && t.$('textarea').value === 'Hello world\n\n## Title\n\n- item', JSON.stringify(t.$('textarea').value));

  const heading = t.surface.children[1];
  t.caretIn(heading);
  t.click(t.$('[data-cmd=aligncenter]'));
  await wait(400);
  check('a heading is aligned as a paragraph is', heading.getAttribute('align') === 'center' && t.$('textarea').value === 'Hello world\n\n{align=center}\n## Title\n\n- item', JSON.stringify(t.$('textarea').value));

  // a selection over several blocks aligns each of them
  const range = t.window.document.createRange();
  range.setStart(paragraph.firstChild, 2);
  range.setEnd(heading.firstChild, 2);
  const selection = t.window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
  t.click(t.$('[data-cmd=alignright]'));
  check('a selection over a paragraph and a heading aligns both', paragraph.getAttribute('align') === 'right' && heading.getAttribute('align') === 'right', [paragraph.outerHTML, heading.outerHTML]);

  // a list item is not aligned, and the person is told
  const item = t.surface.querySelector('li');
  t.caretIn(item);
  t.click(t.$('[data-cmd=aligncenter]'));
  check('a list item is left alone, and the person is told why', !item.hasAttribute('align') && !t.surface.querySelector('ul').hasAttribute('align') && /not for list items/.test(t.$('[data-ed-note]').textContent), t.$('[data-ed-note]').textContent);

  console.log(failed === 0 ? '\nALL PASSED' : '\n' + failed + ' FAILED');
  process.exit(failed === 0 ? 0 : 1);
})().catch((error) => {
  console.log('FAIL the test stopped: ' + (error && error.stack || error));
  process.exit(1);
});
