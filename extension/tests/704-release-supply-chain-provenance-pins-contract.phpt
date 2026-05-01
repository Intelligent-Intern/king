--TEST--
King release supply-chain verification checks new LSQUIC/BoringSSL provenance pins
--FILE--
<?php
$root = dirname(__DIR__, 2);
$script = $root . '/infra/scripts/check-release-supply-chain-provenance.rb';

if (!is_file($script)) {
    throw new RuntimeException('missing release supply-chain provenance checker');
}

$output = [];
$status = 1;
exec('ruby ' . escapeshellarg($script) . ' 2>&1', $output, $status);
if ($status !== 0) {
    echo implode("\n", $output), "\n";
}

var_dump($status === 0);
?>
--EXPECT--
bool(true)
