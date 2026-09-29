from client import *
def matrix_form(f): return ['action', 'save'] in f['fields'] and any(x[0].startswith('caps[') for x in f['fields'])
def code(c, path): return c.get(path)[0]

def set_caps(root, role_caps, drop=()):
    """Submit the roles form with extra capabilities on, and named ones off (as unticking a box would)."""
    fields = [list(x) for x in next(f for f in root.forms('/admin/roles') if matrix_form(f))['fields']]
    keep = [f for f in fields if f[0] not in drop]
    extra = [[f'caps[{role}][{cap}]', '1'] for role, caps in role_caps.items() for cap in caps]
    return root.request('/admin/roles', data=[tuple(x) for x in keep + extra])

