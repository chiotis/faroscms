/* Theme > Branding > Colour: the palette row, read-only ready-made palettes, Customise, "+ Custom", rename, delete with fallback
   and Fix (admin-palettes.js). Run with node tests/js/palettes.test.js. */
'use strict';

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

let failed = 0;
function check(label, ok) {
  if (!ok) { failed++; }
  console.log((ok ? 'ok   ' : 'FAIL ') + label);
}
const tick = (ms = 20) => new Promise((resolve) => setTimeout(resolve, ms));
const TEAL = { name: 'Teal', light: { accent: '#0f766e', background: '#ffffff', surface: '#f5f6f8', text: '#0e1726', muted: '#4a5568', border: '#e2e6ec' }, dark: { accent: '#5eead4', background: '#0b1220', surface: '#111a2c', text: '#e8edf4', muted: '#a6b3c5', border: '#223049' }, ink: '#0b1220' };
const BUILTIN = { slate: Object.assign({}, TEAL, { name: 'Slate' }), teal: TEAL };
const mine = [{ id: 'brand', name: 'Brand', source: 'teal', light: { accent: '#34d5c3', background: '#ffffff', surface: '#f5f6f8', text: '#0e1726' }, dark: { accent: '#5eead4', background: '#0b1220' } }];

const dom = new JSDOM(`<!doctype html><form><div data-bp>
  <section data-pl data-pl-endpoint="/admin/theme" data-pl-csrf="tok" data-pl-default="slate" data-pl-builtin='${JSON.stringify(BUILTIN)}'>
    <input type="hidden" name="theme_settings[design][site_palette]" value="brand" data-pl-active>
    <input type="hidden" name="theme_settings[design][site_palettes]" value='${JSON.stringify(mine)}' data-pl-store>
    <div class="pl-row"><div data-pl-chips role="radiogroup" aria-label="Palette in use"></div><button type="button" data-pl-new aria-expanded="false">+ Custom</button></div>
    <div data-pl-native><div class="bp-pal">
      <label><input type="radio" name="theme_settings[appearance][palette]" value="slate"><span><i style="background:#1e293b"></i>Slate</span></label>
      <label><input type="radio" name="theme_settings[appearance][palette]" value="teal" checked><span><i style="background:#0f766e"></i>Teal</span></label>
    </div></div>
    <div data-pl-view="builtin" hidden><p data-pl-builtin-note></p><button type="button" data-pl-customise>Customise</button><div data-pl-ro></div></div>
    <div data-pl-view="create" hidden>
      <input type="radio" name="pl_start" value="colours" data-pl-start checked><input type="radio" name="pl_start" value="duo" data-pl-start><input type="radio" name="pl_start" value="copy" data-pl-start>
      <div data-pl-pane="colours"><span class="lc-colour"><input type="color"><input type="text" name="pl_accent" value="#4338ca"></span><button type="button" data-pl-swap></button>
        <span class="lc-colour"><input type="color"><input type="text" name="pl_base" value="#f8fafc"></span><span class="lc-colour"><input type="color"><input type="text" name="pl_secondary" value=""></span>
        <input type="radio" name="pl_role" data-pl-role value="background" checked><input type="radio" name="pl_role" data-pl-role value="ink">
        <div data-pl-result></div><input type="text" data-pl-name><button type="button" data-pl-save></button><button type="button" data-pl-cancel></button></div>
      <div data-pl-pane="duo" hidden><div data-pl-duo-box><input type="checkbox" data-pl-aa><p data-pl-duo-note></p><div data-pl-grid></div></div></div>
      <div data-pl-pane="copy" hidden><div data-pl-copy></div></div>
    </div>
    <div data-pl-view="edit" hidden><input type="text" data-pl-rename><button type="button" data-pl-regen></button><button type="button" data-pl-delete></button>
      <table data-pl-roles><tbody></tbody></table></div>
    <div data-pl-contrast><button type="button" data-pl-fixall hidden></button><p data-pl-contrast-note></p><div data-pl-contrast-rows></div></div>
  </section>
  <iframe data-bp-frame></iframe>
</div></form>`, { runScripts: 'outside-only', url: 'http://localhost/admin/theme', pretendToBeVisual: true });
const w = dom.window;
const doc = w.document;

// The preview frame's computed colours stand in for the page.
const TOKENS = { '--n-text': '#0e1726', '--n-bg': '#ffffff', '--n-surface': '#f5f6f8', '--n-muted': '#4a5568', '--accent-l': '#34d5c3', '--accent-contrast-l': '#0b1220', '--accent-d': '#5eead4', '--d-bg': '#0b1220', '--d-surface': '#111a2c', '--d-text': '#e8edf4', '--d-muted': '#a6b3c5', '--accent-contrast-d': '#0b1220' };
const frame = doc.querySelector('[data-bp-frame]');
Object.defineProperty(frame, 'contentDocument', { get: () => ({ documentElement: {} }) });
Object.defineProperty(frame, 'contentWindow', { get: () => ({ getComputedStyle: () => ({ getPropertyValue: (name) => TOKENS[name] || '' }) }) });

