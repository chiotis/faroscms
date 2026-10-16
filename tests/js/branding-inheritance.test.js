/* Branding inheritance: show a concrete default without silently saving an override. */
'use strict';

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const script = fs.readFileSync(path.join(__dirname, '../../public/assets/js/admin-branding.js'), 'utf8');
let failed = 0;
function check(label, ok) {
  if (!ok) { failed++; }
  console.log((ok ? 'ok   ' : 'FAIL ') + label);
}
const name = (key, section = 'design') => `theme_settings[${section}][${key}]`;
const radio = (key, options, selected, section) => `<div class="lc-row" data-field="${key}"><dt>${key}</dt><dd>${options.map(option => `<label><input type="radio" name="${name(key, section)}" value="${option}"${selected === option ? ' checked' : ''}>${option === 'auto' ? 'Theme' : option}</label>`).join('')}</dd></div>`;

function boot({ enhance = true, weight = 'auto', radius = 'auto', shape = 'soft' } = {}) {
  const dom = new JSDOM(`<!doctype html><form><div data-bp>
    <details class="bp-card"><div class="bp-body">
      ${radio('shape', ['soft', 'rounded', 'sharp'], shape, 'appearance')}
    </div></details>
    <details class="bp-card"><div class="bp-body">
      ${radio('button_weight', ['auto', '500', '600', '700'], weight)}
      ${radio('button_radius', ['auto', 'pill', 'rounded', 'square'], radius)}
      <button type="button" data-bp-reset="buttons">Reset button overrides</button>
    </div></details>
    <details class="bp-card"><div class="bp-body">
      ${radio('heading_leading', ['auto', 'tight', 'normal', 'relaxed'], 'auto')}
      ${radio('unknown_equivalent', ['auto', '500', '600'], 'auto')}
      ${radio('custom_default', ['auto', '600', '700'], '600')}
      ${radio('without_auto', ['500', '600', '700'], '600')}
    </div></details>
  </div></form>`, { runScripts: 'outside-only', url: 'http://localhost/admin/theme' });
  const w = dom.window, d = w.document;
  const defaults = {
    [name('shape', 'appearance')]: { value: 'soft', label: 'Corners' },
    [name('button_weight')]: { value: 'auto', label: 'Button text weight', equivalent: { value: '600' } },
    [name('button_radius')]: { value: 'auto', label: 'Button corners', equivalent: { field: name('shape', 'appearance'), values: { soft: 'pill', rounded: 'pill' } } },
    [name('heading_leading')]: { value: 'auto', label: 'Heading line height' },
    [name('unknown_equivalent')]: { value: 'auto', label: 'Unknown equivalent', equivalent: { value: '900' } },
    [name('custom_default')]: { value: '700', label: 'Custom default', equivalent: { value: '600' } },
    [name('without_auto')]: { value: 'auto', label: 'Missing inheritance option', equivalent: { value: '600' } },
  };
  d.querySelector('[data-bp]').setAttribute('data-bp-defaults', JSON.stringify(defaults));
  const form = d.querySelector('form');
  let refreshes = 0;
  const changedNames = [];
  form.addEventListener('preview:refresh', () => { refreshes++; });
  form.addEventListener('input', event => { changedNames.push(event.target.name); });
  if (enhance) { w.eval(script); }
  const row = key => d.querySelector(`[data-field="${key}"]`);
  const choice = (key, option) => row(key).querySelector(`input[type="radio"][value="${option}"]`);
  const canonical = key => row(key).querySelector(`input[name="${name(key)}"]`);
  const data = () => new w.FormData(form);
  const source = key => row(key).querySelector('.bp-value');
  const change = (key, option) => {
    const field = choice(key, option);
    field.checked = true;
    field.dispatchEvent(new w.Event('change', { bubbles: true }));
  };
  const reset = key => source(key).querySelector('button').click();
  return { w, d, row, choice, canonical, data, source, change, reset, changedNames, refreshes: () => refreshes };
}

let t = boot({ enhance: false });
check('without JavaScript the original Theme choices submit inherited values', t.data().get(name('button_weight')) === 'auto' && t.data().get(name('button_radius')) === 'auto' && t.choice('button_weight', 'auto').checked && !t.choice('button_weight', 'auto').closest('label').hidden);
t.choice('button_weight', '700').click();
check('without JavaScript concrete choices still submit normally', t.data().get(name('button_weight')) === '700');
t.w.close();

