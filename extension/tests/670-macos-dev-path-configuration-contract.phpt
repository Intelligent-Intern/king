--TEST--
King macOS/dev dependency paths stay documented and portable
--SKIPIF--
<?php
$ruby = trim((string) shell_exec('command -v ruby 2>/dev/null'));
if ($ruby === '') {
    die('skip ruby not available');
}
?>
--FILE--
<?php
$root = dirname(__DIR__, 2);
$script = $root . '/infra/scripts/check-dev-path-configuration.rb';
$output = [];
$status = 1;

exec('ruby ' . escapeshellarg($script) . ' 2>&1', $output, $status);
if ($status !== 0) {
    echo implode("\n", $output), "\n";
}

$readme = (string) file_get_contents($root . '/README.md');
$operations = (string) file_get_contents($root . '/documentation/operations-and-release.md');
$operationsNormalized = preg_replace('/\s+/', ' ', $operations);
$config = (string) file_get_contents($root . '/extension/config.m4');

var_dump($status === 0);
var_dump(str_contains($readme, 'macOS / Dev Dependency Paths'));
var_dump(str_contains($operationsNormalized, 'Homebrew/Cellar paths must stay local'));
var_dump(str_contains($config, 'KING_LSQUIC_CFLAGS'));
var_dump(str_contains($config, 'liblsquic'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
