<?php
/*
 * Checks which role may use which admin action. Run it after adding or changing an admin action:
 *
 *   php scripts/check-permissions.php
 *
 * Add the new action to the table below with who should reach it: [superadmin, admin, editor, user].
 * An action that is not mapped in PermissionService is administrator-only, which is what a new
 * action should start as.
 */
require __DIR__ . '/../vendor/autoload.php';
use FarosCMS\PermissionService;
$p = new PermissionService();
$fail = 0;
function check(string $label, $actual, $expected) { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . var_export($actual, true) . ' expected ' . var_export($expected, true)) . "\n"; }
$u = fn(string $role, int $id = 5) => ['id' => $id, 'role' => $role, 'status' => 'active'];

// Every admin action the router knows, and who may use it: [superadmin, admin, editor, user]
$actions = [
    'dashboard' => [1,1,1,0], 'index' => [1,1,1,0],
    'content' => [1,1,1,0], 'content-bulk' => [1,1,1,0], 'search' => [1,1,1,0], 'edit' => [1,1,1,0], 'save' => [1,1,1,0], 'delete' => [1,1,1,0], 'new' => [1,1,1,0], 'block-presets' => [1,1,1,0],
    'media' => [1,1,1,0], 'files' => [1,1,1,0], 'taxonomies' => [1,1,1,0],
    'menus' => [1,1,0,0], 'menus-new' => [1,1,0,0], 'menus-edit' => [1,1,0,0],
    'forms' => [1,1,0,0], 'form-submissions' => [1,1,0,0], 'forms-export' => [1,1,0,0], 'export' => [1,1,0,0], 'import' => [1,1,0,0],
    'settings' => [1,1,0,0], 'system' => [1,1,0,0], 'content-types' => [1,1,0,0], 'translations' => [1,1,0,0],
    'activity-logs' => [1,1,0,0], 'email-logs' => [1,1,0,0], 'backups' => [1,1,0,0], 'updates' => [1,1,0,0],
    'notification-read' => [1,1,0,0], 'notifications-read-all' => [1,1,0,0],
    'users' => [1,0,0,0], 'users-delete' => [1,0,0,0],
    'users-edit' => [1,1,1,1],
    'some-future-action' => [1,1,0,0],
];
foreach ($actions as $action => $expected) {
    foreach (['superadmin', 'admin', 'editor', 'user'] as $i => $role) {
        check("$role / $action", $p->canAccessAction($u($role), $action), (bool)$expected[$i]);
    }
}
check('anonymous denied', $p->canAccessAction(null, 'dashboard'), false);
check('inactive editor denied', $p->canAccessAction(['id' => 1, 'role' => 'editor', 'status' => 'inactive'], 'content'), false);
check('unknown role has nothing', $p->capabilitiesForRole('wizard'), []);
check('raw_html: admin roles only', [$p->can($u('superadmin'), 'content.raw_html'), $p->can($u('admin'), 'content.raw_html'), $p->can($u('editor'), 'content.raw_html')], [true, true, false]);
check('forms.manage not for editor', $p->can($u('editor'), 'forms.manage'), false);
check('editor edits only self', [$p->canEditUser($u('editor', 5), 5), $p->canEditUser($u('editor', 5), 6), $p->canCreateUsers($u('editor'))], [true, false, false]);
check('editor cannot change own role', $p->canChangeAccessForUser($u('editor', 5), 5), false);
check('admin cannot manage users', $p->canCreateUsers($u('admin')), false);
check('role list', array_keys(PermissionService::roles()), ['superadmin', 'admin', 'editor', 'user']);
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
