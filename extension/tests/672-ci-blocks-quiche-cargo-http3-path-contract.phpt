--TEST--
King CI blocks Quiche and Cargo bootstrap from the active HTTP/3 product path
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
$ciGuard = $root . '/infra/scripts/check-ci-linux-reproducible-builds.rb';
$http3Guard = $root . '/infra/scripts/check-http3-product-build-path.rb';
$staticChecks = (string) file_get_contents($root . '/infra/scripts/static-checks.sh');
$ciGuardSource = (string) file_get_contents($ciGuard);

var_dump(str_contains($ciGuardSource, 'bootstrap-quiche.sh'));
var_dump(str_contains($ciGuardSource, 'check-quiche-bootstrap.sh'));
var_dump(str_contains($ciGuardSource, 'ensure-quiche-toolchain.sh'));
var_dump(str_contains($ciGuardSource, 'quiche-bootstrap.lock'));
var_dump(str_contains($ciGuardSource, 'quiche-workspace.Cargo.lock'));
var_dump(str_contains($ciGuardSource, 'cargo build'));
var_dump(str_contains($ciGuardSource, 'libquiche.so'));
var_dump(str_contains($ciGuardSource, 'quiche-server'));
var_dump(str_contains($ciGuardSource, '../infra/scripts/static-checks.sh'));
var_dump(str_contains($staticChecks, 'ruby infra/scripts/check-http3-product-build-path.rb'));

$output = [];
$status = 1;
exec('ruby ' . escapeshellarg($ciGuard) . ' 2>&1', $output, $status);
if ($status !== 0) {
    echo implode("\n", $output), "\n";
}
var_dump($status === 0);

$output = [];
$status = 1;
exec('ruby ' . escapeshellarg($http3Guard) . ' 2>&1', $output, $status);
if ($status !== 0) {
    echo implode("\n", $output), "\n";
}
var_dump($status === 0);
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
