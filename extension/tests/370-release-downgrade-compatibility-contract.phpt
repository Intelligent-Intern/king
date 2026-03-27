--TEST--
King release downgrade compatibility gate stays wired into scripts and CI
--FILE--
<?php
$extensionDir = dirname(__DIR__);
$rootDir = dirname($extensionDir);
$script = $extensionDir . '/scripts/check-release-downgrade.sh';
$ciWorkflow = $rootDir . '/.github/workflows/ci.yml';

$output = [];
$status = 1;
exec('bash ' . escapeshellarg($script) . ' --help 2>&1', $output, $status);
$help = implode("\n", $output);

var_dump($status === 0);
var_dump(str_contains($help, 'Usage: ./scripts/check-release-downgrade.sh --from-ref REF'));
var_dump(str_contains($help, '--current-archive PATH'));
var_dump(str_contains($help, '--php-bin BIN'));
var_dump(str_contains($help, '--artifacts-dir DIR'));

$ci = (string) file_get_contents($ciWorkflow);
var_dump(str_contains($ci, 'release-downgrade-compatibility:'));
var_dump(str_contains($ci, 'name: Release Downgrade Compatibility'));
var_dump(str_contains($ci, 'fetch-depth: 0'));
var_dump(str_contains($ci, 'github.event.before'));
var_dump(str_contains($ci, './scripts/check-release-downgrade.sh --from-ref'));
var_dump(str_contains($ci, 'compat-artifacts/release-downgrade/'));
var_dump(str_contains($ci, 'king-release-downgrade-failures'));
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
