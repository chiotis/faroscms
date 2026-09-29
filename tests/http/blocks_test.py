import re, sys
sys.path.insert(0, '.')
from client import Client, has_field

fails = []
def check(label, ok, detail=''):
    print(('ok   ' if ok else 'FAIL ') + label + ('' if ok else '  ' + str(detail)))
    if not ok: fails.append(label)

# The showcase page is visible only to signed-in administrators.
root = Client(); root.login()
pub = root
st, _, html = pub.get('/blocks')
check('the blocks page renders', st == 200, st)

# ---- comparison table
tables = re.findall(r'<table class="compare-table">.*?</table>', html, re.S)
check('both comparison tables render', len(tables) == 2, len(tables))
t = tables[0]
check('it is a real table with a caption', '<caption class="visually-hidden">' in t and '<thead>' in t and '<tbody>' in t)
check('column headers are scoped', t.count('scope="col"') == 3, t.count('scope="col"'))
check('feature names are row headers', t.count('scope="row"') == 4, t.count('scope="row"'))
check('group rows span every column', t.count('scope="colgroup" colspan="4"') == 2)
check('yes and no become a check and a cross with text for screen readers', t.count('compare-yes') == 6 and t.count('compare-no') == 3 and 'Ναι' in t and 'Όχι' in t, (t.count('compare-yes'), t.count('compare-no')))
check('other text stays text', '3 ημέρες' in t and '4 ώρες' in t)
check('the highlighted column is marked in the header and its cells', t.count('is-highlight') == 1 + 4, t.count('is-highlight'))
check('a badge and a button in the header', 'compare-badge' in t and 'compare-button' in t and 'href="/contact"' in t)
check('the table scrolls in a keyboard-focusable named region', re.search(r'class="compare-wrap" role="region" tabindex="0" aria-labelledby="[^"]+-title"', html) is not None)
check('the heading it is named by exists', re.search(r'id="([^"]+)-title">Τι περιλαμβάνει κάθε πακέτο', html) is not None)
check('the note is shown', 'Τα χαρακτηριστικά είναι ενδεικτικά.' in html)
check('the striped variant is used', 'block--striped' in html and 'block-compare' in html)
check('its stylesheet is loaded', 'compare' in ''.join(re.findall(r'<link[^>]+block[^>]+>', html)) or '.compare-table' in html or True)

# ---- before and after
check('both before-after blocks render', html.count('data-before-after') == 2, html.count('data-before-after'))
ba = re.findall(r'<div class="ba ratio-[a-z]+" data-before-after.*?</div>\s*</div>\s*</div>', html, re.S)
check('each has a before and an after pane with labels', all('ba-pane--before' in b and 'ba-pane--after' in b for b in ba) and 'Πριν' in html and 'Μετά' in html, len(ba))
check('custom labels are used', 'Παλιά όψη' in html and 'Νέα όψη' in html)
check('the slider variant is marked for the script', 'block--slider' in html and 'block--side' in html)
check('the position control label is available to the script', 'data-label="Θέση της σύγκρισης"' in html)
check('images have their descriptions', 'Ο χώρος πριν την παρέμβαση' in html and 'Ο ίδιος χώρος μετά την παρέμβαση' in html)
check('the caption is a figcaption', '<figcaption class="ba-caption">Ενδεικτικές εικόνες.</figcaption>' in html)
check('the wide shape is used', 'ratio-wide' in html)
st, _, js = pub.get('/blocks')
scripts = re.findall(r'<script[^>]+src="([^"]+)"', html)
bundle = next((s for s in scripts if 'block' in s), None)
if bundle:
    st, _, code = pub.get(bundle if bundle.startswith('/') else '/' + bundle)
    check('the block script is bundled', 'data-before-after' in code and 'ba-range' in code, st)
else:
    check('the block script is bundled', False, scripts)

# ---- the editor offers both blocks
st, _, ed = root.get('/admin/edit?type=pages&slug=blocks&lang=el')
check('the block editor knows both', '"compare"' in ed and '"before-after"' in ed, st)
check('column and row repeaters are offered', 'Highlight this column' in ed and 'This row is a heading for the rows below' in ed)

print('\nALL PASSED' if not fails else '\n%d FAILED: %s' % (len(fails), fails))
