--TEST--
King CI blocks local paths, Homebrew paths, Cargo HTTP/3 bootstrap, and Quiche locks
--FILE--
<?php
$root = dirname(__DIR__, 2);
$script = $root . '/infra/scripts/check-ci-migration-guardrails.rb';

if (!is_file($script)) {
    throw new RuntimeException('missing CI migration guardrail checker');
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
