--TEST--
King native extension libtool selects macOS and Linux linker naming flags by host
--FILE--
<?php
$root = dirname(__DIR__, 2);
$libtoolPath = $root . '/extension/libtool';
$libtool = (string) file_get_contents($libtoolPath);
$configurePath = $root . '/extension/configure';
$configure = is_file($configurePath) ? (string) file_get_contents($configurePath) : '';
$libtoolM4Path = $root . '/extension/build/libtool.m4';
$libtoolM4 = (string) file_get_contents($libtoolM4Path);
$buildProfilePath = $root . '/infra/scripts/build-profile.sh';
$buildProfile = (string) file_get_contents($buildProfilePath);

var_dump(str_contains($libtool, 'uname -s'));
var_dump(str_contains($libtool, 'version_type=darwin'));
var_dump(str_contains($libtool, '-dynamiclib'));
var_dump(str_contains($libtool, '-install_name'));
var_dump(str_contains($libtool, '-bundle'));
var_dump(str_contains($libtool, '-undefined dynamic_lookup'));
var_dump(str_contains($libtool, '\$wl-soname \$wl\$soname'));

$output = [];
$status = 1;
exec('/bin/sh -n ' . escapeshellarg($libtoolPath) . ' 2>&1', $output, $status);
if ($status !== 0) {
    echo implode("\n", $output), "\n";
}
var_dump($status === 0);

$selector = '';
if (preg_match('/case "`uname -s.*?^esac/ms', $libtool, $match)) {
    $selector = $match[0];
}

var_dump($selector !== '');
var_dump(str_contains($selector, 'Darwin)'));
var_dump(!str_contains($selector, '-soname'));
var_dump(str_contains($selector, '-install_name'));
var_dump(str_contains($selector, '-undefined dynamic_lookup'));
var_dump(str_contains($selector, 'no_undefined_flag="-undefined dynamic_lookup"'));

$configureDarwin = '';
if (preg_match('/darwin\* \| rhapsody\*\).*?archive_cmds_need_lc=no/ms', $configure, $match)) {
    $configureDarwin = $match[0];
}

var_dump($configure === '' || $configureDarwin !== '');
var_dump($configure === '' || str_contains($configureDarwin, '${wl}-undefined ${wl}dynamic_lookup'));
var_dump($configure === '' || !str_contains($configureDarwin, '-undefined ${wl}suppress'));
var_dump($configure === '' || !str_contains($configureDarwin, '-flat_namespace'));
var_dump($configure === '' || str_contains($configure, "_lt_dar_single_mod=''"));

$templateDarwin = '';
if (preg_match('/case \$host_os in\s+rhapsody\*.*?^  esac/ms', $libtoolM4, $match)) {
    $templateDarwin = $match[0];
}

var_dump($templateDarwin !== '');
var_dump(str_contains($templateDarwin, '$wl-undefined ${wl}dynamic_lookup'));
var_dump(!str_contains($templateDarwin, 'suppress'));
var_dump(!str_contains($templateDarwin, 'flat_namespace'));
var_dump(str_contains($libtoolM4, "_lt_dar_single_mod=''"));

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
