<?php
/*
 * An install on a host that has switched functions off. Hosts do this (set_time_limit, ignore_user_abort, disk_free_space,
 * curl_exec, opcache_reset are common), and a disabled function is not there to call: calling it is a fatal error that
 * `@` does not catch. The installer's whole test is run again with those functions disabled.
 *   php tests/unit/update-installer-restricted.php
 */
$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$test = __DIR__ . '/update-installer.php';
$disabled = 'set_time_limit,ignore_user_abort,disk_free_space,opcache_reset,curl_exec,curl_init';
$probe = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=' . $disabled . ' -r ' . escapeshellarg('foreach (["set_time_limit","ignore_user_abort","disk_free_space","curl_exec"] as $f) { echo function_exists($f) ? "on " : "off "; }') . ' 2>&1');
check('the functions are really off in the second run', trim($probe), 'off off off off');
$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=' . $disabled . ' ' . escapeshellarg($test) . ' 2>&1');
check('the installer test passes with them off', [str_contains($out, 'ALL PASSED'), preg_match('/^(FAIL|PHP Fatal|Fatal error)/m', $out)], [true, 0]);
if (!str_contains($out, 'ALL PASSED')) { echo preg_replace('/^ok .*\n/m', '', $out); }

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
