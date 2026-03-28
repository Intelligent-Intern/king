--TEST--
King release config-state compatibility matrix stays wired into scripts and CI
--FILE--
<?php
$extensionDir = dirname(__DIR__);
$rootDir = dirname($extensionDir);
$script = $rootDir . '/infra/scripts/check-config-compatibility-matrix.sh';
$fixture = $rootDir . '/infra/scripts/runtime-config-compatibility.php';
$ciWorkflow = $rootDir . '/.github/workflows/ci.yml';

$output = [];
$status = 1;
exec('bash ' . escapeshellarg($script) . ' --help 2>&1', $output, $status);
$help = implode("\n", $output);

var_dump($status === 0);
var_dump(str_contains($help, 'Usage: ./infra/scripts/check-config-compatibility-matrix.sh [--archive PATH] [--php-bin BIN] [--artifacts-dir DIR]'));
var_dump(str_contains($help, 'legacy flat userland override aliases'));
var_dump(str_contains($help, 'current namespaced userland overrides'));
var_dump(str_contains($help, 'system INI snapshot inheritance'));

$fixtureSource = (string) file_get_contents($fixture);
var_dump(str_contains($fixtureSource, '$mode === \'legacy_alias_overrides\''));
var_dump(str_contains($fixtureSource, '$mode === \'namespaced_overrides\''));
var_dump(str_contains($fixtureSource, '$mode === \'ini_snapshot\''));
var_dump(str_contains($fixtureSource, '\'quic.cc_algorithm\' => \'bbr\''));
var_dump(str_contains($fixtureSource, '\'dns_mode\' => \'service_discovery\''));

$ci = (string) file_get_contents($ciWorkflow);
var_dump(str_contains($ci, 'release-config-state-compatibility:'));
var_dump(str_contains($ci, 'name: Release Config-State Compatibility'));
var_dump(str_contains($ci, '../infra/scripts/check-config-compatibility-matrix.sh'));
var_dump(str_contains($ci, 'compat-artifacts/release-config-state/'));
var_dump(str_contains($ci, 'king-release-config-state-failures'));
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
