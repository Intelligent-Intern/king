--TEST--
King sanitizer soak gates stay wired into scripts and CI
--FILE--
<?php
$extensionDir = dirname(__DIR__);
$rootDir = dirname($extensionDir);
$script = $rootDir . '/infra/scripts/soak-runtime.sh';
$ciWorkflow = $rootDir . '/.github/workflows/ci.yml';

$output = [];
$status = 1;
exec('bash ' . escapeshellarg($script) . ' --help 2>&1', $output, $status);
$help = implode("\n", $output);

var_dump($status === 0);
var_dump(str_contains($help, 'Usage: ./infra/scripts/soak-runtime.sh <asan|ubsan|leak>'));
var_dump(str_contains($help, '--iterations N'));
var_dump(str_contains($help, '--artifacts-dir DIR'));

$ci = (string) file_get_contents($ciWorkflow);
var_dump(str_contains($ci, 'sanitizer-soak:'));
var_dump(str_contains($ci, 'name: ${{ matrix.mode }} sanitizer soak'));
var_dump(str_contains($ci, '../infra/scripts/soak-runtime.sh "${{ matrix.mode }}"'));
var_dump(str_contains($ci, 'king-${{ matrix.mode }}-soak-diagnostics'));
var_dump(str_contains($ci, 'soak-artifacts/${{ matrix.mode }}/'));
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
