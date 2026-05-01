--TEST--
King HTTP/3 peer replacement preserves the required regression behavior matrix
--FILE--
<?php
$root = dirname(__DIR__, 2);
$testsDir = $root . '/extension/tests';
$matrix = require $testsDir . '/http3_behavior_preservation_matrix.inc';
$strategy = require $testsDir . '/http3_peer_replacement_strategy.inc';

function king_http3_behavior_fail(string $message): void
{
    echo "FAIL: {$message}\n";
    exit(1);
}

$requiredByIssue = [
    'handshake_failure',
    'transport_close',
    'timeout',
    'flow_control',
    'packet_loss',
    'zero_rtt',
    'session_tickets',
    'multi_stream_fairness',
];

if (($matrix['issue'] ?? null) !== '#Q-8') {
    king_http3_behavior_fail('behavior matrix is not bound to #Q-8');
}

$behaviors = $matrix['behaviors'] ?? null;
if (!is_array($behaviors)) {
    king_http3_behavior_fail('behavior matrix missing behaviors');
}

$actualBehaviors = array_keys($behaviors);
sort($actualBehaviors);
$expectedBehaviors = $requiredByIssue;
sort($expectedBehaviors);
if ($actualBehaviors !== $expectedBehaviors) {
    king_http3_behavior_fail(
        "behavior matrix drifted from the issue checklist\n" .
        'expected=' . json_encode($expectedBehaviors, JSON_UNESCAPED_SLASHES) . "\n" .
        'actual=' . json_encode($actualBehaviors, JSON_UNESCAPED_SLASHES)
    );
}

$strategyCapabilities = array_fill_keys($strategy['required_capabilities'] ?? [], true);
$activeHelpers = $strategy['active_helpers'] ?? null;
if (!is_array($activeHelpers) || $activeHelpers === []) {
    king_http3_behavior_fail('replacement strategy has no active helper inventory');
}

$replacementCapabilities = [];
foreach ($activeHelpers as $helper) {
    foreach (($helper['capabilities'] ?? []) as $capability) {
        $replacementCapabilities[$capability] = true;
    }
}

$uniqueTests = [];
foreach ($requiredByIssue as $behavior) {
    $entry = $behaviors[$behavior] ?? null;
    if (!is_array($entry)) {
        king_http3_behavior_fail("{$behavior} behavior entry missing");
    }

    $capability = $entry['strategy_capability'] ?? null;
    if (!is_string($capability) || $capability === '') {
        king_http3_behavior_fail("{$behavior} has no strategy capability");
    }
    if (!isset($strategyCapabilities[$capability])) {
        king_http3_behavior_fail("{$behavior} capability is not required by the replacement strategy");
    }
    if (!isset($replacementCapabilities[$capability])) {
        king_http3_behavior_fail("{$behavior} capability is not covered by any replacement helper target");
    }

    $tests = $entry['tests'] ?? null;
    if (!is_array($tests) || $tests === []) {
        king_http3_behavior_fail("{$behavior} has no preserved PHPT tests");
    }

    foreach ($tests as $test) {
        if (!is_array($test)) {
            king_http3_behavior_fail("{$behavior} has malformed test entry");
        }

        $path = $test['path'] ?? null;
        if (!is_string($path) || !str_starts_with($path, 'extension/tests/') || !str_ends_with($path, '.phpt')) {
            king_http3_behavior_fail("{$behavior} has invalid test path");
        }

        $fullPath = $root . '/' . $path;
        if (!is_file($fullPath)) {
            king_http3_behavior_fail("{$behavior} references missing test {$path}");
        }

        $source = file_get_contents($fullPath);
        if (!is_string($source) || $source === '') {
            king_http3_behavior_fail("{$behavior} could not read {$path}");
        }

        foreach (($test['needles'] ?? []) as $needle) {
            if (!is_string($needle) || $needle === '') {
                king_http3_behavior_fail("{$behavior} has invalid assertion needle for {$path}");
            }
            if (!str_contains($source, $needle)) {
                king_http3_behavior_fail("{$behavior} test {$path} is missing assertion needle: {$needle}");
            }
        }

        $uniqueTests[$path] = true;
    }
}

if (count($uniqueTests) < 12) {
    king_http3_behavior_fail('behavior preservation matrix collapsed below the expected regression breadth');
}

echo 'HTTP/3 preserved behavior tests: ' . count($requiredByIssue) . ' behaviors across ' . count($uniqueTests) . " PHPTs.\n";
?>
--EXPECT--
HTTP/3 preserved behavior tests: 8 behaviors across 16 PHPTs.
