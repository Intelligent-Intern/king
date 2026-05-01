--TEST--
King client HTTP/3 LSQUIC loader guard rejects placeholder success paths
--FILE--
<?php
$root = dirname(__DIR__, 2);
$guard = $root . '/infra/scripts/check-http3-lsquic-loader-contract.php';
$staticChecks = (string) file_get_contents($root . '/infra/scripts/static-checks.sh');
$output = [];
$status = 1;

exec(PHP_BINARY . ' -n ' . escapeshellarg($guard) . ' 2>&1', $output, $status);

var_dump(is_file($guard));
var_dump(str_contains($staticChecks, 'check-http3-lsquic-loader-contract.php'));
var_dump($status === 0);
var_dump(implode("\n", $output));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
string(37) "HTTP/3 LSQUIC loader contract passed."
