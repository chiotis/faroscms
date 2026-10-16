/* Theme > Branding: schema defaults, field sources, colour swatches and resets. Run with node tests/js/branding-controls.test.js. */
'use strict';

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

let failed = 0;
function check(label, ok) {
  if (!ok) { failed++; }
  console.log((ok ? 'ok   ' : 'FAIL ') + label);
}
const name = (key, section = 'design') => `theme_settings[${section}][${key}]`;
const radio = (key, choices, selected, section) => `<div class="lc-row"><dt>${key}</dt><dd>${choices.map((choice) => `<label><input type="radio" name="${name(key, section)}" value="${choice}"${selected === choice ? ' checked' : ''}>${choice}</label>`).join('')}</dd></div>`;
const colour = (key, value) => `<div><span class="lc-colour" data-colour><input type="color" value="#ffffff"><input type="text" name="${name(key)}" value="${value}" placeholder="Theme"><button type="button" class="lc-colour-x">Reset colour</button></span></div>`;
const select = (key, choices, selected, section) => `<div class="lc-row"><dt>${key}</dt><dd><select name="${name(key, section)}">${choices.map((choice) => `<option${selected === choice ? ' selected' : ''}>${choice}</option>`).join('')}</select></dd></div>`;

const dom = new JSDOM(`<!doctype html><form><div data-bp>
  <details class="bp-card" id="colours"><div class="bp-body">
    ${radio('palette', ['slate', 'indigo'], 'indigo', 'appearance')}
    ${radio('heading_color', ['text', 'accent', 'custom'], 'custom')}
    ${colour('light_accent', '#aabbcc')}${colour('dark_background', '')}${colour('heading_color_light', '#cc0000')}
    <button type="button" data-bp-reset="colour">Reset colour overrides</button>
  </div></details>
  <details class="bp-card" id="type"><div class="bp-body">
    ${select('font', ['sans', 'serif'], 'serif', 'appearance')}
    ${select('heading_font', ['pairing', 'custom'], 'custom')}
    ${select('scale', ['auto', '1.25', '1.5'], '1.5')}
    <div class="lc-row" data-bp-custom-font><dt>Font file</dt><dd><input name="${name('font_file')}" value="fonts/test.woff2"></dd></div>
    <div class="bp-num"><label>Text size<input type="number" name="${name('base_size')}" value="20"></label></div>
    <button type="button" data-bp-reset="type">Reset type overrides</button>
  </div></details>
  <details class="bp-card" id="buttons"><div class="bp-body">
    ${radio('button_radius', ['auto', 'pill', 'square'], 'square')}
    <button type="button" data-bp-reset="buttons">Reset button overrides</button>
  </div></details>
  <div class="lc-row"><dt>Site name</dt><dd><input type="checkbox" name="${name('show_name', 'brand')}" checked></dd></div>
  <iframe data-bp-frame></iframe>
</div></form>`, { runScripts: 'outside-only', url: 'http://localhost/admin/theme' });
const w = dom.window;
const d = w.document;
const root = d.querySelector('[data-bp]');
const defaults = {};
Object.entries({ heading_color: 'text', light_accent: '', dark_background: '', heading_color_light: '', heading_font: 'pairing', font_file: '', base_size: '', scale: '1.25', button_radius: 'pill' }).forEach(([key, value]) => { defaults[name(key)] = { value, label: key }; });
defaults[name('palette', 'appearance')] = { value: 'slate', label: 'Palette' };
defaults[name('font', 'appearance')] = { value: 'sans', label: 'Font pairing' };
defaults[name('show_name', 'brand')] = { value: false, label: 'Show site name' };
root.setAttribute('data-bp-defaults', JSON.stringify(defaults));
const frame = d.querySelector('iframe');
const tokens = frame.contentDocument.documentElement.style;
tokens.setProperty('--accent-l', '#4338ca');
tokens.setProperty('--d-bg', '#0b1220');
tokens.setProperty('--n-text', '#0e1726');
let refreshes = 0;
let bubbledInputs = 0;
d.querySelector('form').addEventListener('preview:refresh', () => { refreshes++; });
d.querySelector('form').addEventListener('input', (event) => { if (event.target.name) { bubbledInputs++; } });
w.eval(fs.readFileSync(path.join(__dirname, '../../public/assets/js/admin-branding.js'), 'utf8'));
const field = (key, section) => d.querySelector(`[name="${name(key, section)}"]`);
const selected = (key, section) => d.querySelector(`[name="${name(key, section)}"]:checked`);
const source = (key, section) => {
  const input = field(key, section);
  const box = input.closest('[data-colour]');
  return (box ? box.parentElement : input.closest('dd, .bp-num')).querySelector('.bp-value');
};

