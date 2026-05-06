--TEST--
King CI system dependency installer avoids transient setup-php apt sources and logs real retry status
--FILE--
<?php
$root = dirname(__DIR__, 2);
$scriptPath = $root . '/infra/scripts/install-ci-system-dependencies.sh';
$source = (string) file_get_contents($scriptPath);

var_dump(str_contains($source, 'disable_setup_php_apt_sources()'));
var_dump(str_contains($source, '*ondrej*php*'));
var_dump(str_contains($source, '*setup-php*'));
var_dump(str_contains($source, 'setup-php apt sources disabled before dependency refresh'));
var_dump(strpos($source, 'disable_setup_php_apt_sources') < strpos($source, 'run_with_retry "apt-get update"'));
var_dump(str_contains($source, 'timeout --kill-after="${APT_KILL_AFTER}" "${APT_TIMEOUT}" "$@"'));
var_dump(str_contains($source, 'status=$?'));
var_dump(strpos($source, 'status=$?') < strpos($source, 'if [[ "${status}" -eq 0 ]]; then'));
var_dump(!str_contains($source, "if timeout --kill-after"));
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
