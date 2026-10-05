/*
 * The character count under the search and sharing texts (public/assets/js/admin-seo-count.js, the whole file) in jsdom: what it
 * says and which state it is in for each length, the text it counts when the field is empty, how it is placed, and that it follows
 * typing.
 *
 *   node tests/js/seo-count.test.js        (after: npm ci --prefix tests/js)
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

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'admin-seo-count.js'), 'utf8');

function page(html) {
  const dom = new JSDOM('<!doctype html><body>' + html + '</body>', { runScripts: 'outside-only' });
  dom.window.eval(source);
  const $ = (selector) => dom.window.document.querySelector(selector);
  const type = (selector, value) => {
    const field = $(selector);
    field.value = value;
    field.dispatchEvent(new dom.window.Event('input', { bubbles: true }));
  };
  return { $, type, window: dom.window };
}

const MARKUP = ''
  + '<input id="t" name="title" value="Our work">'
  + '<textarea name="excerpt">A short summary of the page.</textarea>'
  + '<div class="seo-row"><label for="st">SEO title</label><input id="st" name="seo_title" data-count="30,60,70" data-count-from=\'input[name="title"]\' data-count-label="the title"></div>'
  + '<div class="seo-row"><label for="sd">Description</label><textarea id="sd" name="seo_description" data-count="70,160,180" data-count-from=\'textarea[name="excerpt"]\' data-count-label="the excerpt"></textarea></div>'
  + '<label>Share title <input id="ot" name="og" data-count="30,70,90" data-count-from="#st|#t" data-count-label="the search title|the title"></label>';

const p = page(MARKUP);
const note = (id) => p.$('#' + id + '-count');
const state = (id) => (note(id).className.match(/is-(\w+)/) || [])[1];

check('a field that asks for a count gets one, after it, in the same cell of a row', note('st') !== null && note('st').parentNode === p.$('#st').parentNode && p.$('#st').parentNode.tagName === 'DIV' && p.$('#st').parentNode.parentNode.className === 'seo-row');
check('in a label it goes after the label, not in its name', p.$('label:not([for])').nextSibling === note('ot') && !p.$('label:not([for])').textContent.includes('characters'));
check('and the field points at it', p.$('#st').getAttribute('aria-describedby') === 'st-count');
check('an empty title counts the page title, and says so', /^8 characters \(from the title\)/.test(note('st').textContent) && state('st') === 'short', note('st').textContent);
check('an empty description counts the excerpt', /\(from the excerpt\)/.test(note('sd').textContent) && state('sd') === 'short', note('sd').textContent);
check('the share title counts the search title or the title, the first that has a text, and names it', /^8 characters \(from the title\)/.test(note('ot').textContent), note('ot').textContent);
p.type('#st', 'Search title here');
check('and the search title when there is one', /^17 characters \(from the search title\)/.test(note('ot').textContent), note('ot').textContent);
p.type('#st', '');

const title = (n) => 'x'.repeat(n);
for (const [n, expected] of [[0, 'short'], [29, 'short'], [30, 'good'], [60, 'good'], [61, 'long'], [70, 'long'], [71, 'over']]) {
  p.type('#st', title(n));
  check('a title of ' + n + ' characters is ' + expected, (n === 0 ? state('st') === 'short' : state('st') === expected), state('st') + ' ' + note('st').textContent);
}
p.type('#st', title(45));
check('the text says the length and that it is good', note('st').textContent === '45 characters · a good length', note('st').textContent);
p.type('#st', title(65));
check('a little long says it may be cut off', /a little long, it may be cut off \(over 60\)/.test(note('st').textContent), note('st').textContent);
p.type('#st', title(80));
check('too long says it will be cut off', /too long, it will be cut off \(over 70\)/.test(note('st').textContent), note('st').textContent);
p.type('#st', title(10));
check('a short one says what to aim for', /a little short, aim for 30–60/.test(note('st').textContent), note('st').textContent);
p.type('#st', '');
p.type('#t', title(40));
check('with the field empty the count follows the text it falls back to', /^40 characters \(from the title\)/.test(note('st').textContent) && state('st') === 'good', note('st').textContent);
p.type('#st', 'Ελληνικός τίτλος με τόνους');
check('letters are counted, not bytes', /^26 characters/.test(note('st').textContent), note('st').textContent);
p.type('#st', '😀'.repeat(35));
check('and a character outside the basic plane counts as one', /^35 characters/.test(note('st').textContent) && state('st') === 'good', note('st').textContent);
p.type('#sd', 'y'.repeat(170));
check('a description of 170 is a little long', state('sd') === 'long', note('sd').textContent);
p.type('#sd', 'y'.repeat(200));
check('and of 200 too long', state('sd') === 'over', note('sd').textContent);
p.type('#sd', 'y'.repeat(120));
check('and of 120 good', state('sd') === 'good', note('sd').textContent);
const q = page('<input id="a" data-count="30,60">');
check('a count with a wrong range is ignored', q.$('.seo-count') === null);
const r = page('<input id="a">');
check('a page with no field that asks has nothing added', r.$('.seo-count') === null);

console.log(failed === 0 ? '\nALL PASSED' : '\n' + failed + ' FAILED');
process.exit(failed === 0 ? 0 : 1);