t = boot();
check('a known theme value selects its equivalent and hides only the redundant Theme choice', t.choice('button_weight', '600').checked && t.choice('button_weight', 'auto').closest('label').hidden && !t.choice('button_weight', '600').closest('label').hidden);
check('the displayed default still submits exactly one canonical auto value', t.canonical('button_weight').type === 'hidden' && t.data().getAll(name('button_weight')).length === 1 && t.data().get(name('button_weight')) === 'auto' && t.choice('button_weight', '600').name === 'bp_choice_' + name('button_weight'));
check('opening the form does not turn inherited button values into overrides', t.data().get(name('button_radius')) === 'auto' && t.choice('button_radius', 'pill').checked && t.source('button_weight').getAttribute('data-bp-value') === 'theme' && t.source('button_weight').querySelector('button').hidden && t.changedNames.length === 0);
check('genuinely varied defaults retain the normal Theme option', t.canonical('heading_leading').type === 'radio' && t.choice('heading_leading', 'auto').checked && !t.choice('heading_leading', 'auto').closest('label').hidden);
check('unknown equivalents and missing auto options leave the original controls usable', t.canonical('unknown_equivalent').type === 'radio' && t.data().get(name('unknown_equivalent')) === 'auto' && t.canonical('without_auto').type === 'radio' && t.data().get(name('without_auto')) === '600');
check('a custom theme with a non-auto schema default is not enhanced', t.canonical('custom_default').type === 'radio' && t.data().get(name('custom_default')) === '600');
t.reset('custom_default');
check('custom schema defaults still govern ordinary field resets', t.data().get(name('custom_default')) === '700' && t.source('custom_default').getAttribute('data-bp-value') === 'theme');

t.choice('button_weight', '700').click();
check('choosing a concrete value saves the override and exposes its reset', t.data().get(name('button_weight')) === '700' && t.source('button_weight').getAttribute('data-bp-value') === 'custom' && t.source('button_weight').textContent.includes('Your value') && !t.source('button_weight').querySelector('button').hidden);
check('edits notify preview listeners through the canonical field', t.changedNames.includes(name('button_weight')));
t.reset('button_weight');
check('field reset restores inheritance and the resolved concrete selection', t.data().get(name('button_weight')) === 'auto' && t.choice('button_weight', '600').checked && t.source('button_weight').getAttribute('data-bp-value') === 'theme');
t.choice('button_weight', '600').click();
check('clicking the already selected default explicitly makes it Your value', t.data().get(name('button_weight')) === '600' && t.source('button_weight').getAttribute('data-bp-value') === 'custom');
t.change('button_weight', '500');
check('keyboard-style radio changes also store the chosen value', t.data().get(name('button_weight')) === '500' && t.choice('button_weight', '500').checked && t.source('button_weight').getAttribute('data-bp-value') === 'custom');

t.change('shape', 'rounded');
check('changing the shape updates inherited button corners without saving an override', t.data().get(name('button_radius')) === 'auto' && t.choice('button_radius', 'pill').checked && t.source('button_radius').getAttribute('data-bp-value') === 'theme');
t.change('shape', 'sharp');
check('an unmapped sharp shape shows Theme instead of implying square corners', t.data().get(name('button_radius')) === 'auto' && t.choice('button_radius', 'auto').checked && !t.choice('button_radius', 'auto').closest('label').hidden && !t.choice('button_radius', 'square').checked);
t.change('shape', 'soft');
check('returning to a mapped shape restores the equivalent selection', t.data().get(name('button_radius')) === 'auto' && t.choice('button_radius', 'pill').checked && t.choice('button_radius', 'auto').closest('label').hidden);
t.choice('button_radius', 'square').click();
t.change('shape', 'rounded');
check('shape changes preserve explicitly chosen button corners', t.data().get(name('button_radius')) === 'square' && t.choice('button_radius', 'square').checked && t.source('button_radius').getAttribute('data-bp-value') === 'custom');
t.reset('button_radius');
check('resetting explicit corners follows the current shape', t.data().get(name('button_radius')) === 'auto' && t.choice('button_radius', 'pill').checked && t.source('button_radius').getAttribute('data-bp-value') === 'theme');
t.choice('button_radius', 'rounded').click();
const refreshes = t.refreshes();
t.d.querySelector('[data-bp-reset="buttons"]').click();
check('group reset restores both inherited choices, preserves shape, and refreshes once', t.data().get(name('button_weight')) === 'auto' && t.data().get(name('button_radius')) === 'auto' && t.choice('button_weight', '600').checked && t.choice('button_radius', 'pill').checked && t.data().get(name('shape', 'appearance')) === 'rounded' && t.refreshes() === refreshes + 1);
t.w.close();

t = boot({ weight: '600', radius: 'rounded', shape: 'sharp' });
check('saved explicit values remain explicit even when they equal a theme equivalent', t.data().get(name('button_weight')) === '600' && t.choice('button_weight', '600').checked && t.source('button_weight').getAttribute('data-bp-value') === 'custom');
check('an existing explicit radius survives an unsupported inherited mapping', t.data().get(name('button_radius')) === 'rounded' && t.choice('button_radius', 'rounded').checked && t.source('button_radius').getAttribute('data-bp-value') === 'custom');
t.reset('button_radius');
check('reset on sharp restores the real Theme fallback', t.data().get(name('button_radius')) === 'auto' && t.choice('button_radius', 'auto').checked && !t.choice('button_radius', 'auto').closest('label').hidden);
t.change('shape', 'soft');
check('inherited fallback follows a later shape change while unrelated overrides persist', t.data().get(name('button_radius')) === 'auto' && t.choice('button_radius', 'pill').checked && t.data().get(name('button_weight')) === '600');
t.w.close();

console.log(failed ? 'FAILED: ' + failed : 'ALL PASSED');
process.exitCode = failed ? 1 : 0;
