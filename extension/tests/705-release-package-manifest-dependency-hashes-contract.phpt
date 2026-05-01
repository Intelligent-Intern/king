--TEST--
King release package manifests contain LSQUIC dependency hashes and no Quiche manifests
--FILE--
<?php
$root = dirname(__DIR__, 2);
$script = $root . '/infra/scripts/check-release-package-manifest-contract.rb';

if (!is_file($script)) {
    throw new RuntimeException('missing release package manifest checker');
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