const requests = [];
w.fetch = (url, options) => {
  requests.push({ url, body: options && options.body });
  const what = String(url).split('palette=')[1];
  let answer = { ok: false };
  if (what === 'copy') {
    answer = { ok: true, palette: Object.assign({ id: 'teal-custom', name: 'Teal (custom)', source: 'teal', ink: '#0b1220' }, { light: TEAL.light, dark: TEAL.dark }) };
  } else if (what === 'fix') {
    answer = { ok: true, before: '#34d5c3', after: '#007f73', ratio_before: 1.7, ratio_after: 4.6, level: 'AA' };
  } else if (what === 'generate') {
    answer = { ok: true, light: TEAL.light, dark: TEAL.dark, ink: '#0b1220', passes: true };
  }
  return Promise.resolve({ ok: true, json: () => Promise.resolve(answer) });
};
w.confirm = () => true;
w.eval(fs.readFileSync(path.join(__dirname, '../../public/assets/js/admin-palettes.js'), 'utf8'));

const active = () => doc.querySelector('[data-pl-active]');
const store = () => JSON.parse(doc.querySelector('[data-pl-store]').value || '[]');
const chips = () => Array.from(doc.querySelectorAll('[data-pl-chips] .pl-chip'));
const on = () => chips().filter((c) => c.getAttribute('aria-checked') === 'true').map((c) => c.getAttribute('data-id'));
const native = () => doc.querySelector('input[name="theme_settings[appearance][palette]"]:checked').value;
const view = (name) => !doc.querySelector(`[data-pl-view="${name}"]`).hidden;
const hidden = (selector) => doc.querySelector(selector).hidden;

check('the chips are a radio group: the ready-made palettes, then the site palettes, and exactly one is chosen', doc.querySelector('[data-pl-chips]').getAttribute('role') === 'radiogroup' && chips().map((c) => c.getAttribute('data-id')).join() === 'slate,teal,brand' && chips().every((c) => c.getAttribute('role') === 'radio') && on().join() === 'brand');
check('only the chosen chip is in the tab order (a roving tabindex)', chips().filter((c) => c.tabIndex === 0).length === 1 && chips().find((c) => c.tabIndex === 0).getAttribute('data-id') === 'brand');
check('the plain radio buttons of the form are kept for saving, and hidden', hidden('[data-pl-native]') === true);
check('a site palette opens its editor right there, with every role for light and dark', view('edit') && !view('builtin') && doc.querySelectorAll('[data-pl-roles] tbody tr').length === 8 && doc.querySelectorAll('[data-pl-roles] input[type="text"]').length === 15);
check('the editor labels its colour boxes for assistive technology', doc.querySelector('[aria-label="Accent, light, hex code"]') !== null && doc.querySelector('[aria-label="Dark panels and footer, hex code"]') !== null);

