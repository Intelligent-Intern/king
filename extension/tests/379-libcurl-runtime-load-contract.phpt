--TEST--
King builds the extension without a hard libcurl runtime dependency so telemetry exports stay lazy-loaded
--SKIPIF--
<?php
if (PHP_OS_FAMILY !== 'Linux') {
    die('skip Linux-only dependency check');
}

$readelf = trim((string) shell_exec('command -v readelf'));
if ($readelf === '') {
    die('skip readelf not available');
}

$extensionPath = realpath(__DIR__ . '/../modules/king.so');
if ($extensionPath === false || !is_file($extensionPath)) {
    die('skip extension artifact missing');
}
?>
--FILE--
<?php
$readelf = trim((string) shell_exec('command -v readelf'));
$extensionPath = realpath(__DIR__ . '/../modules/king.so');

$command = escapeshellcmd($readelf) . ' -d ' . escapeshellarg($extensionPath) . ' 2>&1';
exec($command, $output, $status);

var_dump($status === 0);

$joined = implode("\n", $output);
var_dump(stripos($joined, 'libcurl') === false);
?>
--EXPECT--
bool(true)
bool(true)
