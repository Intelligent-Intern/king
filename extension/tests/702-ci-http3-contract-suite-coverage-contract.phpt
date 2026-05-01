--TEST--
King CI covers HTTP/3 client and server contract suites in canonical PHPT shards
--FILE--
<?php
$root = dirname(__DIR__, 2);
$script = $root . '/infra/scripts/check-ci-http3-contract-suites.rb';

if (!is_file($script)) {
    throw new RuntimeException('missing CI HTTP/3 contract-suite checker');
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
