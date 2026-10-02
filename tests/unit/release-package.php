<?php
/*
 * The package a site installs: only code, never a site's own data, the same bytes every time, and a manifest that
 * matches it.
 *   php tests/unit/release-package.php
 */
$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)) . "\n"; }

$root = dirname(__DIR__, 2);
$out = sys_get_temp_dir() . '/relpkg' . getmypid();
$version = trim((string)file_get_contents($root . '/VERSION'));
$run = function (string $dir, string $extra = '') use ($root): string { return (string)shell_exec('php ' . escapeshellarg($root . '/scripts/build-release.php') . ' ' . escapeshellarg($dir) . ' ' . $extra . ' 2>&1'); };

$said = $run($out . '/a', '--repo=acme/site');
check('it says what it built', str_starts_with($said, "Built faroscms-$version.zip: "), true);
$manifest = json_decode((string)file_get_contents($out . '/a/release.json'), true);
$zipPath = $out . "/a/faroscms-$version.zip";
check('the manifest names the version, the package and where it is', [$manifest['version'], $manifest['package'], $manifest['package_url']], [$version, "faroscms-$version.zip", "https://github.com/acme/site/releases/download/v$version/faroscms-$version.zip"]);
check('and the lowest PHP, from composer.json', $manifest['min_php'], '8.1');
check('and the checksum and size of the package', [$manifest['sha256'], $manifest['size']], [hash_file('sha256', $zipPath), filesize($zipPath)]);
check('a backup is required before installing it', $manifest['requires_backup'], true);

$zip = new ZipArchive();
$zip->open($zipPath);
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = $zip->getNameIndex($i); }
check('the manifest counts its files', $manifest['files'], count($names));
check('it holds the code the site runs', [in_array('src/App.php', $names, true), in_array('public/index.php', $names, true), in_array('vendor/autoload.php', $names, true), in_array('themes/default/theme.yaml', $names, true), in_array('VERSION', $names, true)], [true, true, true, true, true]);
check('and the VERSION inside is the version of the manifest', trim((string)$zip->getFromName('VERSION')), $version);
check('and a demo site to start from', in_array('starter/content/pages/index.md', $names, true) || count(array_filter($names, fn($n) => str_starts_with($n, 'starter/content/'))) > 0, true);
$leaks = array_values(array_filter($names, fn($n) => (bool)preg_match('#^(content|storage|tests|node_modules|\.git|\.github|\.claude|build|docs)/#', $n) || (str_starts_with($n, 'custom/') && $n !== 'custom/README.md') || (str_starts_with($n, 'public/uploads/') && $n !== 'public/uploads/.htaccess')));
check('and nothing of a site: no content, uploads, custom/, storage, tests, or settings', $leaks, []);
check('uploads keep the file that stops code running in them', in_array('public/uploads/.htaccess', $names, true), true);
$bad = array_values(array_filter($names, fn($n) => str_starts_with($n, '/') || str_contains($n, '..') || str_contains($n, '\\') || basename($n) === '.DS_Store'));
check('every name is a safe relative path', $bad, []);
$zip->close();

// A site's own files lying in the folder it is built from never get in.
$work = $out . '/work';
mkdir($work, 0775, true);
exec('cp -R ' . escapeshellarg($root . '/src') . ' ' . escapeshellarg($work . '/src'));
foreach (['admin', 'themes', 'starter', 'public/assets', 'vendor'] as $d) { @mkdir($work . '/' . dirname($d), 0775, true); }
foreach (['VERSION', 'composer.json'] as $f) { copy($root . '/' . $f, $work . '/' . $f); }
mkdir($work . '/scripts', 0775, true);
copy($root . '/scripts/build-release.php', $work . '/scripts/build-release.php');
mkdir($work . '/content/pages', 0775, true);
file_put_contents($work . '/content/pages/secret.md', 'private');
mkdir($work . '/public/uploads/media', 0775, true);
file_put_contents($work . '/public/uploads/media/photo.jpg', 'x');
mkdir($work . '/custom', 0775, true);
file_put_contents($work . '/custom/custom.css', 'x');
mkdir($work . '/storage/db', 0775, true);
file_put_contents($work . '/storage/db/app.sqlite', 'x');
shell_exec('php ' . escapeshellarg($work . '/scripts/build-release.php') . ' ' . escapeshellarg($out . '/b') . ' 2>&1');
$zip->open($out . "/b/faroscms-$version.zip");
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = $zip->getNameIndex($i); }
$zip->close();
check('a folder with a site in it gives a package without the site', array_values(array_filter($names, fn($n) => !str_starts_with($n, 'src/') && $n !== 'VERSION' && $n !== 'composer.json')), []);

// The same version builds to the same bytes.
$run($out . '/c', '--repo=acme/site');
check('the same version builds to the same package', hash_file('sha256', $out . "/c/faroscms-$version.zip"), $manifest['sha256']);

// A version that is not one is refused.
$bogus = $out . '/bogus';
mkdir($bogus . '/scripts', 0775, true);
copy($root . '/scripts/build-release.php', $bogus . '/scripts/build-release.php');
file_put_contents($bogus . '/VERSION', "not-a-version\n");
file_put_contents($bogus . '/composer.json', '{}');
check('a VERSION that is not x.y.z builds nothing', [str_contains((string)shell_exec('php ' . escapeshellarg($bogus . '/scripts/build-release.php') . ' ' . escapeshellarg($bogus . '/out') . ' 2>&1'), 'VERSION must be like'), is_dir($bogus . '/out')], [true, false]);

exec('rm -rf ' . escapeshellarg($out));
echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
