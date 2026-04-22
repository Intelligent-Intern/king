--TEST--
King LSQUIC bootstrap replaces deleted Quiche script entrypoints
--FILE--
<?php
$root = dirname(__DIR__, 2);
$lsquic = $root . '/infra/scripts/bootstrap-lsquic.sh';
$deleted = [
    $root . '/infra/scripts/bootstrap-quiche.sh',
    $root . '/infra/scripts/check-quiche-bootstrap.sh',
    $root . '/infra/scripts/ensure-quiche-toolchain.sh',
];
$output = [];
$status = 1;

exec('bash ' . escapeshellarg($lsquic) . ' --verify-lock 2>&1', $output, $status);

var_dump(is_executable($lsquic));
var_dump($status === 0);
foreach ($deleted as $path) {
    var_dump(!file_exists($path));
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
