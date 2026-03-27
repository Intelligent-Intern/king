--TEST--
King QUIC bootstrap stays pinned and fail-closed
--FILE--
<?php
$script = __DIR__ . '/../scripts/check-quiche-bootstrap.sh';
$output = [];
$status = 1;

exec('bash ' . escapeshellarg($script) . ' 2>&1', $output, $status);

var_dump($status === 0);
echo implode("\n", $output), "\n";
?>
--EXPECT--
bool(true)
Pinned quiche commit: b30f9e76c32332aa35377dcb00f556626d47a841
Pinned boringssl commit: f1c75347daa2ea81a941e953f2263e0a4d970c8d
Pinned wirefilter commit: 6621924baf36f8ba7f603433dbe6f857ad3d5589
Bootstrap contract: deterministic-pinned
