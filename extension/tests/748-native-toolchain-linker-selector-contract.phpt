--TEST--
King generated libtool uses host linker flags and retains portable build hooks
--SKIPIF--
<?php
if (!in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true)) {
    die('skip linker configuration coverage requires macOS or Linux');
}
?>
--FILE--
<?php
$root = dirname(__DIR__, 2);
$libtoolPath = $root . '/extension/libtool';
$buildProfile = (string) file_get_contents($root . '/infra/scripts/build-profile.sh');

// phpize/configure generate libtool for the current host. Check its actual
// linker configuration instead of expecting a committed cross-platform copy.
$output = [];
$status = 1;
exec('/bin/sh -n ' . escapeshellarg($libtoolPath) . ' 2>&1', $output, $status);
var_dump($status === 0);

$output = [];
exec('/bin/sh ' . escapeshellarg($libtoolPath) . ' --config 2>&1', $output, $status);
var_dump($status === 0);
$configuration = implode("\n", $output);

if (PHP_OS_FAMILY === 'Darwin') {
    var_dump(str_contains($configuration, 'version_type=darwin'));
    var_dump(str_contains($configuration, '-install_name'));
    var_dump(str_contains($configuration, 'dynamic_lookup'));
    var_dump(!str_contains($configuration, '-flat_namespace'));
    var_dump(!preg_match('/-undefined\s+(?:\\\$\{wl\})?suppress/', $configuration));
} else {
    var_dump(str_contains($configuration, 'version_type=linux'));
    var_dump(str_contains($configuration, '-soname'));
    var_dump(str_contains($configuration, '-shared'));
    var_dump(!str_contains($configuration, '-install_name'));
    var_dump(!str_contains($configuration, '-dynamiclib'));
}

var_dump(str_contains($buildProfile, 'lt_cv_apple_cc_single_mod=no'));
var_dump(str_contains($buildProfile, 'lt_cv_sys_max_cmd_len=196608'));
var_dump(str_contains($buildProfile, 'patch_generated_libtool_for_host'));
var_dump(str_contains($buildProfile, 'dynamic_lookup'));
var_dump(str_contains($buildProfile, 'allow_undefined_flag'));
var_dump(str_contains($buildProfile, 'suppress'));
var_dump(str_contains($buildProfile, 'CYGWIN*|MINGW*|MSYS*)'));
var_dump(str_contains($buildProfile, 'printf \'%s\\n\' "windows"'));
var_dump(str_contains($buildProfile, 'lsquic.dll'));
var_dump(str_contains($buildProfile, 'linux|windows)'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
