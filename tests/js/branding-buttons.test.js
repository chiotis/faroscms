/* Theme > Branding: the colours of Links and Button styles (admin-branding-buttons.js). Run with node tests/js/branding-buttons.test.js. */
'use strict';

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

let failed = 0;
function check(label, ok) {
  if (!ok) { failed++; }
  console.log((ok ? 'ok   ' : 'FAIL ') + label);
}
const field = (key) => `theme_settings[design][${key}]`;
const tone = (label, key, value = '') => `<span data-tone data-tone-label="${label}"><select data-tone-role><option value="">Theme</option><option value="accent">Accent</option><option value="white">White</option><option value="ink">Ink</option><option value="custom">Custom</option></select><span class="bt-custom"${value ? '' : ' hidden'}><input type="color" data-tone-picker value="#336699"><input type="text" data-tone-hex value="${value}"></span><input type="hidden" name="${field(key)}" value="${value}" data-tone-value></span>`;

const dom = new JSDOM(`<!doctype html><form><div data-bp>
  <div class="bp-card" id="links"><div data-bt data-bt-kind="links">
    ${tone('Link colour, light', 'link_color')}${tone('Link colour, dark', 'link_color_dark')}
    <span data-bt-badge="link" data-bt-mode="light"></span><span data-bt-badge="link" data-bt-mode="dark"></span>
    <button type="button" data-bp-reset="links">Reset</button>
  </div></div>
  <div class="bp-card" id="buttons"><div data-bt data-bt-kind="buttons"><section data-bt-style="primary">
    ${tone('Primary background', 'btn_primary_bg')}${tone('Primary text', 'btn_primary_text')}${tone('Primary hover background', 'btn_primary_hover_bg')}${tone('Primary hover text', 'btn_primary_hover_text')}
    <span data-bt-badge="button" data-bt-state="normal"></span><span data-bt-badge="button" data-bt-state="hover"></span>
  </section><button type="button" data-bp-reset="button-styles">Reset</button></div></div>
  <iframe data-bp-frame></iframe>
</div></form>`, { runScripts: 'outside-only', url: 'http://localhost/' });
const w = dom.window;
w.eval(fs.readFileSync(path.join(__dirname, '../../public/assets/js/admin-branding-buttons.js'), 'utf8'));
const doc = w.document;
const stored = (key) => doc.querySelector(`[name="${field(key)}"]`).value;
const box = (key) => doc.querySelector(`[name="${field(key)}"]`).closest('[data-tone]');
const choose = (key, value) => { const s = box(key).querySelector('[data-tone-role]'); s.value = value; s.dispatchEvent(new w.Event('change', { bubbles: true })); };
const badges = (selector) => doc.querySelector(selector).textContent.replace(/\s+/g, ' ').trim();

let inputs = 0;
doc.querySelector('[data-bp]').addEventListener('input', () => { inputs++; });

check('the page starts with the theme\'s colours: nothing is stored, and the badges show the theme\'s own pairs', stored('link_color') === '' && /L\s*1\d\.\d/.test(badges('[data-bt-badge="link"][data-bt-mode="light"]')));
choose('link_color', 'accent');
check('a role is stored as its name, and the preview is told', stored('link_color') === 'accent' && inputs > 0);
choose('link_color', 'custom');
check('Custom opens the colour and stores its hex', !box('link_color').querySelector('.bt-custom').hidden && /^#[0-9a-f]{6}$/.test(stored('link_color')));
const hex = box('link_color').querySelector('[data-tone-hex]');
hex.value = '#FFF'; hex.dispatchEvent(new w.Event('input', { bubbles: true }));
check('a typed hex is written #rrggbb', stored('link_color') === '#ffffff');
check('a custom colour that does not read is marked in the badge (white on white: 1.0, fails)', /1\.0.*fails AA/.test(badges('[data-bt-badge="link"][data-bt-mode="light"]')) && doc.querySelector('[data-bt-badge="link"] .bt-fail') !== null);
hex.value = '#0b57d0'; hex.dispatchEvent(new w.Event('input', { bubbles: true }));
check('one that reads passes in light, and the dark box follows the light one while it is empty', doc.querySelector('[data-bt-badge="link"][data-bt-mode="light"] .bt-pass') !== null && /D\s*\d/.test(badges('[data-bt-badge="link"][data-bt-mode="dark"]')));
hex.value = 'zzz'; hex.dispatchEvent(new w.Event('input', { bubbles: true }));
check('a hex that is not one is not stored', stored('link_color') === '#0b57d0');

choose('btn_primary_bg', 'ink'); choose('btn_primary_text', 'white');
check('button badges: the text on the background, normal and hover', doc.querySelectorAll('[data-bt-badge="button"] .bt-badge').length === 4);
choose('btn_primary_text', 'ink');
check('ink on ink does not read, and the badge says so', doc.querySelector('[data-bt-badge="button"][data-bt-state="normal"] .bt-fail') !== null);

doc.querySelector('[data-bp-reset="links"]').click();
check('Reset empties the card\'s colours', stored('link_color') === '' && box('link_color').querySelector('[data-tone-role]').value === '' && box('link_color').querySelector('.bt-custom').hidden);
check('and not those of the other card', stored('btn_primary_bg') === 'ink');
doc.querySelector('[data-bp-reset="button-styles"]').click();
check('the button card resets too', stored('btn_primary_bg') === '' && stored('btn_primary_text') === '');

console.log(failed === 0 ? 'ALL PASSED' : failed + ' FAILED');
process.exit(failed === 0 ? 0 : 1);