check('custom and inherited colours show their source', source('light_accent').textContent === 'Your value' && source('dark_background').textContent === 'Theme value');
check('an empty colour shows the inherited swatch and hex without writing a form value', field('dark_background').value === '' && field('dark_background').placeholder === '#0b1220' && field('dark_background').previousElementSibling.value === '#0b1220');
check('a custom colour keeps its typed value and swatch', field('light_accent').value === '#aabbcc' && field('light_accent').previousElementSibling.value === '#aabbcc');

d.querySelector('[data-bp-reset="colour"]').click();
check('colour reset restores the non-auto heading default and clears both custom colours', selected('heading_color').value === 'text' && field('light_accent').value === '' && field('heading_color_light').value === '');
check('colour reset keeps the chosen palette', selected('palette', 'appearance').value === 'indigo');
check('reset sends one explicit preview refresh and bubbling field changes', refreshes === 1 && bubbledInputs > 0);
check('reset updates all affected source labels', source('heading_color').getAttribute('data-bp-value') === 'theme' && source('light_accent').textContent === 'Theme value');
root.dispatchEvent(new w.CustomEvent('preview:drawn'));
check('preview redraw resolves inherited colours after reset', field('light_accent').placeholder === '#4338ca' && field('light_accent').previousElementSibling.value === '#4338ca' && field('heading_color_light').placeholder === '#0e1726');

d.querySelector('[data-bp-reset="type"]').click();
check('type reset uses the schema default even when it is not auto', field('scale').value === '1.25' && field('heading_font').value === 'pairing' && field('base_size').value === '');
check('type reset keeps the chosen pairing and updates the custom-font row', field('font', 'appearance').value === 'serif' && field('font_file').value === '' && d.querySelector('[data-bp-custom-font]').style.display === 'none');
check('type reset refreshes once and clears source/reset controls', refreshes === 2 && source('scale').getAttribute('data-bp-value') === 'theme' && source('scale').querySelector('button').hidden);

d.querySelector('[data-bp-reset="buttons"]').click();
check('button reset uses the schema button radius instead of assuming auto', selected('button_radius').value === 'pill' && refreshes === 3);
const size = field('base_size');
size.value = '18';
size.dispatchEvent(new w.Event('input', { bubbles: true }));
check('typing an override updates its source and exposes a field reset', source('base_size').getAttribute('data-bp-value') === 'custom' && !source('base_size').querySelector('button').hidden);
source('base_size').querySelector('button').click();
check('the individual reset restores inheritance and refreshes the preview', size.value === '' && source('base_size').getAttribute('data-bp-value') === 'theme' && refreshes === 4);
source('show_name', 'brand').querySelector('button').click();
check('a toggle reset handles a false schema default', !field('show_name', 'brand').checked && source('show_name', 'brand').getAttribute('data-bp-value') === 'theme');

const accent = field('light_accent');
const picker = accent.previousElementSibling;
picker.value = '#ffdd00';
picker.dispatchEvent(new w.Event('input', { bubbles: true }));
check('using the colour picker updates the stored field and source', accent.value === '#ffdd00' && source('light_accent').textContent === 'Your value');
accent.closest('[data-colour]').querySelector('button').click();
check('the colour reset restores inheritance, source and preview refresh', accent.value === '' && source('light_accent').textContent === 'Theme value' && refreshes === 6);
tokens.setProperty('--accent-l', '#047857');
root.dispatchEvent(new w.CustomEvent('preview:drawn'));
check('a palette change updates the empty colour swatch without saving an override', accent.value === '' && accent.placeholder === '#047857' && picker.value === '#047857');
check('colour controls remain empty in the submitted form', new w.FormData(d.querySelector('form')).get(name('light_accent')) === '');

dom.window.close();
console.log(failed ? 'FAILED: ' + failed : 'ALL PASSED');
process.exit(failed ? 1 : 0);
