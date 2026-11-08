--TEST--
King LSQUIC bootstrap stays pinned and fail-closed
--FILE--
<?php
$script = dirname(__DIR__, 2) . '/infra/scripts/check-lsquic-bootstrap.sh';
$output = [];
$status = 1;

exec('bash ' . escapeshellarg($script) . ' --print-source-plan 2>&1', $output, $status);
$plan = implode("\n", $output);

var_dump($status === 0);
var_dump(str_contains($plan, "component\tarchive_url\tsha256\tbytes"));
var_dump(str_contains($plan, "lsquic\thttps://github.com/litespeedtech/lsquic/archive/refs/tags/v4.9.0.tar.gz"));
var_dump(str_contains($plan, "boringssl\thttps://github.com/google/boringssl/archive/refs/tags/0.20260413.0.tar.gz"));
var_dump(str_contains($plan, "ls-qpack\thttps://github.com/litespeedtech/ls-qpack/archive/475dfa8064fc6ef80035541405b22965cfbd7244.tar.gz"));
var_dump(str_contains($plan, "ls-hpack\thttps://github.com/litespeedtech/ls-hpack/archive/cf0f70dd10b352194c97448eb5d00b4aa484f531.tar.gz"));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
