--TEST--
King CI builds the HTTP/3 stack through pinned LSQUIC/BoringSSL gates
--FILE--
<?php
$root = dirname(__DIR__, 2);
$script = $root . '/infra/scripts/check-ci-builds-http3-stack.rb';

if (!is_file($script)) {
    throw new RuntimeException('missing CI HTTP/3 stack build checker');
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