(async () => {
  // a ready-made palette: read-only
  chips().find((c) => c.getAttribute('data-id') === 'slate').click();
  await tick();
  check('choosing a ready-made palette checks its radio, empties the site palette in use, and shows its colours read-only', native() === 'slate' && active().value === '' && on().join() === 'slate' && view('builtin') && !view('edit'));
  check('its colours are swatches with labels and hex values (no inputs)', doc.querySelectorAll('.pl-ro-item').length === 13 && doc.querySelector('[data-pl-ro] input') === null && /Accent/.test(doc.querySelector('.pl-ro-item').textContent) && /#0f766e/.test(doc.querySelector('.pl-ro-item').textContent));
  check('ready-made palettes are never changed: the store is untouched', store().length === 1 && store()[0].id === 'brand');

  // Customise
  chips().find((c) => c.getAttribute('data-id') === 'teal').click();
  await tick();
  doc.querySelector('[data-pl-customise]').click();
  await tick(60);
  const copyRequest = requests.filter((r) => /palette=copy/.test(r.url)).pop();
  check('Customise asks the server for a copy of the palette, naming the ones taken', copyRequest && copyRequest.body.get('builtin') === 'teal' && copyRequest.body.get('taken[]') === 'brand');
  check('and the copy is a site palette that is chosen and open in the editor', store().map((p) => p.id).join() === 'brand,teal-custom' && active().value === 'teal-custom' && on().join() === 'teal-custom' && native() === 'teal' && view('edit') && doc.querySelector('[data-pl-rename]').value === 'Teal (custom)');

  // rename
  const rename = doc.querySelector('[data-pl-rename]');
  rename.value = 'Warm teal'; rename.dispatchEvent(new w.Event('input', { bubbles: true }));
  check('rename changes the palette and its chip', store().find((p) => p.id === 'teal-custom').name === 'Warm teal' && chips().find((c) => c.getAttribute('data-id') === 'teal-custom').textContent.indexOf('Warm teal') !== -1);

  // an edit lands in the palette
  const accent = doc.querySelector('[aria-label="Accent, light, hex code"]');
  accent.value = '#115e59'; accent.dispatchEvent(new w.Event('input', { bubbles: true }));
  check('an edit is written into the palette (and the form is told)', store().find((p) => p.id === 'teal-custom').light.accent === '#115e59');

  // Fix
  chips().find((c) => c.getAttribute('data-id') === 'brand').click();
  await tick(60);
  const fixes = Array.from(doc.querySelectorAll('.pl-fixbtn'));
  check('a failing check (Links #34d5c3 on white) offers Fix, and nothing that passes does', fixes.length === 1 && /Links/.test(fixes[0].getAttribute('aria-label')) && doc.querySelector('[data-pl-fixall]').hidden === false);
  fixes[0].click();
  await tick(60);
  const fixRequest = requests.filter((r) => /palette=fix/.test(r.url)).pop();
  check('Fix asks for the nearest AA shade on the colours the link is read on', fixRequest.body.get('colour') === '#34d5c3' && fixRequest.body.get('against') === '#ffffff,#f5f6f8' && fixRequest.body.get('min') === '4.5');
  check('it shows before and after and changes nothing until Apply', /#34d5c3 → #007f73/.test(doc.querySelector('.pl-fix').textContent) && /light mode only/.test(doc.querySelector('.pl-fix').textContent) && store().find((p) => p.id === 'brand').light.accent === '#34d5c3');
  doc.querySelector('.pl-fix .pl-primary').click();
  await tick();
  const brand = store().find((p) => p.id === 'brand');
  check('Apply changes that role in that mode only', brand.light.accent === '#007f73' && brand.dark.accent === '#5eead4');

  // delete with fall back
  doc.querySelector('[data-pl-delete]').click();
  await tick();
  check('deleting the palette in use falls back to its source palette', store().map((p) => p.id).join() === 'teal-custom' && active().value === '' && native() === 'teal' && on().join() === 'teal' && view('builtin'));

  // + Custom: from two colours
  doc.querySelector('[data-pl-new]').click();
  await tick(60);
  check('"+ Custom" opens the three starts, with a draft chosen in the preview', view('create') && on().join() === 'draft' && doc.querySelector('[data-pl-new]').getAttribute('aria-expanded') === 'true' && JSON.parse(doc.querySelector('[data-pl-store]').value).some((p) => p.id === 'draft'));
  doc.querySelector('[data-pl-name]').value = 'From colours';
  doc.querySelector('[data-pl-save]').click();
  await tick();
  const made = store().find((p) => p.name === 'From colours');
  check('Create palette saves it as a site palette with its source, chooses it and opens the editor', made && made.id === 'from-colours' && made.source === 'teal' && made.from.accent === '#4338ca' && active().value === 'from-colours' && view('edit') && !view('create'));

  // + Custom: copy of a ready-made one
  doc.querySelector('[data-pl-new]').click();
  await tick(60);
  const copyStart = doc.querySelector('[data-pl-start][value="copy"]');
  copyStart.checked = true; copyStart.dispatchEvent(new w.Event('change', { bubbles: true }));
  const buttons = Array.from(doc.querySelectorAll('[data-pl-copy] .pl-chip'));
  check('the copy start lists the ready-made palettes', buttons.length === 2);
  buttons[0].click();
  await tick(60);
  check('a copy from there is a new chosen site palette and leaves the draft behind', !store().some((p) => p.id === 'draft') && view('edit') && !view('create'));

  // cancel puts things back
  doc.querySelector('[data-pl-new]').click();
  await tick(60);
  doc.querySelector('[data-pl-cancel]').click();
  await tick();
  check('Cancel drops the draft and returns to what was chosen', !store().some((p) => p.id === 'draft') && active().value !== 'draft' && !view('create'));

  // keyboard
  const current = chips().find((c) => c.getAttribute('aria-checked') === 'true');
  current.focus();
  current.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
  await tick();
  check('Home moves to the first palette and chooses it, like any radio group', on().join() === 'slate' && native() === 'slate');

  console.log(failed ? `\n${failed} FAILED` : '\nALL PASSED');
  process.exit(failed ? 1 : 0);
})();
